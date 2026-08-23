<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service\Resumable;

use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStartHandlerInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectReservation;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\TaskStartRequest;
use Weline\Framework\Runtime\Resumable\TaskStopRequestedException;
use Weline\MediaManager\Service\AiDrawService;
use Weline\MediaManager\Service\MediaFileAccessContextFactory;
use Weline\MediaManager\Service\AiDrawSessionStore;
use Weline\Storage\Api\Data\StorageDiskCode;

/**
 * Detached AI-draw task handler.
 *
 * A checkpoint here contains only replayable application data.  It never
 * serializes provider clients, image bytes, a PHP Fiber, a Request, or a
 * call stack.  The effect ledger supplies the exact-once boundary around the
 * provider call; an unconfirmed provider call is deliberately recovery-unsafe
 * instead of being sent twice.
 */
final class AiDrawTaskHandler implements ResumableTaskStartHandlerInterface
{
    private const TYPE_CODE = 'media.ai_draw';
    private const BATCH_MAX = 8;
    private const MAX_PROMPT_BYTES = 16_384;
    private const MAX_NEGATIVE_PROMPT_BYTES = 4_096;
    private const MAX_SHORT_VALUE_BYTES = 512;
    private const MAX_CHECKPOINT_GAP_SECONDS = 15;

    public function __construct(
        private readonly AiDrawService $aiDrawService,
        private readonly AiDrawSessionStore $sessionStore,
        private readonly MediaFileAccessContextFactory $fileAccessContexts,
    ) {
    }

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        $adminId = $this->backendAdminId($owner);
        $input = $this->fileAccessContexts->freeze($input, $adminId);
        $requestId = $this->requiredIdentifier($input, 'request_id');
        $mode = $this->mode($input);
        $sessionId = $this->optionalSessionId($input) ?? $this->sessionStore->createSessionId();
        $prompt = $this->optionalText($input, 'prompt', self::MAX_PROMPT_BYTES);
        $prompts = $this->promptList($input['prompts'] ?? []);
        $batchCount = $this->batchCount($input['batch_count'] ?? 1);
        $jobs = $this->buildJobs($mode, $prompt, $prompts, $batchCount);
        if ($jobs === []) {
            throw new \InvalidArgumentException((string)__('请输入提示词'));
        }

        $sourceFileHash = $this->optionalText($input, 'source_file_hash', self::MAX_SHORT_VALUE_BYTES);
        $parentGenerationId = $this->optionalGenerationId($input, 'parent_generation_id');
        if ($mode === 'image2image' && $sourceFileHash === '') {
            throw new \InvalidArgumentException((string)__('图生图必须选择参考图'));
        }
        if ($mode === 'edit_turn' && $parentGenerationId === '') {
            throw new \InvalidArgumentException((string)__('继续修图缺少上一轮生成结果'));
        }

        return new TaskStartRequest(
            input: [
                'owner_admin_id' => $adminId,
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'mode' => $mode,
                'prompt' => $prompt,
                'prompts' => $prompts,
                'batch_count' => $batchCount,
                'target' => $this->optionalText($input, 'target', self::MAX_SHORT_VALUE_BYTES),
                'disk_code' => $this->requiredDiskCode($input),
                'locale_code' => (string)$input['locale_code'],
                MediaFileAccessContextFactory::INPUT_KEY => $input[MediaFileAccessContextFactory::INPUT_KEY],
                'source_file_hash' => $sourceFileHash,
                'parent_generation_id' => $parentGenerationId,
                'size' => $this->size($input),
                'aspect_ratio' => $this->aspectRatio($input),
                'output_format' => $this->outputFormat($input),
                'negative_prompt' => $this->optionalText($input, 'negative_prompt', self::MAX_NEGATIVE_PROMPT_BYTES),
            ],
            businessKey: 'media.ai_draw:' . $owner->principal . ':' . $requestId,
            policy: TaskPolicy::defaults(),
        );
    }

    /** @param array<string,mixed> $input */
    private function requiredDiskCode(array $input): string
    {
        $diskCode = trim((string)($input['disk_code'] ?? $input['storage'] ?? ''));
        StorageDiskCode::parse($diskCode);

        return $diskCode;
    }

    public function execute(
        ResumableTaskContextInterface $context,
        array $input,
        ?TaskCheckpoint $checkpoint,
    ): TaskResult {
        $adminId = $this->executionAdminId($input);
        $sessionId = $this->requiredSessionId($input);
        $mode = $this->mode($input);
        $prompt = $this->optionalText($input, 'prompt', self::MAX_PROMPT_BYTES);
        $jobs = $this->buildJobs(
            $mode,
            $prompt,
            $this->promptList($input['prompts'] ?? []),
            $this->batchCount($input['batch_count'] ?? 1),
        );
        if ($jobs === []) {
            return TaskResult::failed('invalid_input', (string)__('请输入提示词'));
        }

        $this->sessionStore->purgeExpired();
        $this->sessionStore->ensureSession($sessionId, $adminId);
        $taskId = $context->taskId();
        $inputHash = $this->inputHash($input);
        $checkpointState = $checkpoint?->state ?? [];
        if ($checkpoint !== null
            && isset($checkpointState['input_hash'])
            && !\hash_equals((string)$checkpointState['input_hash'], $inputHash)) {
            return TaskResult::failed('checkpoint_input_mismatch', (string)__('任务检查点与已冻结输入不匹配'));
        }

        if ($checkpoint?->cursor === 'completed') {
            return TaskResult::completed($this->terminalPayload(
                $taskId,
                $sessionId,
                $this->completedGenerationIds($checkpointState),
                $context->attempt(),
                true,
            ));
        }

        $jobSpecs = $this->jobSpecs($taskId, $jobs);
        $state = $this->checkpointState($inputHash, $sessionId, $mode, $jobSpecs, $checkpointState);
        $context->saveCheckpoint('prepared', $state);
        if ($checkpoint !== null && $context->attempt() > 1) {
            $context->emit('attempt_reset', [
                'attempt' => $context->attempt(),
                'reason' => 'runner_recovered',
            ]);
        }

        $configStatus = $this->aiDrawService->getConfigStatus();
        $mock = (bool)($configStatus['mock'] ?? false);
        $ready = (bool)($configStatus['ready'] ?? false) || $mock;
        $context->emit('start', [
            'task_id' => $taskId,
            'attempt' => $context->attempt(),
            'mode' => $mode,
            'session_id' => $sessionId,
            'batch_total' => \count($jobSpecs),
            'scenario_code' => 'media_manager_ai_draw',
            'mock' => $mock,
            'ready' => $ready,
            'model' => (string)($configStatus['model'] ?? ''),
            'message' => (string)($configStatus['message'] ?? ''),
        ]);
        if (!$ready) {
            return TaskResult::failed(
                'model_not_ready',
                (string)($configStatus['message'] ?? __('文生图模型未就绪')),
                ['task_id' => $taskId, 'session_id' => $sessionId],
            );
        }

        $completedIds = $this->completedGenerationIds($state);
        foreach ($jobSpecs as $index => $job) {
            $context->throwIfStopRequested();
            $context->heartbeat();
            $batchIndex = $index + 1;
            $generationId = $job['generation_id'];
            $effectKey = 'generation:' . $batchIndex;
            $effect = $context->reserveEffect($effectKey);
            $stored = $this->aiDrawService->loadResumableGenerationPayload($adminId, $sessionId, $generationId);

            if ($stored !== null) {
                if (!$effect->alreadyExisted || $effect->state->value !== 'applied') {
                    $context->completeEffect($effectKey, $this->effectResult($stored));
                }
                $completedIds[$batchIndex] = $generationId;
                $state = $this->checkpointState($inputHash, $sessionId, $mode, $jobSpecs, $state, $completedIds);
                $context->saveCheckpoint('generation_' . $batchIndex . '_stored', $state);
                $context->emit('preview', $stored);
                continue;
            }

            if ($effect->alreadyExisted) {
                return $this->recoveryUnsafe(
                    $context,
                    $effect,
                    $sessionId,
                    $generationId,
                    $batchIndex,
                    (string)__('AI 生成在结果确认前中断，无法安全重试。'),
                );
            }

            $state = $this->checkpointState($inputHash, $sessionId, $mode, $jobSpecs, $state, $completedIds);
            $context->saveCheckpoint('before_generation_' . $batchIndex, $state);
            $lastCheckpointAt = \microtime(true);
            $context->emit('progress', [
                'stage' => 'generating',
                'message' => (string)__('正在生成第 %{1}/%{2} 张…', [$batchIndex, \count($jobSpecs)]),
                'batch_index' => $batchIndex,
                'batch_total' => \count($jobSpecs),
            ], 'media_ai_draw_progress:' . $batchIndex);

            $parentGenerationId = $index === 0
                ? $this->optionalGenerationId($input, 'parent_generation_id')
                : $jobSpecs[$index - 1]['generation_id'];
            try {
                $stored = $this->aiDrawService->generateAndStoreResumable(
                    adminId: $adminId,
                    taskId: $taskId,
                    sessionId: $sessionId,
                    generationId: $generationId,
                    prompt: $job['prompt'],
                    input: $input,
                    mode: $mode,
                    sourceFileHash: $this->optionalText($input, 'source_file_hash', self::MAX_SHORT_VALUE_BYTES),
                    parentGenerationId: $parentGenerationId,
                    batchIndex: $batchIndex,
                    batchTotal: \count($jobSpecs),
                    idempotencyKey: $effect->externalIdempotencyKey(),
                    heartbeat: function () use (
                        $context,
                        &$lastCheckpointAt,
                        &$state,
                        $inputHash,
                        $sessionId,
                        $mode,
                        $jobSpecs,
                        &$completedIds,
                        $batchIndex,
                    ): void {
                        $context->throwIfStopRequested();
                        $context->heartbeat();
                        if (\microtime(true) - $lastCheckpointAt < self::MAX_CHECKPOINT_GAP_SECONDS) {
                            return;
                        }
                        // The effect is still reserved, so this is an
                        // in-flight recovery point, never a claim that the
                        // provider call completed. A crash from here remains
                        // conservatively reconciliation-only.
                        $state = $this->checkpointState(
                            $inputHash,
                            $sessionId,
                            $mode,
                            $jobSpecs,
                            $state,
                            $completedIds,
                        );
                        $context->saveCheckpoint('generation_' . $batchIndex . '_inflight', $state);
                        $lastCheckpointAt = \microtime(true);
                    },
                );
            } catch (TaskStopRequestedException $throwable) {
                // A deliberate stop is not an uncertain provider outcome.
                // Let the Runtime convert it into its normal cancelled/expired
                // terminal state instead of incorrectly reporting recovery_unsafe.
                throw $throwable;
            } catch (\Throwable $throwable) {
                // The concrete Runtime context may surface its cooperative
                // stop as a runner-private exception rather than the public
                // TaskStopRequestedException documented on the interface.
                // Re-check the capability instead of converting a deliberate
                // cancellation into an unknown external side effect.
                if ($context->isStopRequested()) {
                    $context->throwIfStopRequested();
                }
                // A local result is sufficient reconciliation if storage won
                // the race with a crash after the provider returned.  Without
                // it, never issue another provider request for this effect.
                $stored = $this->aiDrawService->loadResumableGenerationPayload($adminId, $sessionId, $generationId);
                if ($stored === null) {
                    return $this->recoveryUnsafe(
                        $context,
                        $effect,
                        $sessionId,
                        $generationId,
                        $batchIndex,
                        $throwable->getMessage(),
                    );
                }
            }

            $context->throwIfStopRequested();
            $context->completeEffect($effectKey, $this->effectResult($stored));
            $completedIds[$batchIndex] = $generationId;
            $state = $this->checkpointState($inputHash, $sessionId, $mode, $jobSpecs, $state, $completedIds);
            $context->saveCheckpoint('generation_' . $batchIndex . '_stored', $state);
            $context->emit('preview', $stored);
        }

        $generationIds = \array_values($completedIds);
        $state = $this->checkpointState($inputHash, $sessionId, $mode, $jobSpecs, $state, $completedIds);
        $context->saveCheckpoint('completed', $state);
        $payload = $this->terminalPayload($taskId, $sessionId, $generationIds, $context->attempt());
        // Do not call this event `complete`: StreamHandle historically treats
        // that generic name as terminal before the persisted `completed`
        // event is written by the Runtime.
        $context->emit('generation_complete', $payload);

        return TaskResult::completed($payload);
    }

    private function backendAdminId(TaskOwner $owner): int
    {
        if ($owner->area !== 'backend'
            || \preg_match('/^backend:([1-9][0-9]*)$/', $owner->principal, $matches) !== 1) {
            throw new ResumableTaskAccessDeniedException('AI draw requires an authenticated backend owner.');
        }

        return (int)$matches[1];
    }

    /** @param array<string|int,mixed> $input */
    private function executionAdminId(array $input): int
    {
        $adminId = (int)($input['owner_admin_id'] ?? 0);
        if ($adminId < 1) {
            throw new \InvalidArgumentException('Invalid frozen AI-draw owner.');
        }

        return $adminId;
    }

    /** @param array<string|int,mixed> $input */
    private function mode(array $input): string
    {
        $mode = \strtolower(\trim((string)($input['mode'] ?? 'text2image')));
        if (!\in_array($mode, ['text2image', 'image2image', 'edit_turn', 'batch'], true)) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw mode.');
        }

        return $mode;
    }

    /** @param array<string|int,mixed> $input */
    private function size(array $input): string
    {
        $size = \trim((string)($input['size'] ?? '1024x1024'));
        if (\preg_match('/^([1-9][0-9]{1,3})x([1-9][0-9]{1,3})$/', $size, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw size.');
        }
        $width = (int)$matches[1];
        $height = (int)$matches[2];
        if ($width < 256 || $height < 256 || $width > 4096 || $height > 4096 || $width * $height > 16_777_216) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw size.');
        }

        return $size;
    }

    /** @param array<string|int,mixed> $input */
    private function aspectRatio(array $input): string
    {
        $ratio = \trim((string)($input['aspect_ratio'] ?? '1:1'));
        if (!\in_array($ratio, ['1:1', '16:9', '9:16', '4:3', '3:4'], true)) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw aspect ratio.');
        }

        return $ratio;
    }

    /** @param array<string|int,mixed> $input */
    private function outputFormat(array $input): string
    {
        $format = \strtolower(\trim((string)($input['output_format'] ?? 'png')));
        if ($format === 'jpg') {
            $format = 'jpeg';
        }
        if (!\in_array($format, ['png', 'webp', 'jpeg'], true)) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw output format.');
        }

        return $format;
    }

    /** @param array<string|int,mixed> $input */
    private function requiredIdentifier(array $input, string $key): string
    {
        $value = \trim((string)($input[$key] ?? ''));
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw input: ' . $key);
        }

        return $value;
    }

    /** @param array<string|int,mixed> $input */
    private function optionalSessionId(array $input): ?string
    {
        $value = \trim((string)($input['session_id'] ?? ''));
        if ($value === '') {
            return null;
        }
        if (\preg_match('/^[a-f0-9]{16,64}$/', \strtolower($value)) !== 1) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw session id.');
        }

        return \strtolower($value);
    }

    /** @param array<string|int,mixed> $input */
    private function requiredSessionId(array $input): string
    {
        return $this->optionalSessionId($input) ?? throw new \InvalidArgumentException('Missing frozen AI-draw session id.');
    }

    /** @param array<string|int,mixed> $input */
    private function optionalGenerationId(array $input, string $key): string
    {
        $value = \trim((string)($input[$key] ?? ''));
        if ($value === '') {
            return '';
        }
        if (\preg_match('/^[a-f0-9]{16,64}$/', \strtolower($value)) !== 1) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw generation id.');
        }

        return \strtolower($value);
    }

    /** @param array<string|int,mixed> $input */
    private function optionalText(array $input, string $key, int $maxBytes): string
    {
        $value = \trim((string)($input[$key] ?? ''));
        if (\strlen($value) > $maxBytes || \preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw input: ' . $key);
        }

        return $value;
    }

    /** @return list<string> */
    private function promptList(mixed $prompts): array
    {
        if (!\is_array($prompts)) {
            return [];
        }
        $result = [];
        foreach ($prompts as $prompt) {
            $value = \trim((string)$prompt);
            if ($value === '') {
                continue;
            }
            if (\strlen($value) > self::MAX_PROMPT_BYTES
                || \preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Invalid resumable AI-draw batch prompt.');
            }
            $result[] = $value;
            if (\count($result) >= self::BATCH_MAX) {
                break;
            }
        }

        return $result;
    }

    /** @return int<1,8> */
    private function batchCount(mixed $value): int
    {
        $count = (int)$value;
        if ($count < 1 || $count > self::BATCH_MAX) {
            throw new \InvalidArgumentException('Invalid resumable AI-draw batch count.');
        }

        return $count;
    }

    /** @return list<string> */
    private function buildJobs(string $mode, string $prompt, array $prompts, int $batchCount): array
    {
        if ($mode === 'batch') {
            if ($prompts !== []) {
                return $prompts;
            }

            return $prompt === '' ? [] : \array_fill(0, $batchCount, $prompt);
        }

        return $prompt === '' ? [] : [$prompt];
    }

    /**
     * @param list<string> $jobs
     * @return list<array{generation_id:string,prompt:string,prompt_hash:string}>
     */
    private function jobSpecs(string $taskId, array $jobs): array
    {
        $specs = [];
        foreach ($jobs as $index => $prompt) {
            $specs[] = [
                'generation_id' => \substr(\hash('sha256', $taskId . ':generation:' . ($index + 1)), 0, 24),
                'prompt' => $prompt,
                'prompt_hash' => \hash('sha256', $prompt),
            ];
        }

        return $specs;
    }

    /**
     * @param list<array{generation_id:string,prompt:string,prompt_hash:string}> $jobSpecs
     * @param array<string|int,mixed> $previous
     * @param array<int,string> $completedIds
     * @return array<string,mixed>
     */
    private function checkpointState(
        string $inputHash,
        string $sessionId,
        string $mode,
        array $jobSpecs,
        array $previous = [],
        array $completedIds = [],
    ): array {
        if ($completedIds === []) {
            $completedIds = $this->completedGenerationIds($previous);
        }
        \ksort($completedIds, SORT_NUMERIC);

        return [
            'input_hash' => $inputHash,
            'session_id' => $sessionId,
            'mode' => $mode,
            'jobs' => \array_map(static fn(array $job): array => [
                'generation_id' => $job['generation_id'],
                'prompt_hash' => $job['prompt_hash'],
            ], $jobSpecs),
            'completed_generation_ids' => \array_values($completedIds),
        ];
    }

    /**
     * @param array<string|int,mixed> $state
     * @return array<int,string>
     */
    private function completedGenerationIds(array $state): array
    {
        $ids = [];
        foreach (\is_array($state['completed_generation_ids'] ?? null) ? $state['completed_generation_ids'] : [] as $index => $generationId) {
            $value = \strtolower(\trim((string)$generationId));
            if (\preg_match('/^[a-f0-9]{16,64}$/', $value) === 1) {
                $ids[(int)$index + 1] = $value;
            }
        }

        return $ids;
    }

    /** @param array<string|int,mixed> $input */
    private function inputHash(array $input): string
    {
        $material = $input;
        \ksort($material);
        return \hash('sha256', \json_encode($material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{session_id:string,generation_id:string,batch_index:int,batch_total:int,mime_type:string,preview_token:string,suggested_filename:string} $payload
     * @return array<string,mixed>
     */
    private function effectResult(array $payload): array
    {
        return [
            'session_id' => $payload['session_id'],
            'generation_id' => $payload['generation_id'],
            'batch_index' => $payload['batch_index'],
            'batch_total' => $payload['batch_total'],
            'mime_type' => $payload['mime_type'],
        ];
    }

    /**
     * @param array<int,string> $generationIds
     * @return array<string,mixed>
     */
    private function terminalPayload(
        string $taskId,
        string $sessionId,
        array $generationIds,
        int $attempt,
        bool $recoveredFromCheckpoint = false,
    ): array {
        $ids = \array_values($generationIds);
        return [
            'task_id' => $taskId,
            'session_id' => $sessionId,
            'generation_id' => $ids[0] ?? '',
            'generation_ids' => $ids,
            'attempt' => $attempt,
            'recovered_from_checkpoint' => $recoveredFromCheckpoint,
        ];
    }

    private function recoveryUnsafe(
        ResumableTaskContextInterface $context,
        TaskEffectReservation $effect,
        string $sessionId,
        string $generationId,
        int $batchIndex,
        string $reason,
    ): TaskResult {
        $context->markEffectUnknown($effect->effectKey);

        return new TaskResult(
            ResumableTaskStatus::RECOVERY_UNSAFE,
            [
                'task_id' => $context->taskId(),
                'session_id' => $sessionId,
                'generation_id' => $generationId,
                'batch_index' => $batchIndex,
                'effect_key' => $effect->effectKey,
                'attempt' => $context->attempt(),
            ],
            'external_effect_unknown',
            $reason !== '' ? $reason : (string)__('AI 请求在确认结果前中断，无法安全重试。'),
        );
    }
}
