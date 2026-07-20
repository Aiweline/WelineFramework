<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Resumable;

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
use Weline\Theme\Service\ThemePreviewGenerator;

/**
 * Generates Theme previews from frozen target data in an isolated Runner.
 *
 * A screenshot output is deterministic for its target path. Therefore a
 * RESERVED preview effect can be retried after a Runner dies; UNKNOWN is not
 * retried because the filesystem/database result cannot be reconciled safely.
 */
final class ThemePreviewBatchTaskHandler implements ResumableTaskStartHandlerInterface
{
    private const TYPE_CODE = 'theme.preview_batch';

    public function __construct(private readonly ThemePreviewTaskProcessor $processor)
    {
    }

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        $this->assertBackendOwner($owner);
        $requestId = $this->requiredIdentifier($input, 'request_id');
        $themeId = $this->optionalPositiveInt($input, 'theme_id');
        $scope = strtolower(trim((string)($input['scope'] ?? ($themeId === null ? 'all' : 'single'))));
        if (!in_array($scope, ['all', 'single'], true)) {
            throw new \InvalidArgumentException('Invalid Theme preview task scope.');
        }
        if ($scope === 'single' && $themeId === null) {
            throw new \InvalidArgumentException('A single Theme preview task requires theme_id.');
        }
        if ($scope === 'all' && $themeId !== null) {
            throw new \InvalidArgumentException('A batch Theme preview task cannot include theme_id.');
        }

        $area = $this->optionalArea($input, 'area');
        if ($scope === 'single' && $area === null) {
            throw new \InvalidArgumentException('A single Theme preview task requires area.');
        }
        $force = $this->normalizeBoolean($input['force'] ?? true, 'force');
        $captureBaseUrl = ThemePreviewGenerator::normalizeCaptureBaseUrl(
            isset($input['capture_base_url']) ? (string)$input['capture_base_url'] : null
        );
        $targets = $this->processor->freezeTargets($themeId, $area, $force, $captureBaseUrl);

        return new TaskStartRequest(
            input: [
                'request_id' => $requestId,
                'scope' => $scope,
                'force' => $force,
                'capture_base_url' => $captureBaseUrl,
                'targets' => $targets,
            ],
            businessKey: 'theme.preview_batch:' . $owner->principal . ':' . $requestId,
            policy: TaskPolicy::defaults(),
        );
    }

    public function execute(
        ResumableTaskContextInterface $context,
        array $input,
        ?TaskCheckpoint $checkpoint,
    ): TaskResult {
        $targets = $this->targets($input['targets'] ?? []);
        $state = is_array($checkpoint?->state) ? $checkpoint->state : [];
        $results = is_array($state['results'] ?? null) ? $state['results'] : [];
        $nextIndex = max(0, min(count($targets), (int)($state['next_index'] ?? 0)));

        if ($checkpoint?->cursor === 'preview_batch_completed') {
            return TaskResult::completed($this->summary($targets, $results, true));
        }

        if ($nextIndex === 0) {
            $context->saveCheckpoint('preview_batch_started', $this->state($targets, $results, 0));
            $context->emit('start', [
                'message' => (string)__('开始生成主题预览图'),
                'total' => count($targets),
                'attempt' => $context->attempt(),
            ]);
        }

        for ($index = $nextIndex; $index < count($targets); $index++) {
            $context->throwIfStopRequested();
            $context->heartbeat();

            $target = $targets[$index];
            $effectKey = 'preview_' . $target['key'];
            $context->saveCheckpoint('before_preview', $this->state($targets, $results, $index, $target));
            $effect = $context->reserveEffect($effectKey);

            if ($effect->alreadyExisted && $effect->state === TaskEffectState::UNKNOWN) {
                return $this->recoveryUnsafe($context, $effectKey, $target);
            }

            if ($effect->alreadyExisted && $effect->state === TaskEffectState::APPLIED) {
                $result = $this->resultFromEffect($effect->result, $target);
            } else {
                // A reservation alone has no irreversible external call. The
                // preview file path is deterministic, so a RESERVED retry is
                // safe and converges on the same persisted target.
                $result = $this->runTarget(
                    $target,
                    $context,
                    $this->state($targets, $results, $index, $target),
                );
                $context->completeEffect($effectKey, $result);
            }

            $results[$target['key']] = $result;
            $completed = $index + 1;
            $context->saveCheckpoint('preview_saved', $this->state($targets, $results, $completed));
            $context->emit('progress', [
                'current' => $completed,
                'total' => count($targets),
                'progress' => count($targets) > 0 ? round(($completed / count($targets)) * 100, 2) : 100,
                'theme_id' => $target['theme_id'],
                'area' => $target['area'],
                'success' => (bool)($result['success'] ?? false),
                'image_url' => isset($result['image_url']) ? (string)$result['image_url'] : null,
                'error' => isset($result['error']) ? (string)$result['error'] : null,
                'message' => (bool)($result['success'] ?? false)
                    ? (string)__('主题预览图已保存')
                    : (string)($result['error'] ?? __('主题预览图生成失败')),
            ], 'theme_preview_progress:' . $context->attempt());
            $context->throwIfStopRequested();
        }

        $summary = $this->summary($targets, $results, false);
        $context->saveCheckpoint('preview_batch_completed', $this->state($targets, $results, count($targets)));

        return TaskResult::completed($summary);
    }

    /**
     * @param array{key:string,theme_id:int,area:string,force:bool} $target
     * @return array<string,mixed>
     */
    private function runTarget(
        array $target,
        ResumableTaskContextInterface $context,
        array $runningState,
    ): array
    {
        try {
            $lastCheckpointAt = microtime(true);
            return $this->processor->runTarget($target, static function () use (
                $context,
                $runningState,
                &$lastCheckpointAt,
            ): void {
                // Cancellation is intentionally observed before and after the
                // screenshot. The current screenshot gets a safe completion
                // boundary rather than leaving a partially managed process.
                $context->heartbeat();
                if ((microtime(true) - $lastCheckpointAt) >= TaskPolicy::DEFAULT_CHECKPOINT_MAX_INTERVAL_SECONDS) {
                    $context->saveCheckpoint('preview_running', $runningState);
                    $lastCheckpointAt = microtime(true);
                }
            });
        } catch (\Throwable $throwable) {
            return [
                'key' => $target['key'],
                'theme_id' => $target['theme_id'],
                'area' => $target['area'],
                'success' => false,
                'error' => $this->safeErrorMessage($throwable),
            ];
        }
    }

    /**
     * @param array<string|int,mixed> $effectResult
     * @param array{key:string,theme_id:int,area:string,force:bool} $target
     * @return array<string,mixed>
     */
    private function resultFromEffect(array $effectResult, array $target): array
    {
        return [
            'key' => $target['key'],
            'theme_id' => $target['theme_id'],
            'area' => $target['area'],
            'success' => (bool)($effectResult['success'] ?? false),
            ...$effectResult,
        ];
    }

    /**
     * @param array{key:string,theme_id:int,area:string,force:bool} $target
     */
    private function recoveryUnsafe(
        ResumableTaskContextInterface $context,
        string $effectKey,
        array $target,
    ): TaskResult {
        $context->markEffectUnknown($effectKey);
        return new TaskResult(
            ResumableTaskStatus::RECOVERY_UNSAFE,
            [
                'effect_key' => $effectKey,
                'theme_id' => $target['theme_id'],
                'area' => $target['area'],
                'attempt' => $context->attempt(),
            ],
            'external_effect_unknown',
            (string)__('主题预览图生成在确认保存前中断，无法安全恢复。'),
        );
    }

    /**
     * @param list<array{key:string,theme_id:int,area:string,force:bool}> $targets
     * @param array<string|int,mixed> $results
     * @param array{key:string,theme_id:int,area:string,force:bool}|null $current
     * @return array<string,mixed>
     */
    private function state(array $targets, array $results, int $nextIndex, ?array $current = null): array
    {
        return [
            'target_hash' => hash('sha256', json_encode($targets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'total' => count($targets),
            'next_index' => $nextIndex,
            'results' => $results,
            'current_target' => $current,
        ];
    }

    /**
     * @param list<array{key:string,theme_id:int,area:string,force:bool}> $targets
     * @param array<string|int,mixed> $results
     * @return array<string,mixed>
     */
    private function summary(array $targets, array $results, bool $recovered): array
    {
        $values = array_values(array_filter($results, 'is_array'));
        $success = count(array_filter($values, static fn(array $item): bool => (bool)($item['success'] ?? false)));
        $failed = count($values) - $success;

        return [
            'total' => count($targets),
            'success' => $success,
            'failed' => $failed,
            'results' => $values,
            'recovered_from_checkpoint' => $recovered,
        ];
    }

    /** @return list<array{key:string,theme_id:int,area:string,force:bool}> */
    private function targets(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Frozen Theme preview targets are invalid.');
        }

        $targets = [];
        foreach ($value as $target) {
            if (!is_array($target)) {
                throw new \InvalidArgumentException('Frozen Theme preview target is invalid.');
            }
            $themeId = (int)($target['theme_id'] ?? 0);
            $area = strtolower(trim((string)($target['area'] ?? '')));
            $key = (string)($target['key'] ?? '');
            if ($themeId <= 0 || !in_array($area, ['frontend', 'backend'], true)
                || $key !== 'theme_' . $themeId . '_' . $area) {
                throw new \InvalidArgumentException('Frozen Theme preview target is invalid.');
            }
            $targets[] = [
                'key' => $key,
                'theme_id' => $themeId,
                'area' => $area,
                'force' => (bool)($target['force'] ?? false),
            ];
        }
        return $targets;
    }

    private function assertBackendOwner(TaskOwner $owner): void
    {
        if ($owner->area !== 'backend' || !str_starts_with($owner->principal, 'backend:')) {
            throw new ResumableTaskAccessDeniedException('Theme preview tasks require a backend owner.');
        }
    }

    private function requiredIdentifier(array $input, string $key): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid Theme preview input: ' . $key);
        }
        return $value;
    }

    private function optionalPositiveInt(array $input, string $key): ?int
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return null;
        }
        $value = filter_var($input[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new \InvalidArgumentException('Invalid Theme preview input: ' . $key);
        }
        return (int)$value;
    }

    private function optionalArea(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || trim((string)$input[$key]) === '') {
            return null;
        }
        $area = strtolower(trim((string)$input[$key]));
        if (!in_array($area, ['frontend', 'backend'], true)) {
            throw new \InvalidArgumentException('Invalid Theme preview input: ' . $key);
        }
        return $area;
    }

    private function normalizeBoolean(mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => throw new \InvalidArgumentException('Invalid Theme preview input: ' . $key),
            };
        }
        throw new \InvalidArgumentException('Invalid Theme preview input: ' . $key);
    }

    private function safeErrorMessage(\Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());
        return $message === '' ? (string)__('主题预览图生成失败') : mb_strimwidth($message, 0, 1_500, '…');
    }
}
