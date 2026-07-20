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

/**
 * Detached handler for all AI VirtualTheme create/edit/rebuild operations.
 *
 * The generation result and the persisted Theme version are independent
 * effects.  A previously reserved AI request cannot be guessed after a
 * runner crash, so it enters recovery_unsafe rather than charge/re-run an
 * unknown provider operation.  Persistence is reconcilable through the
 * runtime task metadata stored on the version.
 */
final class ThemeVirtualThemeGenerationTaskHandler implements ResumableTaskStartHandlerInterface
{
    private const TYPE_CODE = 'theme.virtual_theme_generation';

    public function __construct(private readonly ThemeVirtualThemeRuntimeInterface $runtime)
    {
    }

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        $this->assertBackendOwner($owner);
        $actorId = $this->ownerId($owner);
        $frozen = $this->runtime->freezeTaskInput($input, $actorId);

        return new TaskStartRequest(
            input: $frozen,
            businessKey: self::TYPE_CODE . ':' . $owner->principal . ':' . (string)$frozen['request_id'],
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
        $actorId = $this->ownerIdFromInput($input);

        if ($checkpoint?->cursor === 'virtual_theme_completed') {
            return TaskResult::completed($this->summary($targets, $results, true));
        }

        if ($nextIndex === 0) {
            $context->saveCheckpoint('virtual_theme_started', $this->state($results, 0));
            $context->emit('start', [
                'message' => (string)__('开始生成虚拟主题草稿'),
                'total' => count($targets),
                'attempt' => $context->attempt(),
                'theme_id' => (int)($input['theme_id'] ?? 0),
                'area' => (string)($input['area'] ?? 'frontend'),
            ]);
        }

        for ($index = $nextIndex; $index < count($targets); $index++) {
            $context->throwIfStopRequested();
            $context->heartbeat();
            $target = $targets[$index];
            $plan = $this->resumePlan($state, $index, $target);
            if ($plan === null) {
                try {
                    $plan = $this->runtime->planTarget($input, $target);
                } catch (\Throwable $throwable) {
                    return $this->failed($context, $results, $target, 'plan_failed', $throwable);
                }
                $context->saveCheckpoint('target_planned', $this->state($results, $index, $plan));
                $context->emit('progress', $this->progressPayload($input, $target, $index, count($targets), 'planned'));
            }

            $generationKey = 'generation_' . $target['key'];
            $context->saveCheckpoint('before_generation', $this->state($results, $index, $plan, null, $generationKey));
            $generation = $context->reserveEffect($generationKey);
            if ($generation->alreadyExisted) {
                if ($generation->state === TaskEffectState::APPLIED && $this->isGeneratedPayload($generation->result)) {
                    $generated = $generation->result;
                } else {
                    return $this->recoveryUnsafe($context, $generationKey, $target, 'generation');
                }
            } else {
                try {
                    $generated = $this->runtime->generateSource($plan, $generation->externalIdempotencyKey(), $actorId);
                } catch (\Throwable $throwable) {
                    return $this->failed($context, $results, $target, 'generation_failed', $throwable);
                }
                $context->completeEffect($generationKey, $generated);
            }
            $context->saveCheckpoint('generation_completed', $this->state($results, $index, $plan, $generated));
            $context->emit('progress', $this->progressPayload($input, $target, $index, count($targets), 'generated'));
            $context->throwIfStopRequested();

            $persistKey = 'persist_' . $target['key'];
            $context->saveCheckpoint('before_persist', $this->state($results, $index, $plan, $generated, $persistKey));
            $persist = $context->reserveEffect($persistKey);
            if ($persist->alreadyExisted && $persist->state === TaskEffectState::APPLIED) {
                $saved = $persist->result;
            } elseif ($persist->alreadyExisted) {
                $saved = $this->runtime->findPersisted($plan, $context->taskId(), $target['key']);
                if ($saved === null) {
                    return $this->recoveryUnsafe($context, $persistKey, $target, 'persistence');
                }
                $context->completeEffect($persistKey, $saved);
            } else {
                try {
                    $saved = $this->runtime->persistGenerated(
                        $plan,
                        $generated,
                        $context->taskId(),
                        $target['key'],
                        $actorId,
                    );
                } catch (\Throwable $throwable) {
                    return $this->failed($context, $results, $target, 'persist_failed', $throwable);
                }
                $context->completeEffect($persistKey, $saved);
            }

            $results[$target['key']] = $saved + [
                'key' => $target['key'],
                'layout_type' => $target['layout_type'],
                'layout_option' => $target['layout_option'],
                'success' => true,
            ];
            $completed = $index + 1;
            $state = $this->state($results, $completed);
            $context->saveCheckpoint('target_persisted', $state);
            $context->emit('progress', $this->progressPayload($input, $target, $completed, count($targets), 'persisted', $saved));
            $context->throwIfStopRequested();
        }

        $summary = $this->summary($targets, $results, false);
        $context->saveCheckpoint('virtual_theme_completed', $this->state($results, count($targets)));
        return TaskResult::completed($summary);
    }

    /** @return list<array{key:string,layout_type:string,layout_option:string}> */
    private function targets(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Frozen Theme virtual layout targets are invalid.');
        }
        $targets = [];
        foreach ($value as $target) {
            if (!is_array($target)) {
                throw new \InvalidArgumentException('Frozen Theme virtual layout target is invalid.');
            }
            $key = trim((string)($target['key'] ?? ''));
            $layoutType = trim((string)($target['layout_type'] ?? ''));
            $layoutOption = trim((string)($target['layout_option'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/', $key) !== 1
                || preg_match('/^[a-z0-9_-]{1,64}$/', $layoutType) !== 1
                || preg_match('/^[a-z0-9_-]{1,128}$/', $layoutOption) !== 1
                || $layoutOption === 'default') {
                throw new \InvalidArgumentException('Frozen Theme virtual layout target is invalid.');
            }
            $targets[] = ['key' => $key, 'layout_type' => $layoutType, 'layout_option' => $layoutOption];
        }
        return $targets;
    }

    /** @param array<string,mixed> $state @param array{key:string,layout_type:string,layout_option:string} $target @return array<string,mixed>|null */
    private function resumePlan(array $state, int $index, array $target): ?array
    {
        if ((int)($state['current_index'] ?? -1) !== $index || !is_array($state['plan'] ?? null)) {
            return null;
        }
        $plan = $state['plan'];
        if ((string)($plan['target']['key'] ?? '') !== $target['key']) {
            return null;
        }
        return $plan;
    }

    /** @param array<string,mixed> $results @param array<string,mixed>|null $plan @param array<string,mixed>|null $generated @return array<string,mixed> */
    private function state(array $results, int $nextIndex, ?array $plan = null, ?array $generated = null, ?string $effectKey = null): array
    {
        return array_filter([
            'next_index' => $nextIndex,
            'results' => $results,
            'current_index' => $plan === null ? null : $nextIndex,
            'plan' => $plan,
            'generated' => $generated,
            'effect_key' => $effectKey,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /** @param array<string,mixed> $input @param array{key:string,layout_type:string,layout_option:string} $target @param array<string,mixed> $saved @return array<string,mixed> */
    private function progressPayload(array $input, array $target, int $current, int $total, string $phase, array $saved = []): array
    {
        return [
            'current' => min($current, $total),
            'total' => $total,
            'progress' => $total > 0 ? round((min($current, $total) / $total) * 100, 2) : 100,
            'phase' => $phase,
            'theme_id' => (int)($input['theme_id'] ?? 0),
            'area' => (string)($input['area'] ?? 'frontend'),
            'layout_type' => $target['layout_type'],
            'layout_option' => $target['layout_option'],
            'asset_id' => (int)($saved['asset_id'] ?? 0),
            'draft_version_id' => (int)($saved['draft_version_id'] ?? $saved['version_id'] ?? 0),
            'message' => $phase === 'persisted'
                ? (string)__('虚拟主题布局草稿已保存')
                : (string)__('正在生成虚拟主题布局草稿'),
        ];
    }

    /** @param list<array{key:string,layout_type:string,layout_option:string}> $targets @param array<string,mixed> $results @return array<string,mixed> */
    private function summary(array $targets, array $results, bool $recovered): array
    {
        $items = array_values(array_filter($results, 'is_array'));
        return [
            'total' => count($targets),
            'success' => count($items),
            'failed' => max(0, count($targets) - count($items)),
            'layout_option' => (string)($targets[0]['layout_option'] ?? ''),
            'layout_types' => array_values(array_map(static fn(array $target): string => $target['layout_type'], $targets)),
            'created_count' => count($items),
            'results' => $items,
            'recovered_from_checkpoint' => $recovered,
        ];
    }

    /** @param array{key:string,layout_type:string,layout_option:string} $target @param array<string,mixed> $results */
    private function failed(ResumableTaskContextInterface $context, array $results, array $target, string $code, \Throwable $throwable): TaskResult
    {
        $message = trim($throwable->getMessage());
        $message = $message === '' ? (string)__('虚拟主题任务失败') : mb_strimwidth($message, 0, 1_500, '…');
        $checkpointState = $context->checkpoint()?->state;
        $context->saveCheckpoint('virtual_theme_failed', $this->state($results, (int)($checkpointState['next_index'] ?? 0)) + [
            'failed_target' => $target,
            'error_code' => $code,
        ]);
        $context->emit('error', ['code' => $code, 'message' => $message, 'layout_type' => $target['layout_type']]);
        return TaskResult::failed($code, $message, ['target' => $target, 'results' => array_values($results)]);
    }

    /** @param array{key:string,layout_type:string,layout_option:string} $target */
    private function recoveryUnsafe(ResumableTaskContextInterface $context, string $effectKey, array $target, string $phase): TaskResult
    {
        $context->markEffectUnknown($effectKey);
        return new TaskResult(
            ResumableTaskStatus::RECOVERY_UNSAFE,
            ['effect_key' => $effectKey, 'phase' => $phase, 'target' => $target, 'attempt' => $context->attempt()],
            'external_effect_unknown',
            (string)__('虚拟主题 AI 外部副作用在确认前中断，无法安全恢复。'),
        );
    }

    private function isGeneratedPayload(array $value): bool
    {
        return trim((string)($value['source_code'] ?? '')) !== '' && is_array($value['payload'] ?? []);
    }

    private function assertBackendOwner(TaskOwner $owner): void
    {
        if ($owner->area !== 'backend' || !str_starts_with($owner->principal, 'backend:')) {
            throw new ResumableTaskAccessDeniedException('Theme virtual theme tasks require a backend owner.');
        }
    }

    private function ownerId(TaskOwner $owner): int
    {
        $id = substr($owner->principal, strlen('backend:'));
        return ctype_digit($id) ? (int)$id : 0;
    }

    private function ownerIdFromInput(array $input): int
    {
        // The value is written by prepareStart after deriving it from the
        // authenticated owner, never accepted as an API parameter.
        return max(0, (int)($input['actor_id'] ?? 0));
    }
}
