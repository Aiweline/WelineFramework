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

    public function testPageBuilderBuildQueueUsesDedicatedOneGigabyteMemoryLimit(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $resolveMethodSource = $this->extractPrivateMethodSource($source, 'resolveWorkerMemoryLimit');

        self::assertStringContainsString('DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS', $source);
        self::assertStringContainsString('\\GuoLaiRen\\PageBuilder\\Queue\\AiSitePlanQueue::class => \'1G\'', $source);
        self::assertStringContainsString('\\GuoLaiRen\\PageBuilder\\Queue\\AiSiteBuildQueue::class => \'1G\'', $source);
        self::assertStringContainsString('\\GuoLaiRen\\PageBuilder\\Queue\\AiSiteAssetQueue::class => \'1G\'', $source);
        self::assertStringContainsString("'queue.worker.memory_limit_by_class.' . \$queueClass", $resolveMethodSource);
        self::assertStringContainsString('self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass]', $resolveMethodSource);
    }

    public function testQueueRunCommandAppliesClassMemoryLimitWhenStartedManually(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Console/Queue/Run.php');

        self::assertStringContainsString('applyCliMemoryLimitForQueueClass($queueClass)', $source);
        self::assertStringContainsString("'GuoLaiRen\\PageBuilder\\Queue\\AiSiteBuildQueue' => '1G'", $source);
        self::assertStringContainsString("'GuoLaiRen\\PageBuilder\\Queue\\AiSitePlanQueue' => '1G'", $source);
        self::assertStringContainsString("'GuoLaiRen\\PageBuilder\\Queue\\AiSiteAssetQueue' => '1G'", $source);
        self::assertStringContainsString('ini_set(\'memory_limit\', $target)', $source);
        self::assertStringContainsString("'queue.worker.memory_limit_by_class.' . \$queueClass", $source);
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
