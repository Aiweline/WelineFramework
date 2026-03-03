<?php
declare(strict_types=1);

/**
 * Weline Websites - HTTPS 状态同步定时任务
 * 
 * 根据证书有效性自动同步域名的 HTTPS 状态
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\HealthCheckService;

/**
 * HTTPS 状态同步定时任务
 * 
 * 功能：
 * - 每小时检查证书有效性
 * - 证书有效则启用 HTTPS
 * - 证书无效/过期则自动回退到 HTTP
 */
class HttpsSync implements CronTaskInterface
{
    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'websites_https_sync';
    }
    
    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return __('网站 HTTPS 状态同步');
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('根据证书有效性自动同步域名的 HTTPS 状态，证书失效自动回退 HTTP');
    }
    
    /**
     * @inheritDoc
     * 
     * 每小时执行一次
     */
    public function cron_time(): string
    {
        return '0 * * * *';
    }
    
    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        try {
            /** @var HealthCheckService $healthService */
            $healthService = ObjectManager::getInstance(HealthCheckService::class);
            
            // 同步 HTTPS 状态
            $results = $healthService->syncAllHttpsStatus();
            
            // 构建结果消息
            $message = \sprintf(
                __('HTTPS 状态同步完成：共 %{1} 个域名，启用 %{2} 个，禁用 %{3} 个，未变化 %{4} 个'),
                $results['total'],
                $results['https_enabled'],
                $results['https_disabled'],
                $results['unchanged']
            );
            
            // 如果有状态变化，记录日志
            if ($results['https_enabled'] > 0 || $results['https_disabled'] > 0) {
                w_log_info('[HttpsSync] ' . $message);
            }
            
            return $message;
            
        } catch (\Throwable $e) {
            $error = __('HTTPS 状态同步失败：%{1}', [$e->getMessage()]);
            w_log_error('[HttpsSync] ' . $error);
            return $error;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 15; // 15 分钟超时
    }
}
