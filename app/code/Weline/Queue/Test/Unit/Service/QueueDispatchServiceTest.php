<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Queue\Service\QueueDispatchService;

final class QueueDispatchServiceTest extends TestCase
{
    public function testFailQueueWorkerMarksFinishedTerminal(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $failSource = $this->extractPrivateMethodSource($source, 'failQueueWorkerSafely');

        self::assertStringContainsString('Queue::schema_fields_status => Queue::status_error', $failSource);
        self::assertStringContainsString('Queue::schema_fields_finished => 1', $failSource);
        self::assertStringContainsString('Queue::schema_fields_DISPATCH_TOKEN => null', $failSource);
    }

    public function testQueueWorkerCommandCarriesDedicatedMemoryLimit(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $buildMethodSource = $this->extractPrivateMethodSource($source, 'buildQueueRunProcessName');

        self::assertStringContainsString('resolveWorkerMemoryLimit($queue)', $buildMethodSource);
        self::assertStringContainsString('memory_limit=', $buildMethodSource);
        self::assertStringContainsString('queue:run --id=', $buildMethodSource);
        self::assertStringContainsString("private const DEFAULT_WORKER_MEMORY_LIMIT = '512M';", $source);
        self::assertStringContainsString("'queue.worker.memory_limit'", $source);
    }

    public function testQueueRunCommandAppliesClassMemoryLimitWhenStartedManually(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Console/Queue/Run.php');

        self::assertStringContainsString('applyCliMemoryLimitForQueueClass($queueClass)', $source);
        self::assertStringContainsString('ini_set(\'memory_limit\', $target)', $source);
        self::assertStringContainsString("'queue.worker.memory_limit_by_class.' . \$queueClass", $source);
    }

    public function testQueueRunCommandPreservesExplicitRetryStateAfterExecute(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Console/Queue/Run.php');
        $executeSource = $this->extractPrivateMethodSource($source, 'execute');
        $serviceSource = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $completeSource = $this->extractPrivateMethodSource($serviceSource, 'completeQueueWorkerSafely');

        self::assertStringContainsString('completeQueueWorkerSafely(', $executeSource);
        self::assertStringContainsString('Queue::status_pending', $completeSource);
        self::assertStringContainsString('Queue::status_error', $completeSource);
        self::assertStringContainsString('Queue::status_stop', $completeSource);
        self::assertStringContainsString('$queue->isFinished()', $completeSource);
        self::assertStringContainsString('Queue::schema_fields_DISPATCH_TOKEN => null', $completeSource);
        self::assertStringNotContainsString('->save()', $executeSource);
    }

    public function testQueueSchedulerConcurrencyIsConfigurable(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');

        self::assertStringContainsString("'queue.cron.max_concurrent'", $source);
        self::assertStringContainsString('public function getMaxConcurrent(): int', $source);
    }

    public function testBatchDrainingClassesAreSingleFlightOnDispatch(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $startSource = $this->extractPrivateMethodSource($source, 'startQueueProcess');
        $eligibleSource = $this->extractPrivateMethodSource($source, 'dispatchQueueIfEligible');

        self::assertFileExists(\dirname(__DIR__, 3) . '/Api/BatchDrainingQueueConsumerInterface.php');
        self::assertStringContainsString('BatchDrainingQueueConsumerInterface', $source);
        self::assertStringContainsString('countActiveAutoQueuesForClass(', $eligibleSource);
        self::assertStringContainsString('resolveMaxConcurrentForClass(', $eligibleSource);
        self::assertStringContainsString('withQueueClassDispatchLock(', $startSource);
        self::assertStringContainsString('isBatchDrainingQueueClass(', $startSource);
        self::assertStringContainsString("'queue.cron.max_concurrent_by_class.'", $source);
    }

    public function testQueueWorkerMemoryLimitNormalizationAcceptsPhpIniUnits(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $normalizeMethodSource = $this->extractPrivateMethodSource($source, 'normalizeMemoryLimit');

        self::assertStringContainsString("if (\$value === '-1')", $normalizeMethodSource);
        self::assertStringContainsString("return \$value . 'M';", $normalizeMethodSource);
        self::assertStringContainsString('/^[1-9]\d*(?:K|M|G)$/', $normalizeMethodSource);
        self::assertStringContainsString('return $default;', $normalizeMethodSource);
    }

    public function testQueueResultAndProcessOutputAreByteBounded(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $prependSource = $this->extractPrivateMethodSource($source, 'prependResultMessage');

        self::assertMatchesRegularExpression('/RESULT_MAX_BYTES\s*=\s*\d+/', $source);
        self::assertMatchesRegularExpression('/PROCESS_OUTPUT_MAX_BYTES\s*=\s*\d+/', $source);
        self::assertLessThanOrEqual(
            8192,
            $this->extractNamedIntConstant($source, 'RESULT_MAX_BYTES'),
            'queue.result must stay a short process trail, not a log dump.'
        );
        self::assertStringContainsString('private static function boundResultText', $source);
        self::assertStringContainsString('private static function readFileTail', $source);
        self::assertStringContainsString('self::boundResultText', $this->extractPrivateMethodSource($source, 'appendProcessMessage'));
        self::assertStringContainsString('self::boundResultText', $prependSource);
        self::assertStringNotContainsString(
            'boundResultText($output',
            $prependSource,
            'prependResultMessage must not persist worker stdout into queue.result.'
        );
        self::assertStringNotContainsString(
            'PROCESS_OUTPUT_MAX_BYTES',
            $prependSource,
            'process log caps belong to probing, not result persistence.'
        );
        self::assertStringContainsString('self::readFileTail', $this->extractPrivateMethodSource($source, 'getManagedProcessOutput'));
        self::assertStringNotContainsString(
            'file_get_contents($path)',
            $this->extractPrivateMethodSource($source, 'getManagedProcessOutput'),
            'Unbounded file_get_contents of process logs must not remain in getManagedProcessOutput.'
        );
        self::assertStringContainsString('alignUtf8Tail', $source);
        self::assertStringContainsString('ensureValidUtf8', $source);
        self::assertStringContainsString('reconcileOneRunningAutoQueue', $source);
        self::assertStringContainsString('queue.reconcile_running_failed:', $source);
        self::assertSame(
            0,
            \preg_match(
                '/schema_fields_process\s*=>\s*\\\\trim\(\s*(?:\(string\))?\$queue->getProcess/',
                $source
            ),
            'Direct process concatenations must go through boundResultText to keep UTF-8 safe for PG.'
        );
        self::assertStringContainsString(
            'boundResultText',
            $this->extractPrivateMethodSource($source, 'failQueueWorkerSafely')
        );
    }

    public function testBoundResultTextKeepsValidUtf8AfterMidCodepointTailCut(): void
    {
        $method = new \ReflectionMethod(QueueDispatchService::class, 'boundResultText');
        $method->setAccessible(true);

        $text = "head\n" . \str_repeat('汉字', 4000) . '尾部';
        $bounded = (string)$method->invoke(null, $text, 8192);

        self::assertLessThanOrEqual(8192, \strlen($bounded));
        self::assertTrue(\mb_check_encoding($bounded, 'UTF-8'));
        self::assertStringContainsString('[truncated', $bounded);
    }

    public function testReconcileRunningIsolatesPerQueueFailures(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        self::assertStringContainsString('reconcileOneRunningAutoQueue($queue)', $reconcileSource);
        self::assertStringContainsString('catch (\\Throwable $throwable)', $reconcileSource);
        self::assertStringContainsString('queue.reconcile_running_failed:', $reconcileSource);
        self::assertStringContainsString('cleanupDeadQueuePidJsonOrphans()', $reconcileSource);
    }

    private function extractNamedIntConstant(string $source, string $name): int
    {
        if (!\preg_match('/' . \preg_quote($name, '/') . '\s*=\s*(\d+)/', $source, $m)) {
            self::fail($name . ' missing');
        }

        return (int)$m[1];
    }

    public function testReconcileRepairsFinishedRunningQueues(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileMethodSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        self::assertStringNotContainsString('schema_fields_finished, 0', $reconcileMethodSource);
        self::assertStringContainsString('$queue->isFinished()', $reconcileMethodSource);

        $finishedOffset = \strpos($reconcileMethodSource, '$queue->isFinished()');
        $pendingOffset = \strpos($reconcileMethodSource, 'Queue::schema_fields_status => Queue::status_pending');
        self::assertNotFalse($finishedOffset);
        self::assertNotFalse($pendingOffset);
        self::assertLessThan(
            $pendingOffset,
            $finishedOffset,
            'Finished running queues must be completed before no-PID rows are reset to pending.'
        );
    }

    public function testDispatchQueueIfEligibleReconcilesRunningQueuesBeforeEligibilityCheck(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $dispatchMethodSource = $this->extractPrivateMethodSource($source, 'dispatchQueueIfEligible');

        $reconcileOffset = \strpos($dispatchMethodSource, '$this->reconcileRunningQueues();');
        $loadFreshOffset = \strpos($dispatchMethodSource, '$freshQueue = $this->loadFreshQueue($queueId);');

        self::assertNotFalse($reconcileOffset);
        self::assertNotFalse($loadFreshOffset);
        self::assertLessThan(
            $loadFreshOffset,
            $reconcileOffset,
            'Single-queue wakeups must reconcile stale running rows before checking dispatch eligibility.'
        );
    }

    public function testQueueQueryProviderSupportsDeferredCreateAndExplicitDispatch(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php');
        $executeMethodSource = $this->extractPrivateMethodSource($source, 'execute');
        $createMethodSource = $this->extractPrivateMethodSource($source, 'createQueue');
        $dispatchMethodSource = $this->extractPrivateMethodSource($source, 'dispatchQueue');

        self::assertSame(
            1,
            \substr_count($executeMethodSource, "'dispatch' => \$this->dispatchQueue(\$params)"),
            'Queue must expose one explicit dispatch operation.'
        );
        self::assertStringContainsString(
            "!\\array_key_exists('dispatch', \$params) || (bool)\$params['dispatch']",
            $createMethodSource
        );
        self::assertStringContainsString(
            '$this->transactions->afterCommit(',
            $createMethodSource
        );
        self::assertStringContainsString('$this->maybeDispatchAutoQueue($committed)', $createMethodSource);
        self::assertSame(1, \substr_count($dispatchMethodSource, 'maybeDispatchAutoQueue($queue)'));
        self::assertStringContainsString('refreshCreateResultAfterOwnedCommit($result)', $source);
    }

    public function testQueueQueryProviderUpdateAndTakeoverRequireExplicitDispatch(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php');
        $updateMethodSource = $this->extractPrivateMethodSource($source, 'updateQueue');
        $takeoverMethodSource = $this->extractPrivateMethodSource($source, 'takeoverQueue');
        $dispatchMethodSource = $this->extractPrivateMethodSource($source, 'dispatchQueue');

        self::assertStringNotContainsString('maybeDispatchAutoQueue(', $updateMethodSource);
        self::assertStringNotContainsString('maybeDispatchAutoQueue(', $takeoverMethodSource);
        self::assertStringContainsString('$this->maybeDispatchAutoQueue($queue)', $dispatchMethodSource);
    }

    public function testQueueQueryProviderCreateOnlyAcceptsPendingStatus(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php');
        $createMethodSource = $this->extractPrivateMethodSource($source, 'createQueue');

        self::assertStringContainsString('$status !== Queue::status_pending', $createMethodSource);
        self::assertStringContainsString('公开 Queue create 只允许 status=pending', $createMethodSource);
        self::assertStringNotContainsString('VALID_STATUSES', $source);
        self::assertStringContainsString("'description' => __('仅允许 pending')", $source);
    }

    public function testQueueQueryProviderUpdateUsesPendingCasBeforeSuccessEvent(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php');
        $updateMethodSource = $this->extractPrivateMethodSource($source, 'updateQueue');

        $idOffset = \strpos($updateMethodSource, '$queueId = (int)$queue->getId();');
        $casOffset = \strpos($updateMethodSource, 'updatePendingQueueSafely($queueId, $updates)');
        $freshDataOffset = \strpos($updateMethodSource, "\$result['data']");
        $afterCommitOffset = \strpos($updateMethodSource, '$this->transactions->afterCommit(');
        $eventOffset = \strpos($updateMethodSource, "dispatch('Weline_Queue::edit'");
        $returnOffset = \strrpos($updateMethodSource, "'queue_id' => \$queueId");

        self::assertNotFalse($idOffset);
        self::assertNotFalse($casOffset);
        self::assertNotFalse($freshDataOffset);
        self::assertNotFalse($afterCommitOffset);
        self::assertNotFalse($eventOffset);
        self::assertNotFalse($returnOffset);
        self::assertLessThan($casOffset, $idOffset);
        self::assertLessThan($freshDataOffset, $casOffset);
        self::assertLessThan($afterCommitOffset, $freshDataOffset);
        self::assertLessThan($eventOffset, $afterCommitOffset);
        self::assertLessThan($returnOffset, $eventOffset);
        self::assertStringNotContainsString('$queue->save();', $updateMethodSource);
        self::assertStringNotContainsString('clearData()->load((int)$queue->getId())', $updateMethodSource);
    }

    public function testReconcileChecksPidLivenessAndWritesOperatorMessage(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileMethodSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        $pidAliveOffset = \strpos($reconcileMethodSource, 'probeQueueProcessState($queuePid)');
        $managedOffset = \strpos($reconcileMethodSource, 'isManagedQueueWorkerRunning(');

        self::assertNotFalse($pidAliveOffset);
        self::assertNotFalse($managedOffset);
        self::assertLessThan(
            $managedOffset,
            $pidAliveOffset,
            'Reconcile must obtain a fresh three-state process result before managed identity matching.'
        );
        self::assertStringNotContainsString('Processer::isRunningByPid', $reconcileMethodSource);
        self::assertStringContainsString('队列记录的 PID %{1} 已不存在', $source);
        self::assertStringContainsString('队列记录的 PID %{1} 仍存在', $source);
        self::assertStringContainsString('updateQueueSnapshotIf($queue', $reconcileMethodSource);
        self::assertStringContainsString('Queue::schema_fields_process => $this->appendProcessMessage', $reconcileMethodSource);
        self::assertStringContainsString('cleanupDeadQueuePidJsonOrphans()', $reconcileMethodSource);
    }

    public function testRecoverableDeadWorkersAreReturnedToSchedulerInsteadOfMarkedError(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileMethodSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        $recoverOffset = \strpos($reconcileMethodSource, 'deadWorkerRecoveryPatch($queue, $queuePid, $output)');
        $errorOffset = \strpos($reconcileMethodSource, 'Queue::schema_fields_status => Queue::status_error');

        self::assertNotFalse($recoverOffset, 'dead-worker recovery contract must be checked before terminal error handling');
        self::assertNotFalse($errorOffset, 'generic dead-worker error path missing');
        self::assertLessThan($errorOffset, $recoverOffset);
        self::assertStringContainsString('deadWorkerRecoveryMessage($queue, $queuePid, $output)', $reconcileMethodSource);
        self::assertStringContainsString('Queue::schema_fields_status => Queue::status_pending', $reconcileMethodSource);
        self::assertStringContainsString('Queue::schema_fields_finished => 0', $reconcileMethodSource);
        self::assertStringContainsString('Queue::schema_fields_pid => 0', $reconcileMethodSource);
        self::assertStringContainsString('Queue::schema_fields_start_at => null', $reconcileMethodSource);
        self::assertStringContainsString('Queue::schema_fields_end_at => null', $reconcileMethodSource);
        self::assertStringContainsString('Queue::schema_fields_result => $message', $reconcileMethodSource);
    }

    public function testReconcileDeadWorkerErrorPathMarksFinishedTerminal(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileMethodSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        $errorOffset = \strpos($reconcileMethodSource, 'Queue::schema_fields_status => Queue::status_error');
        self::assertNotFalse($errorOffset, 'generic dead-worker error path missing');
        $finishedAfterError = \strpos(
            $reconcileMethodSource,
            'Queue::schema_fields_finished => 1',
            $errorOffset
        );
        self::assertNotFalse(
            $finishedAfterError,
            'Dead-PID error closeout must set finished=1 (same terminal as failQueueWorkerSafely).'
        );
        self::assertLessThan(
            400,
            $finishedAfterError - $errorOffset,
            'finished=1 must sit on the dead-PID error update block, not a distant branch.'
        );
    }

    public function testRecoverableNoPidRunningQueuesMustPassRecoveryContract(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileMethodSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        $recoverableOffset = \strpos($reconcileMethodSource, 'resolveDeadWorkerRecoverableQueue($queue) instanceof DeadWorkerRecoverableQueueInterface');
        $contractOffset = \strpos($reconcileMethodSource, 'deadWorkerRecoveryPatch($queue, 0, $output)');
        $errorOffset = \strpos($reconcileMethodSource, '可恢复队列处于 running 但没有 PID');
        $genericNoPidOffset = \strrpos($reconcileMethodSource, 'Queue::schema_fields_status => Queue::status_pending');

        self::assertNotFalse($recoverableOffset);
        self::assertNotFalse($contractOffset);
        self::assertNotFalse($errorOffset);
        self::assertNotFalse($genericNoPidOffset);
        self::assertLessThan($contractOffset, $recoverableOffset);
        self::assertLessThan($errorOffset, $contractOffset);
        self::assertLessThan($genericNoPidOffset, $errorOffset);
        self::assertStringContainsString('Queue::schema_fields_status => Queue::status_error', $reconcileMethodSource);
        self::assertStringContainsString('Queue::schema_fields_finished => 1', $reconcileMethodSource);
    }

    public function testDeadWorkerRecoveryUsesQueueTypeContract(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $resolverSource = $this->extractPrivateMethodSource($source, 'resolveDeadWorkerRecoverableQueue');
        $decisionSource = $this->extractPrivateMethodSource($source, 'shouldRecoverDeadWorker');
        $patchSource = $this->extractPrivateMethodSource($source, 'deadWorkerRecoveryPatch');

        self::assertStringContainsString('DeadWorkerRecoverableQueueInterface', $source);
        self::assertStringContainsString('DeadWorkerRecoveryPatchQueueInterface', $source);
        self::assertStringContainsString('ObjectManager::getInstance($queueClass)', $resolverSource);
        self::assertStringContainsString('resolveQueueClass($queue)', $resolverSource);
        self::assertStringContainsString('instanceof DeadWorkerRecoverableQueueInterface', $resolverSource);
        self::assertStringContainsString('->shouldRecoverDeadWorker($queue, $deadPid, $workerOutput)', $decisionSource);
        self::assertStringContainsString('Queue::schema_fields_content', $patchSource);
        self::assertStringContainsString('array_diff(', $patchSource);
        self::assertStringNotContainsString('->save()', $patchSource);
    }

    private function extractPrivateMethodSource(string $source, string $methodName): string
    {
        $methodPattern = '/\\b(?:private|protected|public)\\s+function\\s+'
            . \preg_quote($methodName, '/')
            . '\\s*\\(/';
        $matched = \preg_match($methodPattern, $source, $matches, \PREG_OFFSET_CAPTURE);
        self::assertSame(1, $matched, $methodName . ' missing');
        $methodOffset = $matches[0][1];
        $nextPrivateMethodOffset = \strpos($source, 'private function ', $methodOffset + 1);
        $nextPublicMethodOffset = \strpos($source, 'public function ', $methodOffset + 1);
        $methodOffsets = \array_filter(
            [$nextPrivateMethodOffset, $nextPublicMethodOffset],
            static fn (int|false $offset): bool => $offset !== false
        );
        $nextMethodOffset = $methodOffsets === [] ? false : \min($methodOffsets);

        return $nextMethodOffset === false
            ? \substr($source, $methodOffset)
            : \substr($source, $methodOffset, $nextMethodOffset - $methodOffset);
    }
}
