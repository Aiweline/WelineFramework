<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名同步定时任务
 *
 * 每 15 分钟自动同步所有启用账户的域名数据
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\DomainSyncService;

class DomainSync implements CronTaskInterface
{
    /**
     * 任务名称（用于系统显示）
     */
    public function name(): string
    {
        return __('域名自动同步');
    }

    /**
     * 执行名称（唯一标识）
     */
    public function execute_name(): string
    {
        return 'domain_sync';
    }

    /**
     * 任务描述
     */
    public function tip(): string
    {
        return __('定期从域名商 API 同步域名数据到本地数据库');
    }

    /**
     * Cron 表达式：每 15 分钟执行一次
     */
    public function cron_time(): string
    {
        return '*/15 * * * *';
    }

    /**
     * 执行同步任务
     */
    public function execute(): string
    {
        try {
            /** @var DomainSyncService $syncService */
            $syncService = ObjectManager::getInstance(DomainSyncService::class);

            $result = $syncService->syncAllAccounts();

            $message = $result['message'];

            if ($result['success']) {
                w_log_info("同步成功: {$message}", [], 'domain_sync_cron');
            } else {
                w_log_warning("同步部分失败: {$message}", [], 'domain_sync_cron');
            }

            $details = [];
            foreach ($result['results'] ?? [] as $accountId => $accountResult) {
                $details[] = "账户{$accountId}: " . ($accountResult['message'] ?? 'unknown');
            }

            if ($details !== []) {
                $message .= "\n详情:\n" . \implode("\n", $details);
            }

            return $message;
        } catch (\Throwable $e) {
            $errorMsg = '域名同步任务异常: ' . $e->getMessage();
            w_log_error($errorMsg, [], 'domain_sync_cron');
            return $errorMsg;
        }
    }

    /**
     * 超时解锁时间（分钟）
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
