<?php

declare(strict_types=1);

namespace Weline\Ai\Cron;

use Weline\Ai\Service\Provider\AccountService;
use Weline\Framework\Cron\CronTaskInterface;

/**
 * Bounded recovery for provider usage records deferred outside SQLite.
 */
final class UsageAuditRecovery implements CronTaskInterface
{
    private const BATCH_LIMIT = 50;

    public function __construct(private readonly AccountService $accountService)
    {
    }

    public function name(): string
    {
        return (string)__('AI用量审计恢复');
    }

    public function execute_name(): string
    {
        return 'ai_provider_usage_audit_recovery';
    }

    public function tip(): string
    {
        return (string)__('恢复因数据库繁忙而延迟的AI供应商用量审计');
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        $result = $this->accountService->recoverDeferredUsage(self::BATCH_LIMIT);

        return (string)__('AI用量审计恢复完成：成功 %{1}，失败 %{2}，隔离 %{3}，跳过 %{4}', [
            $result['recovered'],
            $result['failed'],
            $result['dead'],
            $result['skipped'],
        ]);
    }

    public function unlock_timeout(int $minute = 2): int
    {
        return $minute;
    }
}
