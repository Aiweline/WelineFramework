<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Resumable;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AiService;
use Weline\Framework\App\Exception as FrameworkException;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStartHandlerInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectState;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\TaskStartRequest;

/**
 * Detached, replayable frontend chat generation.
 *
 * This handler intentionally saves application state only. It cannot and does
 * not attempt to serialize the provider call stack, Fiber, callback, socket,
 * or partial response stream. A pre-effect crash is reported as
 * recovery_unsafe when the provider result cannot be reconciled safely.
 */
final class ChatGenerationTaskHandler implements ResumableTaskStartHandlerInterface
{
    private const TYPE_CODE = 'ai.chat_generation';
    private const MAX_MESSAGE_BYTES = 16_384;
    private const MAX_RESPONSE_BYTES = 1_048_576;
    private const EFFECT_KEY = 'chat_generation';

    public function __construct(private readonly AiService $aiService)
    {
    }

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        if (!$this->isChatOwner($owner)) {
            throw new ResumableTaskAccessDeniedException('Chat generation requires a valid browser owner.');
        }

        $message = $this->requiredString($input, 'message', self::MAX_MESSAGE_BYTES);
        $requestId = $this->requiredIdentifier($input, 'request_id');
        $modelCode = $this->optionalString($input, 'model_code', 128);
        $scenarioCode = $this->optionalString($input, 'scenario_code', 128);
        $locale = $this->optionalString($input, 'locale', 32);
        $modelCode = $this->resolveChatModelCode($modelCode, $scenarioCode);

        return new TaskStartRequest(
            input: [
                'message' => $message,
                'request_id' => $requestId,
                'model_code' => $modelCode,
                'scenario_code' => $scenarioCode,
                'locale' => $locale,
            ],
            // Owner principals may be long (for example an external subject
            // identifier). Keep the durable unique key bounded while still
            // binding its idempotency scope to both owner and request id.
            businessKey: 'ai.chat:' . hash('sha256', $owner->principal . "\0" . $requestId),
            policy: TaskPolicy::defaults(),
        );
    }

    /**
     * The chat page deliberately supports a guest/demo session. The owner is
     * still server-derived: anonymous principals must exactly match the
     * frontend session captured by ResumableTaskOwnerResolver. This keeps
     * detached tasks attached to one browser session instead of accepting an
     * arbitrary client-supplied principal.
     *
     * A browser may also carry a valid backend login while visiting this
     * public page. ResumableTaskOwnerResolver intentionally gives that
     * authenticated server scope precedence, so permit only its canonical
     * backend principal here. This is not a client-selectable area and keeps
     * the task bound to the authenticated administrator session.
     */
    private function isChatOwner(TaskOwner $owner): bool
    {
        if ($owner->area === 'backend') {
            return str_starts_with($owner->principal, 'backend:')
                && $owner->sessionId !== null
                && $owner->sessionId !== '';
        }

        if ($owner->area !== 'frontend') {
            return false;
        }

        if (str_starts_with($owner->principal, 'frontend:')) {
            return true;
        }

        $sessionId = $owner->sessionId;
        return $sessionId !== null
            && $sessionId !== ''
            && hash_equals('session:' . $sessionId, $owner->principal);
    }

    public function execute(
        ResumableTaskContextInterface $context,
        array $input,
        ?TaskCheckpoint $checkpoint,
    ): TaskResult {
        $message = $this->requiredString($input, 'message', self::MAX_MESSAGE_BYTES);
        $modelCode = $this->optionalString($input, 'model_code', 128);
        $scenarioCode = $this->optionalString($input, 'scenario_code', 128);
        $locale = $this->optionalString($input, 'locale', 32);
        $promptHash = hash('sha256', $message);
        $checkpointState = $checkpoint?->state ?? [];

        if ($checkpoint?->cursor === 'generated') {
            $response = (string)($checkpointState['response'] ?? '');
            if ($response !== '') {
                return TaskResult::completed([
                    'message' => $response,
                    'attempt' => $context->attempt(),
                    'recovered_from_checkpoint' => true,
                ]);
            }
        }

        $effect = $context->reserveEffect(self::EFFECT_KEY);
        if ($effect->alreadyExisted) {
            if ($effect->state === TaskEffectState::APPLIED) {
                $response = (string)($effect->result['response'] ?? '');
                if ($response !== '') {
                    $context->saveCheckpoint('generated', [
                        'prompt_hash' => $promptHash,
                        'response' => $response,
                        'effect_key' => self::EFFECT_KEY,
                    ]);
                    return TaskResult::completed([
                        'message' => $response,
                        'attempt' => $context->attempt(),
                        'recovered_from_effect_ledger' => true,
                    ]);
                }
            }

            // AiService currently has no portable acknowledgement/query
            // contract for every provider. A reserved record from an earlier
            // Runner can therefore mean the remote provider already completed
            // the call. Do not blindly create a second chat completion.
            $context->markEffectUnknown(self::EFFECT_KEY);
            return new TaskResult(
                ResumableTaskStatus::RECOVERY_UNSAFE,
                [
                    'attempt' => $context->attempt(),
                    'effect_key' => self::EFFECT_KEY,
                    'prompt_hash' => $promptHash,
                ],
                'external_effect_unknown',
                (string)__('AI 请求在确认结果前中断，无法安全重试。'),
            );
        }

        $context->saveCheckpoint('before_generation', [
            'prompt_hash' => $promptHash,
            'effect_key' => self::EFFECT_KEY,
            'request_id' => (string)($input['request_id'] ?? ''),
        ]);

        if ($checkpoint !== null) {
            $context->emit('attempt_reset', [
                'attempt' => $context->attempt(),
                'reason' => 'runner_recovered',
            ]);
        }
        $context->emit('start', [
            'attempt' => $context->attempt(),
            'message' => (string)__('正在生成回复…'),
        ]);

        $response = '';
        $pendingChunk = '';
        $lastEmitAt = microtime(true);
        $lastCheckpointAt = $lastEmitAt;
        $flush = function () use (&$pendingChunk, &$lastEmitAt, $context): void {
            if ($pendingChunk === '') {
                return;
            }
            $context->emit('chunk', [
                'attempt' => $context->attempt(),
                'chunk' => $pendingChunk,
            ], 'chat_chunk:' . $context->attempt());
            $pendingChunk = '';
            $lastEmitAt = microtime(true);
        };

        try {
            $this->aiService->generateStream(
                $message,
                function (mixed $chunk) use (
                    &$response,
                    &$pendingChunk,
                    &$lastEmitAt,
                    &$lastCheckpointAt,
                    $context,
                    $promptHash,
                    $flush,
                ): void {
                    $context->throwIfStopRequested();
                    $context->heartbeat();
                    $text = (string)$chunk;
                    if ($text === '') {
                        return;
                    }
                    if (strlen($response) + strlen($text) > self::MAX_RESPONSE_BYTES) {
                        throw new \RuntimeException('AI chat response exceeds the resumable runtime response limit.');
                    }
                    $response .= $text;
                    $pendingChunk .= $text;
                    $now = microtime(true);
                    if (strlen($pendingChunk) >= 32_768 || ($now - $lastEmitAt) >= 0.25) {
                        $flush();
                    }
                    if (($now - $lastCheckpointAt) >= TaskPolicy::DEFAULT_CHECKPOINT_MAX_INTERVAL_SECONDS) {
                        $context->saveCheckpoint('generating', [
                            'prompt_hash' => $promptHash,
                            'effect_key' => self::EFFECT_KEY,
                            'response_bytes' => strlen($response),
                        ]);
                        $lastCheckpointAt = $now;
                    }
                },
                $modelCode,
                $scenarioCode,
                $locale,
                [
                    'resumable_task_id' => $context->taskId(),
                    'idempotency_key' => $effect->externalIdempotencyKey(),
                    // Provider balance is not synchronously authoritative for
                    // every vendor. A configured, active and connection-verified
                    // account must reach the provider, which remains the source
                    // of truth for quota or billing rejection.
                    'allow_zero_balance_provider' => true,
                ],
            );
        } catch (FrameworkException) {
            // Provider failures are expected operational outcomes, not Runner
            // failures. Keep provider diagnostics server-side and give the
            // browser a safe action-oriented terminal reason instead.
            return TaskResult::failed(
                'ai_generation_failed',
                (string)__('AI 服务暂时不可用，请检查所选模型和供应商连接后重试。'),
                ['model_code' => $modelCode],
            );
        }

        $flush();
        $context->throwIfStopRequested();
        $context->completeEffect(self::EFFECT_KEY, ['response' => $response]);
        $context->saveCheckpoint('generated', [
            'prompt_hash' => $promptHash,
            'effect_key' => self::EFFECT_KEY,
            'response' => $response,
        ]);
        $context->emit('message_completed', [
            'attempt' => $context->attempt(),
            'response_bytes' => strlen($response),
        ]);

        return TaskResult::completed([
            'message' => $response,
            'attempt' => $context->attempt(),
        ]);
    }

    /** @param array<string|int,mixed> $input */
    private function requiredString(array $input, string $key, int $maxBytes): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new \InvalidArgumentException('Invalid resumable chat input: ' . $key);
        }
        return $value;
    }

    /** @param array<string|int,mixed> $input */
    private function optionalString(array $input, string $key, int $maxBytes): ?string
    {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Invalid resumable chat input: ' . $key);
        }
        return $value;
    }

    /**
     * A public chat may omit model_code. Resolve the configured default first,
     * then select one active text model deterministically so a missing global
     * default never turns into a detached Runner exception.
     */
    private function resolveChatModelCode(?string $requestedModelCode, ?string $scenarioCode): string
    {
        $models = $this->aiService->listModels(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT);
        $modelsByCode = [];
        foreach ($models as $model) {
            $code = trim((string)($model['model_code'] ?? ''));
            if ($code !== '') {
                $modelsByCode[$code] = $model;
            }
        }

        if ($modelsByCode === []) {
            throw new \InvalidArgumentException((string)__('没有可用于文本聊天的已激活模型。'));
        }

        if ($requestedModelCode !== null) {
            if (!isset($modelsByCode[$requestedModelCode])) {
                throw new \InvalidArgumentException((string)__('所选模型不可用于文本聊天。'));
            }

            return $requestedModelCode;
        }

        $resolved = $this->aiService->resolveModel(
            null,
            $scenarioCode,
            AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
        );
        $resolvedCode = trim((string)($resolved['model_code'] ?? ''));
        if ($resolvedCode !== '' && isset($modelsByCode[$resolvedCode])) {
            return $resolvedCode;
        }

        $fallbacks = array_values($modelsByCode);
        usort($fallbacks, static function (array $left, array $right): int {
            $defaultOrder = (int)($right['is_default'] ?? 0) <=> (int)($left['is_default'] ?? 0);
            if ($defaultOrder !== 0) {
                return $defaultOrder;
            }

            return strcmp((string)($left['model_code'] ?? ''), (string)($right['model_code'] ?? ''));
        });

        return (string)$fallbacks[0]['model_code'];
    }

    /** @param array<string|int,mixed> $input */
    private function requiredIdentifier(array $input, string $key): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid resumable chat input: ' . $key);
        }
        return $value;
    }
}
