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

use Weline\Framework\Cron\Attribute\CronTestHelp;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\HealthCheckService;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * HTTPS 状态同步定时任务
 * 
 * 功能：
 * - 每小时检查证书有效性
 * - 证书有效则启用 HTTPS
 * - 证书无效/过期则自动回退到 HTTP
 */
/**
 * 由 {@see WebsitesOperationsMaintenance} 整点调用。
 */
#[CronTestHelp(
    description: '按证书有效性同步站点 HTTPS 开关：检查 website_domain 各条绑定的证书是否仍有效；有效则启用 HTTPS，无效/过期则关闭 HTTPS（回退 HTTP）。',
    examples: ['php bin/w cron:test --task=https_sync --domain=www.example.com -v'],
    manual_help: [
        '逻辑：遍历已绑定网站的域名，根据关联证书的 PEM/有效期判断；hasValidCertificate 与当前 https_enabled 不一致时，写库同步（启用或回退）。不发起 HTTP 请求，仅读证书状态。',
        '--domain= 仅同步包含该域名的站点记录。',
    ],
)]
class HttpsSync
{
    use WebsitesCronTestRunnerTrait;

    public function execute(): string
    {
        try {
            /** @var HealthCheckService $healthService */
            $healthService = ObjectManager::getInstance(HealthCheckService::class);
            WebsitesCronTestContext::detail('HttpsSync.execute', ['domain_filter' => WebsitesCronTestContext::getDomainFilter()]);

            // 同步 HTTPS 状态
            $results = $healthService->syncAllHttpsStatus();
            
            // 构建结果消息（占位符由 __() 替换，禁止再包一层 sprintf，否则 %{n} 会触发 sprintf 的 Unknown format specifier）
            $message = __('HTTPS 状态同步完成：共 %{1} 个域名，启用 %{2} 个，禁用 %{3} 个，未变化 %{4} 个', [
                $results['total'],
                $results['https_enabled'],
                $results['https_disabled'],
                $results['unchanged'],
            ]);
            if (($results['skipped_cron_lock'] ?? 0) > 0) {
                $message .= ' ' . (string) __('（建站锁定跳过 %{1} 个）', [(string) (int) $results['skipped_cron_lock']]);
            }
            
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
}
