<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Extends\Module\Weline_Framework\Query\ResumableTaskQueryProvider;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\Resumable\ResumableTaskEventStreamInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskRuntimeInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskEvent;
use Weline\Framework\Runtime\Resumable\TaskEventReplay;
use Weline\Framework\Runtime\Resumable\TaskHandle;
use Weline\Framework\Runtime\Resumable\TaskLease;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\TaskSnapshot;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Runtime\ResumableTaskOwnerResolver;

final class ResumableTaskQueryProviderTestRuntime implements ResumableTaskRuntimeInterface, ResumableTaskEventStreamInterface
{
    public ?TaskSnapshot $snapshot = null;
    public ?TaskLease $lease = null;
    public ?TaskEventReplay $replay = null;
    /** @var null|\Closure(string,string,TaskOwner,int,int):TaskEventReplay */
    public ?\Closure $replayCallback = null;
    public int $lastReplayCursor = -1;

    public function start(
        string $typeCode,
        array $input,
        TaskOwner $owner,
        TaskPolicy $policy,
        string $businessKey,
    ): TaskHandle {
        throw new \LogicException('Not used by the query bridge.');
    }

    public function status(string $taskId, TaskOwner $owner): TaskSnapshot
    {
        return $this->snapshot ?? throw new \LogicException('Missing test snapshot.');
    }

    public function renew(string $taskId, string $leaseId, TaskOwner $owner): TaskLease
    {
        return $this->lease ?? throw new \LogicException('Missing test lease.');
    }

    public function cancel(string $taskId, TaskOwner $owner, string $intentId, string $reason = ''): TaskSnapshot
    {
        return $this->snapshot ?? throw new \LogicException('Missing test snapshot.');
    }

    public function replay(
        string $taskId,
        string $leaseId,
        TaskOwner $owner,
        int $afterSequence,
        int $limit = 200,
    ): TaskEventReplay {
        $this->lastReplayCursor = $afterSequence;
        if ($this->replayCallback !== null) {
            return ($this->replayCallback)($taskId, $leaseId, $owner, $afterSequence, $limit);
        }
        return $this->replay ?? throw new \LogicException('Missing test replay.');
    }
}

final class ResumableTaskQueryProviderTestOwnerResolver extends ResumableTaskOwnerResolver
{
    public function __construct(private readonly TaskOwner $owner)
    {
    }

    public function resolve(): TaskOwner
    {
        return $this->owner;
    }
}

final class ResumableTaskQueryProviderTest extends TestCase
{
    public function testStatusAndTouchExposeOnlySafeOwnerScopedFields(): void
    {
        $owner = new TaskOwner('frontend', 'frontend:42', 'session-42', 0);
        $runtime = new ResumableTaskQueryProviderTestRuntime();
        $runtime->snapshot = $this->snapshot($owner, ResumableTaskStatus::RUNNING, 3);
        $runtime->lease = new TaskLease(
            leaseId: 'lease-00000001',
            taskId: 'task-00000001',
            owner: $owner,
            subscriptionId: 'tab-0001',
            lastSeenAt: 1_700_000_001,
            expiresAt: 1_700_000_601,
        );
        $provider = $this->provider($runtime, $owner);

        $status = $provider->execute('status', ['task_id' => 'task-00000001']);
        self::assertSame('running', $status['status']);
        self::assertSame(3, $status['latest_event_id']);
        self::assertArrayNotHasKey('owner', $status);
        self::assertArrayNotHasKey('input', $status);

        $touch = $provider->execute('touch', [
            'task_id' => 'task-00000001',
            'lease_id' => 'lease-00000001',
        ]);
        self::assertSame([
            'task_id' => 'task-00000001',
            'lease_id' => 'lease-00000001',
            'last_seen_at' => 1_700_000_001,
            'expires_at' => 1_700_000_601,
        ], $touch);
    }

    public function testEventsUsePersistentSequenceAndDoNotExecuteWork(): void
    {
        $owner = new TaskOwner('frontend', 'frontend:42', 'session-42', 0);
        $event = new TaskEvent(
            taskId: 'task-00000001',
            sequence: 3,
            event: 'completed',
            payload: ['status' => 'completed', 'website_id' => 0],
            attempt: 1,
            fencingGeneration: 1,
            createdAt: 1_700_000_010,
        );
        $runtime = new ResumableTaskQueryProviderTestRuntime();
        $runtime->snapshot = $this->snapshot($owner, ResumableTaskStatus::COMPLETED, 3);
        $runtime->replay = new TaskEventReplay(
            task: $runtime->snapshot,
            requestedAfterSequence: 2,
            events: [$event],
        );
        $provider = $this->provider($runtime, $owner);

        $events = \iterator_to_array($provider->execute('events', [
            'task_id' => 'task-00000001',
            'lease_id' => 'lease-00000001',
            'last_event_id' => 2,
        ]));

        self::assertSame(2, $runtime->lastReplayCursor);
        self::assertSame([
            'event' => 'runtime_open',
            'data' => [
                'task_id' => 'task-00000001',
                'status' => 'completed',
                'latest_event_id' => 3,
                'requested_last_event_id' => 2,
            ],
            'control' => true,
        ], $events[0]);
        self::assertSame($event->toSseEvent(), $events[1]);
        self::assertCount(2, $events);
    }

    public function testStaleCursorEmitsCursorFreeResetThenPersistedSnapshot(): void
    {
        $owner = new TaskOwner('frontend', 'frontend:42', 'session-42', 0);
        $snapshotEvent = new TaskEvent(
            taskId: 'task-00000001',
            sequence: 8,
            event: 'runtime_snapshot',
            payload: ['status' => 'running', 'completed_steps' => 8],
            attempt: 1,
            fencingGeneration: 1,
            createdAt: 1_700_000_010,
        );
        $terminalEvent = new TaskEvent(
            taskId: 'task-00000001',
            sequence: 9,
            event: 'completed',
            payload: ['status' => 'completed'],
            attempt: 1,
            fencingGeneration: 1,
            createdAt: 1_700_000_011,
        );
        $runtime = new ResumableTaskQueryProviderTestRuntime();
        $runtime->snapshot = $this->snapshot($owner, ResumableTaskStatus::COMPLETED, 9);
        $runtime->replay = new TaskEventReplay(
            task: $runtime->snapshot,
            requestedAfterSequence: 2,
            events: [$terminalEvent],
            resetRequired: true,
            compactedBeforeSequence: 7,
            snapshotEvent: $snapshotEvent,
        );
        $provider = $this->provider($runtime, $owner);

        $events = \iterator_to_array($provider->execute('events', [
            'task_id' => 'task-00000001',
            'lease_id' => 'lease-00000001',
            'last_event_id' => '2',
        ]));

        self::assertSame('runtime_open', $events[0]['event']);
        self::assertSame('runtime_reset', $events[1]['event']);
        self::assertTrue($events[1]['control']);
        self::assertArrayNotHasKey('id', $events[1]);
        self::assertSame($snapshotEvent->toSseEvent(), $events[2]);
        self::assertSame($terminalEvent->toSseEvent(), $events[3]);
    }

    public function testTerminalTaskReplaysEveryPersistentPageBeforeClosing(): void
    {
        $owner = new TaskOwner('frontend', 'frontend:42', 'session-42', 0);
        $runtime = new ResumableTaskQueryProviderTestRuntime();
        $runtime->snapshot = $this->snapshot($owner, ResumableTaskStatus::COMPLETED, 201);
        $allEvents = [];
        for ($sequence = 1; $sequence <= 201; $sequence++) {
            $allEvents[] = new TaskEvent(
                taskId: 'task-00000001',
                sequence: $sequence,
                event: $sequence === 201 ? 'completed' : 'progress',
                payload: ['step' => $sequence],
                attempt: 1,
                fencingGeneration: 1,
                createdAt: 1_700_000_000 + $sequence,
            );
        }
        $runtime->replayCallback = static function (
            string $taskId,
            string $leaseId,
            TaskOwner $resolvedOwner,
            int $afterSequence,
            int $limit,
        ) use ($runtime, $allEvents): TaskEventReplay {
            $page = array_slice(
                array_values(array_filter(
                    $allEvents,
                    static fn(TaskEvent $event): bool => $event->sequence > $afterSequence,
                )),
                0,
                $limit,
            );
            return new TaskEventReplay($runtime->snapshot, $afterSequence, $page);
        };
        $provider = $this->provider($runtime, $owner);

        $frames = iterator_to_array($provider->execute('events', [
            'task_id' => 'task-00000001',
            'lease_id' => 'lease-00000001',
            'last_event_id' => 0,
        ]));

        self::assertCount(202, $frames);
        self::assertSame('runtime_open', $frames[0]['event']);
        self::assertSame(1, $frames[1]['id']);
        self::assertSame(201, $frames[201]['id']);
        self::assertSame('completed', $frames[201]['event']);
        self::assertSame(200, $runtime->lastReplayCursor);
    }

    public function testResetRejectsSnapshotBeforeTheCompactionBoundary(): void
    {
        $owner = new TaskOwner('frontend', 'frontend:42', 'session-42', 0);
        $task = $this->snapshot($owner, ResumableTaskStatus::RUNNING, 9);
        $snapshotEvent = new TaskEvent(
            taskId: $task->taskId,
            sequence: 5,
            event: 'runtime_snapshot',
            payload: ['step' => 5],
            attempt: 1,
            fencingGeneration: 1,
            createdAt: 1_700_000_005,
        );

        $this->expectException(\InvalidArgumentException::class);
        new TaskEventReplay(
            task: $task,
            requestedAfterSequence: 2,
            events: [],
            resetRequired: true,
            compactedBeforeSequence: 6,
            snapshotEvent: $snapshotEvent,
        );
    }

    public function testReplayRejectsASequenceGapInsteadOfSilentlySkippingState(): void
    {
        $owner = new TaskOwner('frontend', 'frontend:42', 'session-42', 0);
        $task = $this->snapshot($owner, ResumableTaskStatus::RUNNING, 2);
        $event = new TaskEvent(
            taskId: $task->taskId,
            sequence: 2,
            event: 'progress',
            payload: ['step' => 2],
            attempt: 1,
            fencingGeneration: 1,
            createdAt: 1_700_000_002,
        );

        $this->expectException(\InvalidArgumentException::class);
        new TaskEventReplay($task, 0, [$event]);
    }

    public function testMismatchedOwnerAndMalformedCursorAreUniformlyRejected(): void
    {
        $owner = new TaskOwner('frontend', 'frontend:42', 'session-42', 0);
        $runtime = new ResumableTaskQueryProviderTestRuntime();
        $runtime->snapshot = $this->snapshot(
            new TaskOwner('frontend', 'frontend:77', 'session-77', 0),
            ResumableTaskStatus::RUNNING,
            0,
        );
        $provider = $this->provider($runtime, $owner);

        try {
            $provider->execute('status', ['task_id' => 'task-00000001']);
            self::fail('Expected the owner mismatch to be hidden as not found.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('not_found', $exception->getErrorCode());
            self::assertSame(404, $exception->getHttpStatus());
        }

        $runtime->snapshot = $this->snapshot($owner, ResumableTaskStatus::COMPLETED, 0);
        $runtime->replay = new TaskEventReplay(
            task: $runtime->snapshot,
            requestedAfterSequence: 0,
            events: [],
        );

        $this->expectException(FrontendQueryException::class);
        $this->expectExceptionMessage('Invalid runtime task event cursor.');
        \iterator_to_array($provider->execute('events', [
            'task_id' => 'task-00000001',
            'lease_id' => 'lease-00000001',
            'last_event_id' => '-1',
        ]));
    }

    private function provider(
        ResumableTaskQueryProviderTestRuntime $runtime,
        TaskOwner $owner,
    ): ResumableTaskQueryProvider {
        return new ResumableTaskQueryProvider(
            runtime: $runtime,
            ownerResolver: new ResumableTaskQueryProviderTestOwnerResolver($owner),
            request: new Request(),
            pollIntervalMilliseconds: 1,
            subscriptionSeconds: 1,
        );
    }

    private function snapshot(TaskOwner $owner, ResumableTaskStatus $status, int $latestEventId): TaskSnapshot
    {
        $terminal = $status->isTerminal();

        return new TaskSnapshot(
            taskId: 'task-00000001',
            typeCode: 'websites.site_build',
            status: $status,
            owner: $owner,
            policy: TaskPolicy::defaults(),
            attempt: $status === ResumableTaskStatus::STARTING ? 0 : 1,
            maxAttempts: 4,
            fencingGeneration: $status === ResumableTaskStatus::STARTING ? 0 : 1,
            checkpoint: null,
            latestEventSequence: $latestEventId,
            result: $terminal ? TaskResult::completed(['website_id' => 0]) : null,
            errorCode: null,
            terminalReason: '',
            createdAt: 1_700_000_000,
            updatedAt: 1_700_000_001,
            completedAt: $terminal ? 1_700_000_001 : null,
        );
    }
}
