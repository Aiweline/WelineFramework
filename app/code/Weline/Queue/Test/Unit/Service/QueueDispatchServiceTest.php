<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class QueueDispatchServiceTest extends TestCase
{
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

    public function testPageBuilderQueuesStayWithinDefaultFiveHundredTwelveMegabyteMemoryLimit(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $resolveMethodSource = $this->extractPrivateMethodSource($source, 'resolveWorkerMemoryLimit');

        self::assertStringContainsString('DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS', $source);
        self::assertStringContainsString('\\GuoLaiRen\\PageBuilder\\Queue\\AiSitePlanQueue::class => \'512M\'', $source);
        self::assertStringContainsString('\\GuoLaiRen\\PageBuilder\\Queue\\AiSiteBuildQueue::class => \'512M\'', $source);
        self::assertStringContainsString('\\GuoLaiRen\\PageBuilder\\Queue\\AiSiteAssetQueue::class => \'512M\'', $source);
        self::assertStringContainsString("'queue.worker.memory_limit_by_class.' . \$queueClass", $resolveMethodSource);
        self::assertStringContainsString('self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass]', $resolveMethodSource);
    }

    public function testQueueRunCommandAppliesClassMemoryLimitWhenStartedManually(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Console/Queue/Run.php');

        self::assertStringContainsString('applyCliMemoryLimitForQueueClass($queueClass)', $source);
        self::assertStringContainsString("'GuoLaiRen\\PageBuilder\\Queue\\AiSiteBuildQueue' => '512M'", $source);
        self::assertStringContainsString("'GuoLaiRen\\PageBuilder\\Queue\\AiSitePlanQueue' => '512M'", $source);
        self::assertStringContainsString("'GuoLaiRen\\PageBuilder\\Queue\\AiSiteAssetQueue' => '512M'", $source);
        self::assertStringContainsString('ini_set(\'memory_limit\', $target)', $source);
        self::assertStringContainsString("'queue.worker.memory_limit_by_class.' . \$queueClass", $source);
    }

    public function testQueueRunCommandPreservesExplicitRetryStateAfterExecute(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Console/Queue/Run.php');
        $executeSource = $this->extractPrivateMethodSource($source, 'execute');
        $preserveSource = $this->extractPrivateMethodSource($source, 'shouldPreserveQueueStateAfterExecute');

        self::assertStringContainsString('shouldPreserveQueueStateAfterExecute($queue)', $executeSource);
        self::assertStringContainsString('$queue::status_pending', $preserveSource);
        self::assertStringContainsString('$queue::status_error', $preserveSource);
        self::assertStringContainsString('$queue::status_stop', $preserveSource);
        self::assertStringContainsString('$queue->isFinished()', $preserveSource);
    }

    public function testQueueSchedulerConcurrencyIsConfigurable(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');

        self::assertStringContainsString("'queue.cron.max_concurrent'", $source);
        self::assertStringContainsString('public function getMaxConcurrent(): int', $source);
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

    public function testReconcileRepairsFinishedRunningQueues(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileMethodSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        self::assertStringNotContainsString('schema_fields_finished, 0', $reconcileMethodSource);
        self::assertStringContainsString('$queue->isFinished()', $reconcileMethodSource);

        $finishedOffset = \strpos($reconcileMethodSource, '$queue->isFinished()');
        $pendingOffset = \strpos($reconcileMethodSource, 'setStatus($queue::status_pending)');
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

    public function testQueueQueryProviderCanCreateOrTakeoverWithoutImmediateSchedulerWake(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php');
        $createMethodSource = $this->extractPrivateMethodSource($source, 'createQueue');
        $takeoverMethodSource = $this->extractPrivateMethodSource($source, 'takeoverQueue');
        $wakeGateSource = $this->extractPrivateMethodSource($source, 'shouldWakeScheduler');

        self::assertStringContainsString('if ($this->shouldWakeScheduler($params))', $createMethodSource);
        self::assertStringContainsString('if ($this->shouldWakeScheduler($params))', $takeoverMethodSource);
        self::assertStringContainsString("'wake_scheduler'", $wakeGateSource);
        self::assertStringContainsString("'dispatch'", $wakeGateSource);
        self::assertStringContainsString("'auto_dispatch'", $wakeGateSource);
        self::assertStringContainsString("['0', 'false', 'no', 'off']", $wakeGateSource);
        self::assertStringContainsString('return true;', $wakeGateSource);
    }

    public function testQueueQueryProviderWakesSchedulerWhenUpdateResetsAutoPendingQueue(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php');
        $updateMethodSource = $this->extractPrivateMethodSource($source, 'updateQueue');
        $dispatchableSource = $this->extractPrivateMethodSource($source, 'isQueueDispatchableForScheduler');

        self::assertStringContainsString('if ($this->shouldWakeScheduler($params) && $this->isQueueDispatchableForScheduler($queue))', $updateMethodSource);
        self::assertStringContainsString('$this->wakeSystemScheduler($queue);', $updateMethodSource);
        self::assertStringContainsString('!$queue->isFinished()', $dispatchableSource);
        self::assertStringContainsString('$queue->getAuto()', $dispatchableSource);
        self::assertStringContainsString('$queue->getStatus() === Queue::status_pending', $dispatchableSource);
    }

    public function testQueueQueryProviderUpdateKeepsQueueIdBeforeReloadingFreshRow(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php');
        $updateMethodSource = $this->extractPrivateMethodSource($source, 'updateQueue');

        $saveOffset = \strpos($updateMethodSource, '$queue->save();');
        $idOffset = \strpos($updateMethodSource, '$queueId = (int)$queue->getId();');
        $loadOffset = \strpos($updateMethodSource, '$queue->clearData()->load($queueId);');
        $returnOffset = \strpos($updateMethodSource, "'queue_id' => \$queueId");

        self::assertNotFalse($saveOffset);
        self::assertNotFalse($idOffset);
        self::assertNotFalse($loadOffset);
        self::assertNotFalse($returnOffset);
        self::assertLessThan($idOffset, $saveOffset);
        self::assertLessThan($loadOffset, $idOffset);
        self::assertLessThan($returnOffset, $loadOffset);
        self::assertStringNotContainsString('clearData()->load((int)$queue->getId())', $updateMethodSource);
    }

    public function testReconcileChecksPidLivenessAndWritesOperatorMessage(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileMethodSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        $pidAliveOffset = \strpos($reconcileMethodSource, 'Processer::isRunningByPid($queuePid)');
        $managedOffset = \strpos($reconcileMethodSource, 'Processer::isManagedProcessRunning($queuePid');

        self::assertNotFalse($pidAliveOffset);
        self::assertNotFalse($managedOffset);
        self::assertLessThan(
            $managedOffset,
            $pidAliveOffset,
            'Stale PID detection must check raw PID liveness before managed-process identity matching.'
        );
        self::assertStringContainsString('队列记录的 PID %{1} 已不存在', $source);
        self::assertStringContainsString('队列记录的 PID %{1} 仍存在', $source);
        self::assertStringContainsString('setProcess($this->appendProcessMessage($queue->getProcess(), $message))', $reconcileMethodSource);
    }

    public function testRecoverableDeadWorkersAreReturnedToSchedulerInsteadOfMarkedError(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileMethodSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        $recoverOffset = \strpos($reconcileMethodSource, 'shouldRecoverDeadWorker($queue, $queuePid, $output)');
        $errorOffset = \strpos($reconcileMethodSource, 'setStatus($queue::status_error)');

        self::assertNotFalse($recoverOffset, 'dead-worker recovery contract must be checked before terminal error handling');
        self::assertNotFalse($errorOffset, 'generic dead-worker error path missing');
        self::assertLessThan($errorOffset, $recoverOffset);
        self::assertStringContainsString('deadWorkerRecoveryMessage($queue, $queuePid, $output)', $reconcileMethodSource);
        self::assertStringContainsString('setStatus($queue::status_pending)', $reconcileMethodSource);
        self::assertStringContainsString('setFinished(false)', $reconcileMethodSource);
        self::assertStringContainsString('setPid(0)', $reconcileMethodSource);
        self::assertStringContainsString('setData(Queue::schema_fields_start_at, null)', $reconcileMethodSource);
        self::assertStringContainsString('setData(Queue::schema_fields_end_at, null)', $reconcileMethodSource);
        self::assertStringContainsString('setResult($message)', $reconcileMethodSource);
    }

    public function testDeadWorkerRecoveryUsesQueueTypeContract(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $resolverSource = $this->extractPrivateMethodSource($source, 'resolveDeadWorkerRecoverableQueue');
        $decisionSource = $this->extractPrivateMethodSource($source, 'shouldRecoverDeadWorker');

        self::assertStringContainsString('DeadWorkerRecoverableQueueInterface', $source);
        self::assertStringContainsString('ObjectManager::getInstance($queueClass)', $resolverSource);
        self::assertStringContainsString('resolveQueueClass($queue)', $resolverSource);
        self::assertStringContainsString('instanceof DeadWorkerRecoverableQueueInterface', $resolverSource);
        self::assertStringContainsString('->shouldRecoverDeadWorker($queue, $deadPid, $workerOutput)', $decisionSource);
    }

    private function extractPrivateMethodSource(string $source, string $methodName): string
    {
        $methodOffset = \strpos($source, 'private function ' . $methodName);
        if ($methodOffset === false) {
            $methodOffset = \strpos($source, 'public function ' . $methodName);
        }
        self::assertNotFalse($methodOffset, $methodName . ' missing');
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
