<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Cron\UsageAuditRecovery;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Framework\Cron\CronTaskInterface;

final class CapturingUsageAuditRecoveryAccountService extends AccountService
{
    public int $capturedLimit = 0;

    public function recoverDeferredUsage(int $limit = 50): array
    {
        $this->capturedLimit = $limit;

        return ['recovered' => 2, 'failed' => 1, 'dead' => 0, 'skipped' => 3];
    }
}

final class UsageAuditRecoveryCronTest extends TestCase
{
    public function testSchedulerTaskIsDiscoverableAndUsesTheFixedFiftyEventBound(): void
    {
        $accountService = new CapturingUsageAuditRecoveryAccountService();
        $task = new UsageAuditRecovery($accountService);

        self::assertInstanceOf(CronTaskInterface::class, $task);
        self::assertSame('* * * * *', $task->cron_time());
        self::assertSame('ai_provider_usage_audit_recovery', $task->execute_name());
        $result = $task->execute();

        self::assertSame(50, $accountService->capturedLimit);
        self::assertStringContainsString('2', $result);
        self::assertStringContainsString('1', $result);
        self::assertStringContainsString('3', $result);
    }
}
