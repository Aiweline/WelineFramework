<?php
declare(strict_types=1);

/**
 * Weline Websites - 健康检查定时任务
 * 
 * 定期检查所有网站域名的可访问性并同步 HTTPS 状态
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\HealthCheckService;

/**
 * 健康检查定时任务
 * 
 * 功能：
 * - 每 5 分钟检查所有活跃域名的健康状态
 * - 自动同步 HTTPS 状态（证书失效自动回退 HTTP）
 * - 更新健康检查结果到数据库
 */
class HealthCheck implements CronTaskInterface
{
    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'websites_health_check';
    }
    
    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return __('网站域名健康检查');
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('定期检查所有网站域名的可访问性，自动同步 HTTPS 状态');
    }
    
    /**
     * @inheritDoc
     * 
     * 每 5 分钟执行一次
     */
    public function cron_time(): string
    {
        return '*/5 * * * *';
    }
    
    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        try {
            /** @var HealthCheckService $healthService */
            $healthService = ObjectManager::getInstance(HealthCheckService::class);
            
            // 执行健康检查
            $results = $healthService->checkAllDomains();
            
            // 构建结果消息
            $message = __('健康检查完成：共 %{1} 个域名，健康 %{2} 个，不健康 %{3} 个，HTTPS 状态更新 %{4} 个', [
                $results['total'],
                $results['healthy'],
                $results['unhealthy'],
                $results['https_updated']
            ]);
            
            // 记录日志
            if ($results['unhealthy'] > 0) {
                w_log_warning('[HealthCheck] ' . $message);
            }
            
            return $message;
            
        } catch (\Throwable $e) {
            $error = __('健康检查失败：%{1}', [$e->getMessage()]);
            w_log_error('[HealthCheck] ' . $error);
            return $error;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 10; // 10 分钟超时
    }
}
