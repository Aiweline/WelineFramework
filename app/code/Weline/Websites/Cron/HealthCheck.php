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

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\HealthCheckService;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 健康检查定时任务
 *
 * 由 {@see WebsitesOperationsMaintenance} 统一调度。
 */
#[CronTestHelp(
    description: '站点域名健康检查与 HTTPS 探测（website_domain 表）。',
    examples: ['php bin/w cron:test --task=health_check --domain=www.example.com -v'],
)]
class HealthCheck
{
    use WebsitesCronTestRunnerTrait;

    public function execute(): string
    {
        try {
            /** @var HealthCheckService $healthService */
            $healthService = ObjectManager::getInstance(HealthCheckService::class);
            WebsitesCronTestContext::detail('HealthCheck.execute', ['domain_filter' => WebsitesCronTestContext::getDomainFilter()]);

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
}
