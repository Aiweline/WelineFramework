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
 * 由 {@see WebsitesOperationsMaintenance} 调用。
 */
#[CronTestHelp(
    description: '已绑定站点的域名健康检查：对 website_domain 表中的域名做 HTTP(S) 探测，更新健康状态；证书失效时自动回退 HTTPS 为 HTTP。',
    examples: ['php bin/w cron:test --task=health_check --domain=www.example.com -v'],
    manual_help: [
        '逻辑：取已绑定网站的域名列表，逐个请求 HTTP 或 HTTPS；2xx/3xx 视为健康。若期望 HTTPS 但证书失败则尝试 HTTP，成功则更新为「已回退 HTTP」并同步数据库 HTTPS 开关。',
        '每条记录探测后同步更新关联的 DomainPool（pool_id）与根域 Domain：connectivity_* 来自探测；https_status、cert_id（池）仅以 SSL 证书管理表解析，与 HTTPS 请求结果无关。根域在「主机名=根域」时同步根域 https_status（同源）。',
        '--domain= 仅检查包含该域名的站点记录。',
    ],
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
            $message = __('健康检查完成：共 %{1} 个域名，健康 %{2} 个，不健康 %{3} 个，HTTPS 状态更新 %{4} 个，根域/池同步 %{5} 条', [
                $results['total'],
                $results['healthy'],
                $results['unhealthy'],
                $results['https_updated'],
                $results['infra_synced'] ?? 0,
            ]);
            if (($results['skipped_cron_lock'] ?? 0) > 0) {
                $message .= ' ' . __('（建站锁定跳过 %{1} 个）', [(string) (int) $results['skipped_cron_lock']]);
            }
            
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
