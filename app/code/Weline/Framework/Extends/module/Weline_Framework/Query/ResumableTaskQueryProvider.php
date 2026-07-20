<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskEventStreamInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskRuntimeInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskRuntimeUnavailableException;
use Weline\Framework\Runtime\Resumable\ResumableTaskStarterInterface;
use Weline\Framework\Runtime\Resumable\TaskEventReplay;
use Weline\Framework\Runtime\Resumable\TaskLease;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskSnapshot;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Service\Runtime\ResumableTaskOwnerResolver;
use Weline\Framework\Service\Runtime\ResumableTaskStoreException;

/**
 * Browser-facing control and subscription surface for resumable tasks.
 *
 * It is deliberately a read/control adapter only: handlers always run in an
 * isolated runner, while this provider only checks the current server session,
 * reads persisted events, and asks the runtime to renew or cancel explicitly.
 */
final class ResumableTaskQueryProvider implements QueryProviderInterface
{
    private const EVENT_PAGE_SIZE = 200;
    private const DEFAULT_POLL_INTERVAL_MILLISECONDS = 250;
    private const DEFAULT_SUBSCRIPTION_SECONDS = 55;

    public function __construct(
        private readonly ResumableTaskRuntimeInterface $runtime,
        private readonly ResumableTaskStarterInterface $starter,
        private readonly ResumableTaskOwnerResolver $ownerResolver,
        private readonly Request $request,
        private readonly int $pollIntervalMilliseconds = self::DEFAULT_POLL_INTERVAL_MILLISECONDS,
        private readonly int $subscriptionSeconds = self::DEFAULT_SUBSCRIPTION_SECONDS,
    ) {
        if ($this->pollIntervalMilliseconds < 1 || $this->pollIntervalMilliseconds > 5_000) {
            throw new \InvalidArgumentException('Runtime task stream poll interval is invalid.');
        }
        if ($this->subscriptionSeconds < 1 || $this->subscriptionSeconds > 300) {
            throw new \InvalidArgumentException('Runtime task subscription duration is invalid.');
        }
    }

    public function getProviderName(): string
    {
        return 'runtime_task';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'start' => $this->start($params),
            'status' => $this->status($params),
            'touch' => $this->touch($params),
            'cancel' => $this->cancel($params),
            'events' => $this->events($params),
            default => throw new \InvalidArgumentException(
                (string)__('运行时任务查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('可恢复后台任务'),
            'description' => (string)__('读取任务状态、续租、显式取消及订阅持久事件。'),
            'module' => 'Weline_Framework',
            'operations' => [
                [
                    'name' => 'start',
                    'description' => (string)__('启动已注册的可恢复后台任务。任务类型、幂等键和策略由服务端处理器冻结。'),
                    'mode' => 'write',
                    'frontend' => true,
                    'graph' => false,
                    'params' => [
                        ['name' => 'type_code', 'type' => 'string', 'required' => true, 'max_length' => 128],
                        ['name' => 'input', 'type' => 'map', 'required' => true],
                    ],
                ],
                [
                    'name' => 'status',
                    'description' => (string)__('读取当前用户可访问的后台任务状态。'),
                    'mode' => 'read',
                    'frontend' => true,
                    'graph' => false,
                    'params' => [
                        ['name' => 'task_id', 'type' => 'string', 'required' => true, 'max_length' => 128],
                    ],
                ],
                [
                    'name' => 'touch',
                    'description' => (string)__('续期当前页面的任务客户端租约。'),
                    'mode' => 'write',
                    'frontend' => true,
                    'graph' => false,
                    'params' => [
                        ['name' => 'task_id', 'type' => 'string', 'required' => true, 'max_length' => 128],
                        ['name' => 'lease_id', 'type' => 'string', 'required' => true, 'max_length' => 128],
                    ],
                ],
                [
                    'name' => 'cancel',
                    'description' => (string)__('使用幂等意图明确请求取消后台任务。'),
                    'mode' => 'write',
                    'frontend' => true,
                    'graph' => false,
                    'params' => [
                        ['name' => 'task_id', 'type' => 'string', 'required' => true, 'max_length' => 128],
                        ['name' => 'intent_id', 'type' => 'string', 'required' => true, 'max_length' => 128],
                        ['name' => 'reason', 'type' => 'string', 'required' => false, 'max_length' => 2_000],
                    ],
                ],
                [
                    'name' => 'events',
                    'description' => (string)__('订阅或从持久事件游标重放后台任务事件。'),
                    'mode' => 'stream',
                    'frontend' => true,
                    'graph' => false,
                    'params' => [
                        ['name' => 'task_id', 'type' => 'string', 'required' => true, 'max_length' => 128],
                        ['name' => 'lease_id', 'type' => 'string', 'required' => true, 'max_length' => 128],
                        // Keep the cursor lexical through the frontend
                        // gateway.  Casting an untrusted decimal string to
                        // PHP int there would silently saturate an oversized
                        // Last-Event-ID before resolveCursor() can reject it.
                        ['name' => 'last_event_id', 'type' => 'string', 'required' => false, 'max_length' => 20],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function start(array $params): array
    {
        $typeCode = $this->requireIdentifier($params, 'type_code');
        $input = $params['input'] ?? null;
        if (!\is_array($input) || \array_is_list($input)) {
            throw new FrontendQueryException('validation_error', 'Runtime task input must be a map.', 422);
        }

        $owner = $this->resolveOwner();
        try {
            return $this->starter->startForOwner($typeCode, $input, $owner)->toArray();
        } catch (ResumableTaskAccessDeniedException $exception) {
            throw new FrontendQueryException('not_found', 'Runtime task was not found.', 404, $exception);
        } catch (ResumableTaskStoreException|\InvalidArgumentException $exception) {
            throw new FrontendQueryException('validation_error', 'Invalid runtime task start request.', 422, $exception);
        } catch (ResumableTaskRuntimeUnavailableException $exception) {
            throw new FrontendQueryException('runtime_unavailable', 'Resumable task runtime is unavailable.', 503, $exception);
        } catch (\Throwable $exception) {
            throw new FrontendQueryException('runtime_unavailable', 'Resumable task runtime is unavailable.', 503, $exception);
        }
    }

    /** @param array<string,mixed> $params */
    private function status(array $params): array
    {
        $taskId = $this->requireIdentifier($params, 'task_id');
        $owner = $this->resolveOwner();
        $snapshot = $this->runtimeCall(fn(): TaskSnapshot => $this->runtime->status($taskId, $owner));
        $this->assertOwnerMatches($owner, $snapshot->owner);

        return $this->snapshotPayload($snapshot);
    }

    /** @param array<string,mixed> $params */
    private function touch(array $params): array
    {
        $taskId = $this->requireIdentifier($params, 'task_id');
        $leaseId = $this->requireIdentifier($params, 'lease_id');
        $owner = $this->resolveOwner();
        $lease = $this->runtimeCall(fn(): TaskLease => $this->runtime->renew($taskId, $leaseId, $owner));
        $this->assertOwnerMatches($owner, $lease->owner);

        return [
            'task_id' => $lease->taskId,
            'lease_id' => $lease->leaseId,
            'last_seen_at' => $lease->lastSeenAt,
            'expires_at' => $lease->expiresAt,
        ];
    }

    /** @param array<string,mixed> $params */
    private function cancel(array $params): array
    {
        $taskId = $this->requireIdentifier($params, 'task_id');
        $intentId = $this->requireIdentifier($params, 'intent_id');
        $reason = $this->optionalReason($params);
        $owner = $this->resolveOwner();
        $snapshot = $this->runtimeCall(
            fn(): TaskSnapshot => $this->runtime->cancel($taskId, $owner, $intentId, $reason)
        );
        $this->assertOwnerMatches($owner, $snapshot->owner);

        return $this->snapshotPayload($snapshot);
    }

    /**
     * @param array<string,mixed> $params
     * @return \Generator<int, array<string,mixed>>
     */
    private function events(array $params): \Generator
    {
        $taskId = $this->requireIdentifier($params, 'task_id');
        $leaseId = $this->requireIdentifier($params, 'lease_id');
        $owner = $this->resolveOwner();
        $cursor = $this->resolveCursor($params);
        $deadline = \microtime(true) + $this->subscriptionSeconds;
        $opened = false;

        while (\microtime(true) < $deadline) {
            $replay = $this->readReplay($taskId, $leaseId, $owner, $cursor);
            $this->assertOwnerMatches($owner, $replay->task->owner);

            if (!$opened) {
                $opened = true;
                yield [
                    'event' => 'runtime_open',
                    'data' => [
                        'task_id' => $replay->task->taskId,
                        'status' => $replay->task->status->value,
                        'latest_event_id' => $replay->task->latestEventSequence,
                        'requested_last_event_id' => $cursor,
                    ],
                    'control' => true,
                ];
            }

            if ($replay->resetRequired) {
                $snapshot = $replay->snapshotEvent;
                if ($snapshot === null) {
                    throw new FrontendQueryException('runtime_unavailable', 'Runtime task replay snapshot is unavailable.', 503);
                }
                yield [
                    'event' => 'runtime_reset',
                    'data' => [
                        'task_id' => $taskId,
                        'requested_last_event_id' => $cursor,
                        'compacted_before_sequence' => $replay->compactedBeforeSequence,
                    ],
                    'control' => true,
                ];
                yield $snapshot->toSseEvent();
                $cursor = $snapshot->sequence;
            }

            $emitted = false;
            foreach ($replay->events as $event) {
                if ($event->sequence <= $cursor) {
                    continue;
                }
                yield $event->toSseEvent();
                $cursor = $event->sequence;
                $emitted = true;
            }

            // A terminal task can still have more than one durable replay
            // page.  Do not close its stream until the client has observed
            // the task's persisted high-water sequence; otherwise a task
            // with more than EVENT_PAGE_SIZE events would lose its tail.
            if ($replay->isTerminal() && $cursor >= $replay->task->latestEventSequence) {
                return;
            }

            if ($replay->isTerminal() && !$emitted) {
                // A durable terminal task may not silently claim it has
                // events that its replay store cannot return.  Closing here
                // would permanently hide an event gap from a reconnecting
                // client, so surface a recoverable transport failure instead.
                throw new FrontendQueryException(
                    'runtime_unavailable',
                    'Runtime task event replay has a persistent gap.',
                    503,
                );
            }

            if (!$emitted) {
                // The Stream controller converts this private marker into a
                // throttled SSE comment, never an event or Last-Event-ID.
                yield ['transport' => 'heartbeat'];
                SchedulerSystem::yieldDelay($this->pollIntervalMilliseconds);
            }
        }
    }

    private function readReplay(
        string $taskId,
        string $leaseId,
        TaskOwner $owner,
        int $cursor,
    ): TaskEventReplay {
        if (!$this->runtime instanceof ResumableTaskEventStreamInterface) {
            throw new FrontendQueryException('runtime_unavailable', 'Resumable task event runtime is unavailable.', 503);
        }

        $replay = $this->runtimeCall(
            fn(): TaskEventReplay => $this->runtime->replay(
                $taskId,
                $leaseId,
                $owner,
                $cursor,
                self::EVENT_PAGE_SIZE,
            )
        );
        if (!$replay instanceof TaskEventReplay) {
            throw new FrontendQueryException('runtime_unavailable', 'Resumable task event runtime returned an invalid replay.', 503);
        }

        return $replay;
    }

    /** @param array<string,mixed> $params */
    private function resolveCursor(array $params): int
    {
        $value = \array_key_exists('last_event_id', $params)
            ? $params['last_event_id']
            : $this->request->getServer('HTTP_LAST_EVENT_ID');

        if ($value === null || $value === '') {
            return 0;
        }
        if (\is_int($value)) {
            if ($value >= 0) {
                return $value;
            }
            throw new FrontendQueryException('validation_error', 'Invalid runtime task event cursor.', 422);
        }
        if (!\is_string($value) || \preg_match('/^(?:0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new FrontendQueryException('validation_error', 'Invalid runtime task event cursor.', 422);
        }

        $max = (string)PHP_INT_MAX;
        if (\strlen($value) > \strlen($max)
            || (\strlen($value) === \strlen($max) && \strcmp($value, $max) > 0)) {
            throw new FrontendQueryException('validation_error', 'Invalid runtime task event cursor.', 422);
        }

        return (int)$value;
    }

    /** @param array<string,mixed> $params */
    private function requireIdentifier(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!\is_string($value)) {
            throw new FrontendQueryException('validation_error', 'Invalid runtime task identifier.', 422);
        }

        $value = \trim($value);
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $value) !== 1) {
            throw new FrontendQueryException('validation_error', 'Invalid runtime task identifier.', 422);
        }

        return $value;
    }

    /** @param array<string,mixed> $params */
    private function optionalReason(array $params): string
    {
        $reason = $params['reason'] ?? '';
        if (!\is_string($reason)) {
            throw new FrontendQueryException('validation_error', 'Invalid runtime task cancellation reason.', 422);
        }
        $reason = \trim($reason);
        if (\strlen($reason) > 2_000) {
            throw new FrontendQueryException('validation_error', 'Invalid runtime task cancellation reason.', 422);
        }

        return $reason;
    }

    private function resolveOwner(): TaskOwner
    {
        try {
            return $this->ownerResolver->resolve();
        } catch (ResumableTaskAccessDeniedException $exception) {
            throw new FrontendQueryException('not_found', 'Runtime task was not found.', 404, $exception);
        } catch (\Throwable $exception) {
            throw new FrontendQueryException('runtime_unavailable', 'Resumable task runtime is unavailable.', 503, $exception);
        }
    }

    private function runtimeCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ResumableTaskAccessDeniedException $exception) {
            throw new FrontendQueryException('not_found', 'Runtime task was not found.', 404, $exception);
        } catch (ResumableTaskRuntimeUnavailableException $exception) {
            throw new FrontendQueryException('runtime_unavailable', 'Resumable task runtime is unavailable.', 503, $exception);
        } catch (\Throwable $exception) {
            throw new FrontendQueryException('runtime_unavailable', 'Resumable task runtime is unavailable.', 503, $exception);
        }
    }

    private function assertOwnerMatches(TaskOwner $current, TaskOwner $stored): void
    {
        $matches = $current->area === $stored->area
            && $current->principal === $stored->principal
            && $current->websiteId === $stored->websiteId
            && $current->tenantId === $stored->tenantId
            && $current->acl === $stored->acl;

        // Anonymous work belongs to the session itself.  Authenticated work
        // remains attachable after a session rotation as long as its principal
        // and captured authorization scope still match.
        if ($matches && \str_starts_with($stored->principal, 'session:')) {
            $matches = $current->sessionId !== null
                && $stored->sessionId !== null
                && \hash_equals($stored->sessionId, $current->sessionId);
        }

        if (!$matches) {
            throw new FrontendQueryException('not_found', 'Runtime task was not found.', 404);
        }
    }

    /** @return array<string,mixed> */
    private function snapshotPayload(TaskSnapshot $snapshot): array
    {
        $checkpoint = $snapshot->checkpoint;

        return [
            'task_id' => $snapshot->taskId,
            'type_code' => $snapshot->typeCode,
            'status' => $snapshot->status->value,
            'attempt' => $snapshot->attempt,
            'max_attempts' => $snapshot->maxAttempts,
            'latest_event_id' => $snapshot->latestEventSequence,
            'checkpoint' => $checkpoint === null ? null : [
                'version' => $checkpoint->version,
                'cursor' => $checkpoint->cursor,
                'schema_version' => $checkpoint->schemaVersion,
                'created_at' => $checkpoint->createdAt,
            ],
            'result' => $snapshot->result?->toArray(),
            'error_code' => $snapshot->errorCode,
            'terminal_reason' => $snapshot->terminalReason,
            'created_at' => $snapshot->createdAt,
            'updated_at' => $snapshot->updatedAt,
            'completed_at' => $snapshot->completedAt,
        ];
    }
}
