<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\System\Process\Processer;
use Weline\Queue\DeadWorkerRecoverableQueueInterface;
use Weline\Queue\DeadWorkerRecoveryPatchQueueInterface;
use Weline\Queue\Model\Queue;
use Weline\Queue\Service\QueueDispatchService;
use Weline\Queue\Service\QueueScopeProducerMapping;

final class QueueDispatchServiceControlTest extends TestCase
{
    private const TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TOKEN_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testPidZeroStopWinsFenceBeforeLateAttach(): void
    {
        $service = new InMemoryQueueDispatchService($this->row(pid: 0, token: self::TOKEN_A));

        $result = $service->stopQueueSafely(77);

        self::assertTrue($result['confirmed']);
        self::assertSame(Queue::status_stop, $service->row[Queue::schema_fields_status]);
        self::assertSame(0, $service->row[Queue::schema_fields_pid]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertCount(1, $service->updateCalls);
        self::assertSame(self::TOKEN_A, $service->updateCalls[0]['expected'][Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame(0, $service->updateCalls[0]['expected'][Queue::schema_fields_pid]);
        self::assertSame([], $service->managedTerminationCalls);
        self::assertSame([], $service->probeCalls);
        self::assertFalse($service->rowMatches([
            Queue::schema_fields_ID => 77,
            Queue::schema_fields_status => Queue::status_running,
            Queue::schema_fields_pid => 0,
            Queue::schema_fields_DISPATCH_TOKEN => self::TOKEN_A,
        ]), 'A late Worker attach must lose after the stop CAS clears its fence.');
    }

    public function testPidZeroAttachWinnerIsReloadedAndSameGenerationIsTerminated(): void
    {
        $service = new InMemoryQueueDispatchService($this->row(pid: 0, token: self::TOKEN_A));
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_pid] = 9001;
            $service->row[Queue::schema_fields_DISPATCH_UNTIL] = null;
        };
        $service->managedTerminationResult = [
            'state' => Processer::PROCESS_STATE_EXITED,
            'reason' => 'process_exited',
            'released' => true,
            'terminated' => true,
        ];

        $result = $service->stopQueueSafely(77);

        self::assertTrue($result['confirmed']);
        self::assertTrue($result['terminated']);
        self::assertCount(2, $service->updateCalls);
        self::assertCount(1, $service->managedTerminationCalls);
        self::assertSame(9001, $service->managedTerminationCalls[0]['pid']);
        self::assertSame(self::TOKEN_A, $service->managedTerminationCalls[0]['expected_launch_id']);
        self::assertSame(Queue::status_stop, $service->row[Queue::schema_fields_status]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
    }

    public function testUnknownManagedIdentityFailsClosedWithoutStateWrite(): void
    {
        $original = $this->row(pid: 9001, token: self::TOKEN_A);
        $service = new InMemoryQueueDispatchService($original);
        $service->managedTerminationResult = [
            'state' => Processer::PROCESS_STATE_UNKNOWN,
            'reason' => 'live_identity_unavailable',
            'released' => false,
            'terminated' => false,
        ];

        $result = $service->stopQueueSafely(77);

        self::assertFalse($result['confirmed']);
        self::assertTrue($result['retryable']);
        self::assertSame('queue_identity_unavailable', $result['error_code']);
        self::assertSame($original, $service->row);
        self::assertSame([], $service->updateCalls);
        self::assertSame([], $service->leaseRemovalCalls);
    }

    public function testExternalControlAndDispatchFailClosedInsideOuterTransaction(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_pending, pid: 0, token: null),
        );
        $service->activeQueueTransaction = true;

        $stop = $service->stopQueueSafely(77);
        $takeover = $service->takeoverQueueSafely(77, true);
        $delete = $service->deleteQueueSafely(77, true);
        $manual = $service->claimQueueForManualRun(77, 4242);
        $requeue = $service->requeueQueueSafely(77);
        $terminate = $service->terminateClaimedQueue(77, self::TOKEN_A);
        $dispatch = $service->dispatchQueueIfEligible($service->queueSnapshot());

        foreach ([$stop, $takeover, $delete, $manual, $requeue, $terminate] as $result) {
            self::assertFalse($result['confirmed']);
            self::assertSame('queue_transaction_active', $result['error_code']);
            self::assertTrue($result['retryable']);
        }
        self::assertFalse($dispatch);
        self::assertSame([], $service->managedTerminationCalls);
        self::assertSame([], $service->updateCalls);
        self::assertSame([], $service->deleteCalls);
    }

    public function testTokenlessLiveWorkerFailsClosedForStopTakeoverAndForceDelete(): void
    {
        foreach (['stop', 'takeover', 'delete'] as $action) {
            $original = $this->row(pid: 9001, token: null);
            $service = new InMemoryQueueDispatchService($original);
            $service->probeState = Processer::PROCESS_STATE_RUNNING;

            $result = match ($action) {
                'stop' => $service->stopQueueSafely(77),
                'takeover' => $service->takeoverQueueSafely(77, true),
                'delete' => $service->deleteQueueSafely(77, true),
            };

            self::assertFalse($result['confirmed'], $action);
            self::assertSame('queue_process_unmanaged', $result['error_code'], $action);
            self::assertSame($original, $service->row, $action);
            self::assertSame([], $service->managedTerminationCalls, $action);
            self::assertSame([], $service->updateCalls, $action);
            self::assertSame([], $service->deleteCalls, $action);
        }
    }

    public function testIdentityMismatchReleasesStaleFenceWithoutSignal(): void
    {
        $service = new InMemoryQueueDispatchService($this->row(pid: 9001, token: self::TOKEN_A));
        $service->managedTerminationResult = [
            'state' => Processer::PROCESS_STATE_IDENTITY_MISMATCH,
            'reason' => 'live_required_argument_mismatch',
            'released' => true,
            'terminated' => false,
        ];

        $result = $service->stopQueueSafely(77);

        self::assertTrue($result['confirmed']);
        self::assertFalse($result['terminated']);
        self::assertTrue($result['released_without_signal']);
        self::assertSame(Queue::status_stop, $service->row[Queue::schema_fields_status]);
        self::assertCount(1, $service->leaseRemovalCalls);
    }

    public function testMatchedIdentityTerminatesOnceWithAllRequiredArguments(): void
    {
        $service = new InMemoryQueueDispatchService($this->row(pid: 9001, token: self::TOKEN_A));
        $service->managedTerminationResult = [
            'state' => Processer::PROCESS_STATE_EXITED,
            'reason' => 'process_exited',
            'released' => true,
            'terminated' => true,
        ];

        $result = $service->stopQueueSafely(77);

        self::assertTrue($result['confirmed']);
        self::assertTrue($result['terminated']);
        self::assertCount(1, $service->managedTerminationCalls);
        self::assertSame([
            'id' => '77',
            'name' => 'queue-demo-77',
            'launch-id' => self::TOKEN_A,
            'dispatch-token' => self::TOKEN_A,
        ], $service->managedTerminationCalls[0]['required_live_arguments']);
        self::assertSame('--name=queue-demo-77 --launch-id=' . self::TOKEN_A, $service->managedTerminationCalls[0]['expected_pname']);
    }

    public function testCasConflictAfterTerminationPreservesNewGeneration(): void
    {
        $service = new InMemoryQueueDispatchService($this->row(pid: 9001, token: self::TOKEN_A));
        $service->managedTerminationResult = [
            'state' => Processer::PROCESS_STATE_EXITED,
            'reason' => 'process_exited',
            'released' => true,
            'terminated' => true,
        ];
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_pid] = 9002;
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
            $service->row[Queue::schema_fields_result] = 'new-result';
            $service->row[Queue::schema_fields_process] = 'new-process';
        };

        $result = $service->stopQueueSafely(77);

        self::assertFalse($result['confirmed']);
        self::assertTrue($result['retryable']);
        self::assertSame('queue_state_changed', $result['error_code']);
        self::assertCount(1, $service->managedTerminationCalls);
        self::assertSame(9002, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame('new-result', $service->row[Queue::schema_fields_result]);
        self::assertSame('new-process', $service->row[Queue::schema_fields_process]);
    }

    public function testTakeoverCasIncludesDerivedContentAndProcess(): void
    {
        $service = new InMemoryQueueDispatchService($this->row(
            status: Queue::status_pending,
            pid: 0,
            token: null,
            content: '{"job":1}',
            process: 'old-process',
        ));
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_content] = '{"job":2}';
            $service->row[Queue::schema_fields_process] = 'concurrent-process';
        };

        $result = $service->takeoverQueueSafely(77, true);

        self::assertFalse($result['confirmed']);
        self::assertSame('queue_state_changed', $result['error_code']);
        self::assertSame('{"job":2}', $service->row[Queue::schema_fields_content]);
        self::assertSame('concurrent-process', $service->row[Queue::schema_fields_process]);
        self::assertArrayHasKey(Queue::schema_fields_content, $service->updateCalls[0]['expected']);
        self::assertArrayHasKey(Queue::schema_fields_process, $service->updateCalls[0]['expected']);
    }

    public function testIdleDeleteCannotDeleteConcurrentClaim(): void
    {
        $service = new InMemoryQueueDispatchService($this->row(
            status: Queue::status_pending,
            pid: 0,
            token: null,
        ));
        $service->beforeNextDelete = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_status] = Queue::status_running;
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
            $service->row[Queue::schema_fields_DISPATCH_UNTIL] = '2099-01-01 00:00:00';
        };

        $result = $service->deleteQueueSafely(77, false);

        self::assertFalse($result['confirmed']);
        self::assertSame('queue_state_changed', $result['error_code']);
        self::assertNotNull($service->row);
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
    }

    public function testIdleDeleteCannotEmitStaleSnapshotAfterConcurrentPendingEdit(): void
    {
        $service = new InMemoryQueueDispatchService($this->row(
            status: Queue::status_pending,
            pid: 0,
            token: null,
        ));
        $service->beforeNextDelete = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_name] = 'renamed';
            $service->row[Queue::schema_fields_module] = 'Weline_Changed';
            $service->row[Queue::schema_fields_BIZ_KEY] = 'changed-key';
            $service->row[Queue::schema_fields_content] = '{"job":2}';
        };

        $result = $service->deleteQueueSafely(77, false);

        self::assertFalse($result['confirmed']);
        self::assertSame('queue_state_changed', $result['error_code']);
        self::assertSame('renamed', $service->row[Queue::schema_fields_name]);
        self::assertSame('Weline_Changed', $service->row[Queue::schema_fields_module]);
        self::assertSame('changed-key', $service->row[Queue::schema_fields_BIZ_KEY]);
        self::assertSame('{"job":2}', $service->row[Queue::schema_fields_content]);
        self::assertArrayHasKey(Queue::schema_fields_module, $service->deleteCalls[0]);
        self::assertArrayHasKey(Queue::schema_fields_BIZ_KEY, $service->deleteCalls[0]);
    }

    public function testTerminateClaimedQueueDoesNotConfirmWhenFinalCasLoses(): void
    {
        $service = new InMemoryQueueDispatchService($this->row(pid: 9001, token: self::TOKEN_A));
        $service->managedTerminationResult = [
            'state' => Processer::PROCESS_STATE_EXITED,
            'reason' => 'process_exited',
            'released' => true,
            'terminated' => true,
        ];
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_pid] = 9002;
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
        };

        $result = $service->terminateClaimedQueue(77, self::TOKEN_A);

        self::assertFalse($result['confirmed']);
        self::assertTrue($result['retryable']);
        self::assertSame('queue_state_changed', $result['error_code']);
        self::assertSame(9002, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
    }

    public function testRequeueFailsClosedForEveryActiveOrDirtyFenceShape(): void
    {
        $rows = [];
        $rows[] = $this->row(status: Queue::status_running, pid: 0, token: null);
        $rows[] = $this->row(status: Queue::status_pending, pid: 0, token: self::TOKEN_A);
        $rows[] = $this->row(status: Queue::status_stop, pid: 9001, token: null);
        $dirtyUntil = $this->row(status: Queue::status_error, pid: 0, token: null);
        $dirtyUntil[Queue::schema_fields_DISPATCH_UNTIL] = '2099-01-01 00:00:00';
        $rows[] = $dirtyUntil;

        foreach ($rows as $row) {
            $service = new InMemoryQueueDispatchService($row);
            $result = $service->requeueQueueSafely(77);

            self::assertFalse($result['confirmed']);
            self::assertSame($row, $service->row);
            self::assertSame([], $service->updateCalls);
            self::assertSame([], $service->probeCalls);
            self::assertSame([], $service->managedTerminationCalls);
        }
    }

    public function testCleanTerminalQueueRequeuesWithExactFenceCas(): void
    {
        $row = $this->row(status: Queue::status_stop, pid: 0, token: null);
        $row[Queue::schema_fields_finished] = 1;
        $service = new InMemoryQueueDispatchService($row);

        $result = $service->requeueQueueSafely(77);

        self::assertTrue($result['confirmed']);
        self::assertSame(Queue::status_pending, $service->row[Queue::schema_fields_status]);
        self::assertSame(0, $service->row[Queue::schema_fields_finished]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_UNTIL]);
        self::assertSame(0, $service->row[Queue::schema_fields_pid]);
        self::assertSame(1, $service->updateCalls[0]['expected'][Queue::schema_fields_finished]);
        self::assertArrayHasKey(Queue::schema_fields_DISPATCH_UNTIL, $service->updateCalls[0]['expected']);
        self::assertSame([], $service->probeCalls);
    }

    public function testConcurrentClaimWinsAgainstRequeueWithoutBeingOverwritten(): void
    {
        $row = $this->row(status: Queue::status_stop, pid: 0, token: null);
        $row[Queue::schema_fields_finished] = 1;
        $service = new InMemoryQueueDispatchService($row);
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_status] = Queue::status_running;
            $service->row[Queue::schema_fields_finished] = 0;
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
            $service->row[Queue::schema_fields_DISPATCH_UNTIL] = '2099-01-01 00:00:00';
        };

        $result = $service->requeueQueueSafely(77);

        self::assertFalse($result['confirmed']);
        self::assertSame('queue_state_changed', $result['error_code']);
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
    }

    public function testPendingEditRejectsEveryControlFieldBeforeWrite(): void
    {
        foreach ([
            Queue::schema_fields_status,
            Queue::schema_fields_pid,
            Queue::schema_fields_finished,
            Queue::schema_fields_DISPATCH_TOKEN,
            Queue::schema_fields_DISPATCH_UNTIL,
        ] as $field) {
            $service = new InMemoryQueueDispatchService(
                $this->row(status: Queue::status_pending, pid: 0, token: null),
            );

            $result = $service->updatePendingQueueSafely(77, [$field => null]);

            self::assertFalse($result['confirmed'], $field);
            self::assertSame('queue_edit_field_forbidden', $result['error_code'], $field);
            self::assertSame([], $service->updateCalls, $field);
        }
    }

    public function testAllowedPendingEditUsesFullSnapshotCas(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_pending, pid: 0, token: null),
        );

        $result = $service->updatePendingQueueSafely(77, [
            Queue::schema_fields_name => 'renamed',
            Queue::schema_fields_content => '{"job":2}',
        ]);

        self::assertTrue($result['confirmed']);
        self::assertSame('renamed', $service->row[Queue::schema_fields_name]);
        $expected = $service->updateCalls[0]['expected'];
        self::assertSame(Queue::status_pending, $expected[Queue::schema_fields_status]);
        self::assertSame(0, $expected[Queue::schema_fields_pid]);
        self::assertNull($expected[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertNull($expected[Queue::schema_fields_DISPATCH_UNTIL]);
        self::assertSame('demo', $expected[Queue::schema_fields_name]);
        self::assertSame('{"job":1}', $expected[Queue::schema_fields_content]);
    }

    public function testOwnedRunningWorkerCanPersistTelemetryWithoutChangingFence(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A),
        );

        $result = $service->updateQueueWorkerTelemetrySafely(77, self::TOKEN_A, 9001, [
            Queue::schema_fields_content => '{"job":1,"progress":42}',
            Queue::schema_fields_result => 'planning 4/8',
            Queue::schema_fields_process => 'stage1_page_progress',
        ]);

        self::assertTrue($result['confirmed']);
        self::assertSame('{"job":1,"progress":42}', $service->row[Queue::schema_fields_content]);
        self::assertSame('planning 4/8', $service->row[Queue::schema_fields_result]);
        self::assertSame('stage1_page_progress', $service->row[Queue::schema_fields_process]);
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(9001, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_A, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame(
            self::TOKEN_A,
            $service->updateCalls[0]['expected'][Queue::schema_fields_DISPATCH_TOKEN],
        );
    }

    public function testWorkerTelemetryRejectsControlFieldsAndLostFence(): void
    {
        $original = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $service = new InMemoryQueueDispatchService($original);

        $forbidden = $service->updateQueueWorkerTelemetrySafely(77, self::TOKEN_A, 9001, [
            Queue::schema_fields_status => Queue::status_done,
        ]);
        $lostFence = $service->updateQueueWorkerTelemetrySafely(77, self::TOKEN_B, 9001, [
            Queue::schema_fields_result => 'stale progress',
        ]);

        self::assertFalse($forbidden['confirmed']);
        self::assertSame('queue_worker_telemetry_field_forbidden', $forbidden['error_code']);
        self::assertFalse($lostFence['confirmed']);
        self::assertSame('queue_worker_fence_lost', $lostFence['error_code']);
        self::assertSame($original, $service->row);
        self::assertSame([], $service->updateCalls);
    }

    public function testWorkerTelemetryCasConflictCannotOverwriteNewGeneration(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A),
        );
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_pid] = 9002;
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
            $service->row[Queue::schema_fields_result] = 'new-generation-progress';
        };

        $result = $service->updateQueueWorkerTelemetrySafely(77, self::TOKEN_A, 9001, [
            Queue::schema_fields_result => 'stale-generation-progress',
        ]);

        self::assertFalse($result['confirmed']);
        self::assertSame('queue_state_changed', $result['error_code']);
        self::assertSame(9002, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame('new-generation-progress', $service->row[Queue::schema_fields_result]);
    }

    public function testEditWinsThenStaleClaimLosesOnProcessIdentity(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_pending, pid: 0, token: null),
        );
        $staleSnapshot = $service->queueSnapshot();

        $edit = $service->updatePendingQueueSafely(77, [Queue::schema_fields_name => 'renamed']);
        $claimed = $service->claimForDispatch($staleSnapshot);

        self::assertTrue($edit['confirmed']);
        self::assertNull($claimed);
        self::assertSame(Queue::status_pending, $service->row[Queue::schema_fields_status]);
        self::assertSame('renamed', $service->row[Queue::schema_fields_name]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
    }

    public function testClaimWinsThenPendingEditLoses(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_pending, pid: 0, token: null),
        );

        $claimed = $service->claimForDispatch($service->queueSnapshot());
        $edit = $service->updatePendingQueueSafely(77, [Queue::schema_fields_name => 'renamed']);

        self::assertInstanceOf(Queue::class, $claimed);
        self::assertFalse($edit['confirmed']);
        self::assertSame('queue_edit_active', $edit['error_code']);
        self::assertSame('demo', $service->row[Queue::schema_fields_name]);
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertNotEmpty($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
    }

    public function testDirtyPendingFenceCannotDispatchOrNonForceDelete(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_pending, pid: 0, token: self::TOKEN_A),
        );

        $claimed = $service->claimForDispatch($service->queueSnapshot());
        $deleted = $service->deleteQueueSafely(77, false);

        self::assertNull($claimed);
        self::assertFalse($deleted['confirmed']);
        self::assertSame('queue_force_required', $deleted['error_code']);
        self::assertSame(self::TOKEN_A, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
    }

    public function testManualClaimWinsThenStaleSchedulerClaimLoses(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_pending, pid: 0, token: null),
        );
        $staleSchedulerSnapshot = $service->queueSnapshot();

        $manual = $service->claimQueueForManualRun(77, 4242);
        $scheduler = $service->claimForDispatch($staleSchedulerSnapshot);

        self::assertTrue($manual['confirmed']);
        self::assertNull($scheduler);
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(4242, $service->row[Queue::schema_fields_pid]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
    }

    public function testSchedulerClaimWinsThenManualClaimLoses(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_pending, pid: 0, token: null),
        );

        $scheduler = $service->claimForDispatch($service->queueSnapshot());
        $manual = $service->claimQueueForManualRun(77, 4242);

        self::assertInstanceOf(Queue::class, $scheduler);
        self::assertFalse($manual['confirmed']);
        self::assertSame('queue_manual_claim_unavailable', $manual['error_code']);
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertNotEmpty($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
    }

    public function testQuarantinedTerminalRowCannotBeReopenedByManualClaim(): void
    {
        $row = $this->row(status: Queue::status_stop, pid: 0, token: null);
        $row[Queue::schema_fields_finished] = 1;
        $row[Queue::schema_fields_result] = QueueScopeProducerMapping::QUARANTINE_RESULT_PREFIX
            . ' unprovable';
        $service = new InMemoryQueueDispatchService($row);

        $claimed = $service->claimQueueForManualRun(77, 4242);

        self::assertFalse($claimed['confirmed']);
        self::assertFalse($claimed['retryable']);
        self::assertSame('queue_scope_quarantined', $claimed['error_code']);
        self::assertSame($row, $service->row);
        self::assertSame([], $service->updateCalls);
    }

    public function testManualForcePreparationAndClaimUseOneCas(): void
    {
        $service = new InMemoryQueueDispatchService(
            $this->row(status: Queue::status_pending, pid: 0, token: null),
        );

        $claimed = $service->claimQueueForManualRun(77, 4242, [], true);

        self::assertTrue($claimed['confirmed']);
        self::assertTrue($claimed['force_prepared']);
        self::assertCount(1, $service->updateCalls);
        $updates = $service->updateCalls[0]['updates'];
        self::assertSame(Queue::status_running, $updates[Queue::schema_fields_status]);
        self::assertSame(4242, $updates[Queue::schema_fields_pid]);
        self::assertSame('{"job":1,"_force_rebuild":1}', $updates[Queue::schema_fields_content]);
        self::assertSame('', $updates[Queue::schema_fields_result]);
    }

    public function testTerminalManualForcePreparationAndClaimUseOneCas(): void
    {
        $row = $this->row(status: Queue::status_done, pid: 0, token: null);
        $row[Queue::schema_fields_finished] = 1;
        $service = new InMemoryQueueDispatchService($row);

        $claimed = $service->claimQueueForManualRun(77, 4242, [], true);

        self::assertTrue($claimed['confirmed']);
        self::assertTrue($claimed['force_prepared']);
        self::assertCount(1, $service->updateCalls);
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(0, $service->row[Queue::schema_fields_finished]);
        self::assertSame(4242, $service->row[Queue::schema_fields_pid]);
        self::assertSame('{"job":1,"_force_rebuild":1}', $service->row[Queue::schema_fields_content]);
        self::assertSame('', $service->row[Queue::schema_fields_result]);
        self::assertSame('', $service->row[Queue::schema_fields_process]);
    }

    public function testConcurrentSchedulerGenerationBeatsTerminalManualForceWithoutStalePreparation(): void
    {
        $row = $this->row(status: Queue::status_error, pid: 0, token: null);
        $service = new InMemoryQueueDispatchService($row);
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_status] = Queue::status_running;
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
            $service->row[Queue::schema_fields_DISPATCH_UNTIL] = '2099-01-01 00:00:00';
            $service->row[Queue::schema_fields_content] = '{"job":2}';
            $service->row[Queue::schema_fields_result] = 'scheduler-result';
            $service->row[Queue::schema_fields_process] = 'scheduler-process';
        };

        $claimed = $service->claimQueueForManualRun(77, 4242, [], true);

        self::assertFalse($claimed['confirmed']);
        self::assertSame('queue_state_changed', $claimed['error_code']);
        self::assertCount(1, $service->updateCalls);
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame('{"job":2}', $service->row[Queue::schema_fields_content]);
        self::assertSame('scheduler-result', $service->row[Queue::schema_fields_result]);
        self::assertSame('scheduler-process', $service->row[Queue::schema_fields_process]);
    }

    public function testTransportRequeueRequiresExactErrorGenerationFence(): void
    {
        $row = $this->row(status: Queue::status_error, pid: 0, token: self::TOKEN_A);
        $row[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($row);
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
            $service->row[Queue::schema_fields_result] = 'new-error';
        };

        $requeued = $service->requeueTransportAttempt(77, self::TOKEN_A);

        self::assertFalse($requeued);
        self::assertSame(Queue::status_error, $service->row[Queue::schema_fields_status]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame('new-error', $service->row[Queue::schema_fields_result]);
    }

    public function testTransportActivationRequiresCleanPendingFence(): void
    {
        $dirty = $this->row(status: Queue::status_pending, pid: 0, token: self::TOKEN_A);
        $dirty[Queue::schema_fields_auto] = 0;
        $dirtyService = new InMemoryQueueDispatchService($dirty);
        self::assertFalse($dirtyService->activateTransportAttempt(77));
        self::assertSame([], $dirtyService->updateCalls);

        $clean = $this->row(status: Queue::status_pending, pid: 0, token: null);
        $clean[Queue::schema_fields_auto] = 0;
        $cleanService = new InMemoryQueueDispatchService($clean);
        self::assertTrue($cleanService->activateTransportAttempt(77));
        self::assertSame(1, $cleanService->row[Queue::schema_fields_auto]);
        self::assertNull($cleanService->updateCalls[0]['expected'][Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertNull($cleanService->updateCalls[0]['expected'][Queue::schema_fields_DISPATCH_UNTIL]);
    }

    public function testDetachedSpawnExceptionImmediatelyCompensatesClaim(): void
    {
        $row = $this->row(status: Queue::status_pending, pid: 0, token: null);
        $service = new InMemoryQueueDispatchService($row);
        $service->spawnFailure = new \RuntimeException('spawn-boom');

        $dispatched = $service->dispatchQueueIfEligible($service->queueSnapshot());

        self::assertFalse($dispatched);
        self::assertSame(Queue::status_pending, $service->row[Queue::schema_fields_status]);
        self::assertSame(0, $service->row[Queue::schema_fields_pid]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_UNTIL]);
        self::assertStringContainsString('spawn-boom', $service->row[Queue::schema_fields_process]);
        self::assertCount(2, $service->updateCalls);
    }

    public function testReconcileOldGenerationCannotOverwriteConcurrentNewGeneration(): void
    {
        $old = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $old[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($old);
        $service->runningRows = [$old];
        $service->probeState = Processer::PROCESS_STATE_EXITED;
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_pid] = 9002;
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
            $service->row[Queue::schema_fields_result] = 'new-result';
            $service->row[Queue::schema_fields_process] = 'new-process';
        };

        $service->reconcileRunningQueues();

        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(9002, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame('new-result', $service->row[Queue::schema_fields_result]);
        self::assertSame('new-process', $service->row[Queue::schema_fields_process]);
        self::assertCount(1, $service->updateCalls);
        self::assertSame(self::TOKEN_A, $service->leaseRemovalCalls[0]['expected_launch_id']);
    }

    public function testReconcileCannotOverwriteConcurrentFinishedDecision(): void
    {
        $old = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $old[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($old);
        $service->runningRows = [$old];
        $service->probeState = Processer::PROCESS_STATE_EXITED;
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_finished] = 1;
        };

        $service->reconcileRunningQueues();

        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(1, $service->row[Queue::schema_fields_finished]);
        self::assertSame(9001, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_A, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame(0, $service->updateCalls[0]['expected'][Queue::schema_fields_finished]);
    }

    public function testReconcileCannotOverwriteConcurrentBusinessDecisionInput(): void
    {
        $old = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $old[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($old);
        $service->runningRows = [$old];
        $service->probeState = Processer::PROCESS_STATE_EXITED;
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_content] = '{"job":2}';
        };

        $service->reconcileRunningQueues();

        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame('{"job":2}', $service->row[Queue::schema_fields_content]);
        self::assertSame(9001, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_A, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame('{"job":1}', $service->updateCalls[0]['expected'][Queue::schema_fields_content]);
        self::assertSame('demo', $service->updateCalls[0]['expected'][Queue::schema_fields_name]);
        self::assertSame(1, $service->updateCalls[0]['expected'][Queue::schema_fields_type_id]);
    }

    public function testCanonicalLeaseFinalizerDoesNotNeedQueueRow(): void
    {
        $service = new InMemoryQueueDispatchService($this->row());
        $service->row = null;

        $released = $service->releaseClaimedWorkerLeaseByTaskName(
            77,
            self::TOKEN_A,
            9001,
            'queue-demo-77',
        );

        self::assertTrue($released);
        self::assertSame(0, $service->loadCalls);
        self::assertSame('queue-demo-77', $service->leaseRemovalCalls[0]['expected_process_name']);
        self::assertSame(self::TOKEN_A, $service->leaseRemovalCalls[0]['expected_launch_id']);
    }

    public function testTerminationSignalWithoutConfirmedReleaseNeverWritesOrCleansLease(): void
    {
        $original = $this->row(pid: 9001, token: self::TOKEN_A);
        $service = new InMemoryQueueDispatchService($original);
        $service->managedTerminationResult = [
            'state' => Processer::PROCESS_STATE_RUNNING,
            'reason' => 'termination_failed_process_running',
            'released' => false,
            'terminated' => true,
        ];

        $result = $service->stopQueueSafely(77);

        self::assertFalse($result['confirmed']);
        self::assertSame('queue_termination_unconfirmed', $result['error_code']);
        self::assertSame($original, $service->row);
        self::assertSame([], $service->updateCalls);
        self::assertSame([], $service->leaseRemovalCalls);
    }

    public function testWorkerTerminalCasCannotOverwriteConcurrentNewGeneration(): void
    {
        $old = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $old[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($old);
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_pid] = 9002;
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
            $service->row[Queue::schema_fields_result] = 'new-result';
            $service->row[Queue::schema_fields_process] = 'new-process';
        };

        $result = $service->completeQueueWorkerSafely(77, self::TOKEN_A, 9001, 'old-done');

        self::assertFalse($result['confirmed']);
        self::assertSame('queue_state_changed', $result['error_code']);
        self::assertSame(9002, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame('new-result', $service->row[Queue::schema_fields_result]);
        self::assertSame('new-process', $service->row[Queue::schema_fields_process]);
    }

    public function testWorkerCompletionPreservesOwnedRetryStateAndClearsFence(): void
    {
        $row = $this->row(status: Queue::status_pending, pid: 9001, token: self::TOKEN_A);
        $row[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($row);

        $result = $service->completeQueueWorkerSafely(77, self::TOKEN_A, 9001, 'retry-later');

        self::assertTrue($result['confirmed']);
        self::assertSame(Queue::status_pending, $service->row[Queue::schema_fields_status]);
        self::assertSame(0, $service->row[Queue::schema_fields_finished]);
        self::assertSame(0, $service->row[Queue::schema_fields_pid]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertStringContainsString('retry-later', $service->row[Queue::schema_fields_result]);
        self::assertCount(1, $service->leaseRemovalCalls);
        self::assertSame(self::TOKEN_A, $service->leaseRemovalCalls[0]['expected_launch_id']);
    }

    public function testWorkerDeferredCompletionAtomicallyReturnsCleanPendingToScheduler(): void
    {
        $row = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $row[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($row);

        $result = $service->deferQueueWorkerSafely(
            77,
            self::TOKEN_A,
            9001,
            '{"job":1,"wait":{"checks":1}}',
            'waiting for authorization',
            '{"status":"pending"}',
            '2099-01-01 00:00:05',
        );

        self::assertTrue($result['confirmed']);
        self::assertSame(Queue::status_pending, $service->row[Queue::schema_fields_status]);
        self::assertSame(0, $service->row[Queue::schema_fields_finished]);
        self::assertSame(0, $service->row[Queue::schema_fields_pid]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_UNTIL]);
        self::assertSame(
            '2099-01-01 00:00:05',
            $service->row[Queue::schema_fields_NOT_BEFORE]
        );
        self::assertNull($service->row[Queue::schema_fields_start_at]);
        self::assertNull($service->row[Queue::schema_fields_end_at]);
        self::assertSame(
            '{"job":1,"wait":{"checks":1}}',
            $service->row[Queue::schema_fields_content]
        );
        self::assertStringContainsString(
            'waiting for authorization',
            $service->row[Queue::schema_fields_process]
        );
        self::assertStringContainsString(
            '{"status":"pending"}',
            $service->row[Queue::schema_fields_result]
        );
        self::assertCount(1, $service->leaseRemovalCalls);
        self::assertSame(self::TOKEN_A, $service->leaseRemovalCalls[0]['expected_launch_id']);
    }

    public function testDeferredCompletionCrashTakeoverRaceCannotLeaveOldPendingFence(): void
    {
        $old = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $old[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($old);
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_status] = Queue::status_running;
            $service->row[Queue::schema_fields_pid] = 9002;
            $service->row[Queue::schema_fields_DISPATCH_TOKEN] = self::TOKEN_B;
            $service->row[Queue::schema_fields_content] = '{"job":2}';
            $service->row[Queue::schema_fields_result] = 'new-result';
            $service->row[Queue::schema_fields_process] = 'new-process';
        };

        $result = $service->deferQueueWorkerSafely(
            77,
            self::TOKEN_A,
            9001,
            '{"job":1,"wait":{"checks":1}}',
            'old-wait',
            '{"status":"pending"}',
            '2099-01-01 00:00:05',
        );

        self::assertFalse($result['confirmed']);
        self::assertSame('queue_state_changed', $result['error_code']);
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(9002, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_B, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame('{"job":2}', $service->row[Queue::schema_fields_content]);
        self::assertSame('new-result', $service->row[Queue::schema_fields_result]);
        self::assertSame('new-process', $service->row[Queue::schema_fields_process]);
        self::assertSame([], $service->leaseRemovalCalls);
    }

    public function testSchedulerCannotClaimBeforeNotBeforeAndDoesNotFork(): void
    {
        $row = $this->row(status: Queue::status_pending, pid: 0, token: null);
        $row[Queue::schema_fields_NOT_BEFORE] = '2099-01-01 00:00:00';
        $service = new InMemoryQueueDispatchService($row);

        self::assertNull($service->claimForDispatch($service->queueSnapshot()));
        self::assertFalse($service->dispatchQueueIfEligible($service->queueSnapshot()));
        self::assertSame([], $service->updateCalls);
        self::assertSame(0, $service->spawnCalls);
    }

    public function testSchedulerClaimRechecksDueNotBeforeAndClearsItAtomically(): void
    {
        $row = $this->row(status: Queue::status_pending, pid: 0, token: null);
        $row[Queue::schema_fields_NOT_BEFORE] = '2000-01-01 00:00:00';
        $service = new InMemoryQueueDispatchService($row);

        $claimed = $service->claimForDispatch($service->queueSnapshot());

        self::assertInstanceOf(Queue::class, $claimed);
        self::assertNull($service->row[Queue::schema_fields_NOT_BEFORE]);
        self::assertSame(
            '2000-01-01 00:00:00',
            $service->updateCalls[0]['expected'][Queue::schema_fields_NOT_BEFORE]
        );
        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
    }

    public function testSchedulerMergesNullAndDueCandidatesByQueueIdWithoutStarvation(): void
    {
        $row = $this->row(status: Queue::status_pending, pid: 0, token: null);
        $service = new InMemoryQueueDispatchService($row);
        $service->nullPendingRows = [
            $this->candidateRow($row, 10, null),
            $this->candidateRow($row, 30, null),
            $this->candidateRow($row, 50, null),
        ];
        $service->duePendingRows = [
            $this->candidateRow($row, 20, '2000-01-01 00:00:00'),
            $this->candidateRow($row, 40, '2000-01-01 00:00:00'),
        ];

        $candidates = $service->dispatchReadyCandidates(3);

        self::assertSame(
            [10, 20, 30],
            \array_map(static fn(Queue $queue): int => (int)$queue->getId(), $candidates)
        );
        self::assertSame([
            ['shape' => 'null', 'limit' => 3],
            ['shape' => 'due', 'limit' => 3],
        ], $service->candidateLoads);
    }

    public function testSchedulerCandidateMergeDeduplicatesQueueIds(): void
    {
        $row = $this->row(status: Queue::status_pending, pid: 0, token: null);
        $service = new InMemoryQueueDispatchService($row);
        $service->nullPendingRows = [
            $this->candidateRow($row, 10, null),
            $this->candidateRow($row, 30, null),
        ];
        $service->duePendingRows = [
            $this->candidateRow($row, 20, '2000-01-01 00:00:00'),
            $this->candidateRow($row, 30, '2000-01-01 00:00:00'),
        ];

        $candidates = $service->dispatchReadyCandidates(10);

        self::assertSame(
            [10, 20, 30],
            \array_map(static fn(Queue $queue): int => (int)$queue->getId(), $candidates)
        );
    }

    public function testDeadWorkerRecoveryContentPatchCommitsWithPendingTransition(): void
    {
        $old = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $old[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($old);
        $service->runningRows = [$old];
        $service->probeState = Processer::PROCESS_STATE_EXITED;
        $service->recoverableQueue = new InMemoryRecoveryPatchQueue('{"job":1,"recovery":1}');

        $service->reconcileRunningQueues();

        self::assertSame(Queue::status_pending, $service->row[Queue::schema_fields_status]);
        self::assertSame('{"job":1,"recovery":1}', $service->row[Queue::schema_fields_content]);
        self::assertNull($service->row[Queue::schema_fields_end_at]);
        self::assertSame('{"job":1}', $service->updateCalls[0]['expected'][Queue::schema_fields_content]);
        self::assertSame(
            '{"job":1,"recovery":1}',
            $service->updateCalls[0]['updates'][Queue::schema_fields_content]
        );
    }

    public function testDeadWorkerRecoveryPatchCannotOverwriteConcurrentContent(): void
    {
        $old = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $old[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($old);
        $service->runningRows = [$old];
        $service->probeState = Processer::PROCESS_STATE_EXITED;
        $service->recoverableQueue = new InMemoryRecoveryPatchQueue('{"job":1,"recovery":1}');
        $service->beforeNextUpdate = static function (InMemoryQueueDispatchService $service): void {
            $service->row[Queue::schema_fields_content] = '{"job":2,"manual":true}';
        };

        $service->reconcileRunningQueues();

        self::assertSame(Queue::status_running, $service->row[Queue::schema_fields_status]);
        self::assertSame(9001, $service->row[Queue::schema_fields_pid]);
        self::assertSame(self::TOKEN_A, $service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertSame('{"job":2,"manual":true}', $service->row[Queue::schema_fields_content]);
        self::assertCount(1, $service->updateCalls);
        self::assertSame('{"job":1}', $service->updateCalls[0]['expected'][Queue::schema_fields_content]);
    }

    public function testWorkerFailureClearsFenceAndExactLeaseImmediately(): void
    {
        $row = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $row[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($row);

        $result = $service->failQueueWorkerSafely(
            77,
            self::TOKEN_A,
            9001,
            'failed',
            false,
            'QUEUE_CONSUMER_BOOTSTRAP_FAILED:',
        );

        self::assertTrue($result['confirmed']);
        self::assertSame(Queue::status_error, $service->row[Queue::schema_fields_status]);
        self::assertSame(0, $service->row[Queue::schema_fields_pid]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_TOKEN]);
        self::assertNull($service->row[Queue::schema_fields_DISPATCH_UNTIL]);
        self::assertStringContainsString('failed', $service->row[Queue::schema_fields_result]);
        self::assertStringContainsString(
            'QUEUE_CONSUMER_BOOTSTRAP_FAILED:',
            $service->row[Queue::schema_fields_process],
        );
        self::assertCount(1, $service->leaseRemovalCalls);
        self::assertSame(self::TOKEN_A, $service->leaseRemovalCalls[0]['expected_launch_id']);
    }

    public function testWorkerWithLostPidTokenOwnershipCannotMarkExecuting(): void
    {
        $row = $this->row(status: Queue::status_running, pid: 9002, token: self::TOKEN_B);
        $row[Queue::schema_fields_DISPATCH_UNTIL] = null;
        $service = new InMemoryQueueDispatchService($row);

        $result = $service->markQueueWorkerExecutingSafely(77, self::TOKEN_A, 9001);

        self::assertFalse($result['confirmed']);
        self::assertSame('queue_worker_fence_lost', $result['error_code']);
        self::assertSame([], $service->updateCalls);
        self::assertSame($row, $service->row);
    }

    public function testBackendAndQueryProviderHaveNoRawPidDestructiveBypass(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $controller = (string)\file_get_contents($moduleRoot . '/Controller/Backend/Queue.php');
        $adminService = (string)\file_get_contents($moduleRoot . '/Service/QueueAdminService.php');
        $provider = (string)\file_get_contents(
            $moduleRoot . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php'
        );

        foreach ([$controller, $adminService, $provider] as $source) {
            self::assertStringNotContainsString('processControl()->terminate(', $source);
            self::assertStringNotContainsString('processControl()->removeLog(', $source);
            self::assertStringNotContainsString('processControl()->isRunning(', $source);
            self::assertStringNotContainsString('Processer::killByPid(', $source);
        }
        self::assertSame(0, \substr_count($controller, 'stopQueueSafely('));
        self::assertSame(0, \substr_count($controller, 'deleteQueueSafely('));
        self::assertSame(0, \substr_count($controller, 'requeueQueueSafely('));
        self::assertSame(0, \substr_count($controller, 'updatePendingQueueSafely('));
        self::assertSame(1, \substr_count($adminService, 'stopQueueSafely('));
        self::assertSame(1, \substr_count($adminService, 'deleteQueueSafely('));
        self::assertSame(1, \substr_count($adminService, 'requeueQueueSafely('));
        self::assertSame(2, \substr_count($adminService, 'updatePendingQueueSafely('));
        self::assertStringContainsString('takeoverQueueSafely(', $provider);
        self::assertStringContainsString('deleteQueueSafely(', $provider);
        self::assertStringContainsString('updatePendingQueueSafely(', $provider);
        self::assertStringNotContainsString('applyStatusToQueue(', $provider);
        self::assertStringNotContainsString('clearData()->load($queueId)', $provider);
        self::assertStringContainsString('loadQueueFreshById(', $provider);
        self::assertStringContainsString('$this->transactions->afterCommit(', $provider);

        $controlStart = \strpos($adminService, 'public function action(');
        $controlEnd = \strpos($adminService, 'public function batchAction(', (int)$controlStart);
        self::assertNotFalse($controlStart);
        self::assertNotFalse($controlEnd);
        $controlSource = \substr($adminService, (int)$controlStart, (int)$controlEnd - (int)$controlStart);
        self::assertStringNotContainsString('clearData()->load(', $controlSource);
        self::assertSame(1, \substr_count($controlSource, 'hydrateQueueFromResult('));
        self::assertStringContainsString("private const PUBLIC_ACTIONS = ['delete', 'stop', 'continue', 'retry', 'reset'];", $adminService);
        self::assertStringContainsString("private const PUBLIC_BATCH_ACTIONS = ['delete', 'stop', 'continue'];", $adminService);
    }

    public function testBackendPendingEditCommitsMainRowAndEavBeforeDispatchingEvent(): void
    {
        $adminService = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/QueueAdminService.php'
        );
        $formStart = \strpos($adminService, 'public function save(');
        $formEnd = \strpos($adminService, 'public function action(', (int)$formStart);
        self::assertNotFalse($formStart);
        self::assertNotFalse($formEnd);
        $formSource = \substr($adminService, (int)$formStart, (int)$formEnd - (int)$formStart);

        $begin = \strpos($formSource, '$transactionQuery->beginWriteTransaction();');
        $mainCas = \strpos($formSource, 'updatePendingQueueSafely(', (int)$begin);
        $attributeWrite = \strpos($formSource, '->setValue($queueId, $attribute[\'value\']);', (int)$mainCas);
        $consumerValidate = \strpos($formSource, 'if (!$consumer->validate($queue))', (int)$attributeWrite);
        $commit = \strpos($formSource, '$transactionQuery->commit();', (int)$consumerValidate);
        $event = \strpos($formSource, '$this->dispatchAfterCommit(', (int)$commit);

        self::assertNotFalse($begin);
        self::assertNotFalse($mainCas);
        self::assertNotFalse($attributeWrite);
        self::assertNotFalse($consumerValidate);
        self::assertNotFalse($commit);
        self::assertNotFalse($event);
        self::assertLessThan($mainCas, $begin);
        self::assertLessThan($attributeWrite, $mainCas);
        self::assertLessThan($consumerValidate, $attributeWrite);
        self::assertLessThan($commit, $consumerValidate);
        self::assertLessThan($event, $commit);
        self::assertStringContainsString('$this->rollBack($transactionQuery);', $formSource);
        self::assertStringContainsString('private function loadQueueFresh(', $adminService);
        self::assertStringNotContainsString('$this->queue->load(', $formSource);
        self::assertStringNotContainsString('clearData()->load(', $formSource);
        self::assertSame(3, \substr_count($formSource, 'loadQueueFresh('));
    }

    public function testSafeDeleteCommitsFencedMainRowAndEavCleanupTogether(): void
    {
        $service = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/QueueDispatchService.php'
        );
        $queueModel = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Model/Queue.php'
        );
        $method = \strpos($service, 'protected function deleteQueueIf(');
        $begin = \strpos($service, '$transactionQuery->beginWriteTransaction();', (int)$method);
        $predicate = \strpos($service, 'foreach ($expected as $field => $value)', (int)$begin);
        $mainDelete = \strpos($service, '$query->getQuery()->delete()->fetch();', (int)$predicate);
        $eavCleanup = \strpos($service, '$this->deleteQueueAttributeValues($queueSnapshot);', (int)$mainDelete);
        $commit = \strpos($service, '$transactionQuery->commit();', (int)$eavCleanup);

        self::assertNotFalse($method);
        self::assertNotFalse($begin);
        self::assertNotFalse($predicate);
        self::assertNotFalse($mainDelete);
        self::assertNotFalse($eavCleanup);
        self::assertNotFalse($commit);
        self::assertLessThan($predicate, $begin);
        self::assertLessThan($mainDelete, $predicate);
        self::assertLessThan($eavCleanup, $mainDelete);
        self::assertLessThan($commit, $eavCleanup);
        self::assertStringContainsString('$queueSnapshot->getAllEavAttributes()', $service);
        self::assertStringContainsString('$attribute->getAttributeId()', $service);
        self::assertStringContainsString('$attribute->w_getValueModel()', $service);
        self::assertStringNotContainsString('unsetAttributeValues(', $service);
        self::assertStringContainsString('$transactionQuery->rollBack();', $service);
        self::assertStringContainsString('ObjectManager::make(EavAttribute::class)', $queueModel);
        self::assertStringContainsString('EavAttribute::schema_fields_eav_entity_id', $queueModel);
        self::assertStringNotContainsString('return parent::getAttributes();', $queueModel);
    }

    public function testStrictQueueAttributeCleanupPropagatesValueDeleteFailure(): void
    {
        $service = new InMemoryQueueDispatchService($this->row());
        /** @var FailingQueueAttributeCleanupSnapshot $queue */
        $queue = (new \ReflectionClass(FailingQueueAttributeCleanupSnapshot::class))
            ->newInstanceWithoutConstructor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('value-delete-failed');

        $service->strictlyCleanupQueueAttributes($queue);
    }

    public function testDeleteCleanupFailureStillRemovesExactReleasedLease(): void
    {
        $row = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $service = new InMemoryQueueDispatchService($row);
        $service->managedTerminationResult = [
            'state' => Processer::PROCESS_STATE_EXITED,
            'reason' => 'process_exited',
            'released' => true,
            'terminated' => true,
        ];
        $service->deleteFailure = new \RuntimeException('eav-cleanup-failed');

        try {
            $service->deleteQueueSafely(77, true);
            self::fail('EAV cleanup failure must escape after database rollback.');
        } catch (\RuntimeException $exception) {
            self::assertSame('eav-cleanup-failed', $exception->getMessage());
        }

        self::assertSame($row, $service->row);
        self::assertCount(1, $service->leaseRemovalCalls);
        self::assertSame(9001, $service->leaseRemovalCalls[0]['pid']);
        self::assertSame(self::TOKEN_A, $service->leaseRemovalCalls[0]['expected_launch_id']);
    }

    public function testTransitionWriteFailureStillRemovesExactReleasedLease(): void
    {
        $row = $this->row(status: Queue::status_running, pid: 9001, token: self::TOKEN_A);
        $service = new InMemoryQueueDispatchService($row);
        $service->managedTerminationResult = [
            'state' => Processer::PROCESS_STATE_EXITED,
            'reason' => 'process_exited',
            'released' => true,
            'terminated' => true,
        ];
        $service->updateFailure = new \RuntimeException('transition-write-failed');

        try {
            $service->stopQueueSafely(77);
            self::fail('A transition write failure must escape after exact lease cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transition-write-failed', $exception->getMessage());
        }

        self::assertSame($row, $service->row);
        self::assertCount(1, $service->leaseRemovalCalls);
        self::assertSame(9001, $service->leaseRemovalCalls[0]['pid']);
        self::assertSame(self::TOKEN_A, $service->leaseRemovalCalls[0]['expected_launch_id']);
    }

    public function testClaimedWorkerRegistersExactLeaseFinalizer(): void
    {
        $run = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Console/Queue/Run.php');

        self::assertStringContainsString('registerShutdownCallback', $run);
        self::assertStringContainsString('releaseClaimedWorkerLeaseByTaskName(', $run);
        self::assertStringContainsString('$workerQueueId', $run);
        self::assertStringContainsString('$dispatchToken', $run);
        self::assertStringContainsString('$workerPid', $run);
        self::assertStringContainsString('$workerQueueTaskName', $run);
        self::assertLessThan(
            \strpos($run, '$queue = $this->loadFreshQueue((int)$id);'),
            \strpos($run, '$this->registerShutdownCallback('),
            'The exact managed lease finalizer must be registered before the first Queue load.',
        );
        self::assertStringNotContainsString('Processer::isRunningByPid', $run);
        self::assertStringContainsString('claimQueueForManualRun(', $run);
        self::assertStringContainsString('markQueueWorkerExecutingSafely(', $run);
        self::assertStringContainsString('completeQueueWorkerSafely(', $run);
        self::assertStringContainsString('deferQueueWorkerSafely(', $run);
        self::assertStringContainsString('QueueDeferredCompletionException', $run);
        self::assertStringContainsString('failQueueWorkerSafely(', $run);
        self::assertStringNotContainsString('->save()', $run);
    }

    /** @return array<string,mixed> */
    private function row(
        string $status = Queue::status_running,
        int $pid = 0,
        ?string $token = self::TOKEN_A,
        string $content = '{"job":1}',
        string $process = 'old-process',
    ): array {
        return [
            Queue::schema_fields_ID => 77,
            Queue::schema_fields_type_id => 1,
            Queue::schema_fields_name => 'demo',
            Queue::schema_fields_module => 'Weline_Queue',
            Queue::schema_fields_status => $status,
            Queue::schema_fields_pid => $pid,
            Queue::schema_fields_DISPATCH_TOKEN => $token,
            Queue::schema_fields_DISPATCH_UNTIL => $token === null ? null : '2099-01-01 00:00:00',
            Queue::schema_fields_NOT_BEFORE => null,
            Queue::schema_fields_finished => 0,
            Queue::schema_fields_auto => 1,
            Queue::schema_fields_start_at => null,
            Queue::schema_fields_end_at => null,
            Queue::schema_fields_result => 'old-result',
            Queue::schema_fields_process => $process,
            Queue::schema_fields_content => $content,
            Queue::schema_fields_BIZ_KEY => null,
        ];
    }

    /** @param array<string,mixed> $base @return array<string,mixed> */
    private function candidateRow(array $base, int $queueId, ?string $notBefore): array
    {
        $base[Queue::schema_fields_ID] = $queueId;
        $base[Queue::schema_fields_NOT_BEFORE] = $notBefore;

        return $base;
    }
}

final class InMemoryQueueDispatchService extends QueueDispatchService
{
    /** @var array<string,mixed>|null */
    public ?array $row;
    /** @var list<array{expected:array<string,mixed>,updates:array<string,mixed>}> */
    public array $updateCalls = [];
    /** @var list<array<string,mixed>> */
    public array $deleteCalls = [];
    /** @var list<int> */
    public array $probeCalls = [];
    /** @var list<array<string,mixed>> */
    public array $managedTerminationCalls = [];
    /** @var list<array<string,mixed>> */
    public array $leaseRemovalCalls = [];
    public string $probeState = Processer::PROCESS_STATE_EXITED;
    /** @var array<string,mixed> */
    public array $managedTerminationResult = [
        'state' => Processer::PROCESS_STATE_EXITED,
        'reason' => 'process_exited',
        'released' => true,
        'terminated' => false,
    ];
    public ?\Closure $beforeNextUpdate = null;
    public ?\Closure $beforeNextDelete = null;
    /** @var list<array<string,mixed>> */
    public array $runningRows = [];
    public bool $managedQueueRunning = false;
    public string $queueCommandLine = '';
    public string $managedOutput = '';
    public int $loadCalls = 0;
    public ?\Throwable $spawnFailure = null;
    public ?\Throwable $deleteFailure = null;
    public ?\Throwable $updateFailure = null;
    public bool $activeQueueTransaction = false;
    public int $spawnCalls = 0;
    /** @var list<array<string,mixed>> */
    public array $nullPendingRows = [];
    /** @var list<array<string,mixed>> */
    public array $duePendingRows = [];
    /** @var list<array{shape:string,limit:int}> */
    public array $candidateLoads = [];
    public ?DeadWorkerRecoverableQueueInterface $recoverableQueue = null;

    /** @param array<string,mixed> $row */
    public function __construct(array $row)
    {
        $this->row = $row;
        parent::__construct(
            self::queueFromRow([]),
            (new \ReflectionClass(RuntimeProviderResolver::class))->newInstanceWithoutConstructor(),
        );
    }

    protected function loadFreshQueue(int $queueId): Queue
    {
        $this->loadCalls++;
        return self::queueFromRow($this->row ?? []);
    }

    public function countRunningAutoQueues(): int
    {
        return 0;
    }

    /** @return list<Queue> */
    protected function loadRunningAutoQueues(): array
    {
        return \array_map(
            static fn(array $row): Queue => self::queueFromRow($row),
            $this->runningRows,
        );
    }

    protected function loadDispatchReadyPendingQueuesByNotBefore(
        ?string $notBefore,
        string $condition,
        int $limit,
    ): array {
        $shape = $notBefore === null ? 'null' : 'due';
        $this->candidateLoads[] = ['shape' => $shape, 'limit' => $limit];
        $rows = $shape === 'null' ? $this->nullPendingRows : $this->duePendingRows;

        return \array_map(
            static fn(array $row): Queue => self::queueFromRow($row),
            \array_slice($rows, 0, $limit),
        );
    }

    protected function resolveDeadWorkerRecoverableQueue(
        Queue $queue,
    ): ?DeadWorkerRecoverableQueueInterface {
        return $this->recoverableQueue;
    }

    protected function updateQueueIf(array $expected, array $updates): bool
    {
        $this->updateCalls[] = ['expected' => $expected, 'updates' => $updates];
        if ($this->updateFailure instanceof \Throwable) {
            throw $this->updateFailure;
        }
        if ($this->beforeNextUpdate instanceof \Closure) {
            $callback = $this->beforeNextUpdate;
            $this->beforeNextUpdate = null;
            $callback($this);
        }
        if (!$this->rowMatches($expected)) {
            return false;
        }

        $this->row = \array_replace($this->row ?? [], $updates);
        return true;
    }

    protected function deleteQueueIf(array $expected, Queue $queueSnapshot): bool
    {
        $this->deleteCalls[] = $expected;
        if ($this->deleteFailure instanceof \Throwable) {
            throw $this->deleteFailure;
        }
        if ($this->beforeNextDelete instanceof \Closure) {
            $callback = $this->beforeNextDelete;
            $this->beforeNextDelete = null;
            $callback($this);
        }
        if (!$this->rowMatches($expected)) {
            return false;
        }

        $this->row = null;
        return true;
    }

    protected function normalizeQueueTaskName(string $queueDisplayName, int $queueId): string
    {
        return 'queue-' . $queueDisplayName . '-' . $queueId;
    }

    protected function currentProcessId(): int
    {
        return 4242;
    }

    protected function probeQueueProcessState(int $pid): string
    {
        $this->probeCalls[] = $pid;
        return $this->probeState;
    }

    protected function hasActiveQueueTransaction(): bool
    {
        return $this->activeQueueTransaction;
    }

    protected function isManagedQueueWorkerRunning(
        int $pid,
        string $queueTaskName,
        string $dispatchToken,
        string $processName,
    ): bool {
        return $this->managedQueueRunning;
    }

    protected function getQueueProcessCommandLine(int $pid): string
    {
        return $this->queueCommandLine;
    }

    protected function getQueueManagedProcessOutput(string $processName, int $pid): string
    {
        return $this->managedOutput;
    }

    protected function createDetachedQueueWorker(array $argv, string $processName): int
    {
        $this->spawnCalls++;
        if ($this->spawnFailure instanceof \Throwable) {
            throw $this->spawnFailure;
        }

        return 9001;
    }

    protected function getQueueSpawnOutput(string $processName): string
    {
        return '';
    }

    protected function terminateManagedQueueProcess(
        int $pid,
        string $expectedProcessName,
        string $expectedLaunchId,
        string $expectedPname,
        array $requiredLiveArguments,
    ): array {
        $this->managedTerminationCalls[] = [
            'pid' => $pid,
            'expected_process_name' => $expectedProcessName,
            'expected_launch_id' => $expectedLaunchId,
            'expected_pname' => $expectedPname,
            'required_live_arguments' => $requiredLiveArguments,
        ];

        return $this->managedTerminationResult;
    }

    protected function removeManagedQueueProcessLease(
        int $pid,
        string $expectedProcessName,
        string $expectedLaunchId,
    ): bool {
        $this->leaseRemovalCalls[] = [
            'pid' => $pid,
            'expected_process_name' => $expectedProcessName,
            'expected_launch_id' => $expectedLaunchId,
        ];

        return true;
    }

    public function queueSnapshot(): Queue
    {
        return self::queueFromRow($this->row ?? []);
    }

    public function claimForDispatch(Queue $queue): ?Queue
    {
        return $this->claimQueueForDispatch($queue);
    }

    /** @return list<Queue> */
    public function dispatchReadyCandidates(int $limit): array
    {
        return $this->loadDispatchReadyPendingQueues($limit);
    }

    public function strictlyCleanupQueueAttributes(Queue $queue): void
    {
        $this->deleteQueueAttributeValues($queue);
    }

    /** @param array<string,mixed> $expected */
    public function rowMatches(array $expected): bool
    {
        if ($this->row === null) {
            return false;
        }
        foreach ($expected as $field => $value) {
            if (!\array_key_exists($field, $this->row) || $this->row[$field] !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $row */
    private static function queueFromRow(array $row): Queue
    {
        /** @var Queue $queue */
        $queue = (new \ReflectionClass(Queue::class))->newInstanceWithoutConstructor();
        $queue->setData($row);

        return $queue;
    }
}

final class InMemoryRecoveryPatchQueue implements DeadWorkerRecoveryPatchQueueInterface
{
    public function __construct(private readonly string $content)
    {
    }

    public function shouldRecoverDeadWorker(Queue $queue, int $deadPid, string $workerOutput): bool
    {
        return true;
    }

    public function deadWorkerRecoveryMessage(Queue $queue, int $deadPid, string $workerOutput): string
    {
        return 'recovering dead worker';
    }

    public function deadWorkerRecoveryPatch(Queue $queue, int $deadPid, string $workerOutput): array
    {
        return [Queue::schema_fields_content => $this->content];
    }
}

final class FailingQueueAttributeCleanupSnapshot extends Queue
{
    public function getAttributes(array $options_data = []): array
    {
        return [];
    }

    public function getAllEavAttributes(): array
    {
        return [new FailingQueueEavAttribute()];
    }
}

final class FailingQueueEavAttribute
{
    public function getCode(): string
    {
        return 'demo_attribute';
    }

    public function getAttributeId(): int
    {
        return 123;
    }

    public function w_getValueModel(): FailingQueueEavValueModel
    {
        return new FailingQueueEavValueModel();
    }
}

final class FailingQueueEavValueModel
{
    public function clearData(): static
    {
        return $this;
    }

    public function clearQuery(): static
    {
        return $this;
    }

    public function where(string $field, mixed $value): static
    {
        return $this;
    }

    public function delete(): static
    {
        return $this;
    }

    public function fetch(): never
    {
        throw new \RuntimeException('value-delete-failed');
    }
}
