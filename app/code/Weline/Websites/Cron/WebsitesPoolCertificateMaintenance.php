<?php
declare(strict_types=1);

/**
 * 合并调度：子域 HTTPS 证书两段维护
 * 1. 每 10 分钟先校验已签发证书是否仍然健康
 * 2. 再处理待申请队列，让无效证书在同一轮进入重新申请
 */

namespace Weline\Websites\Cron;

use Weline\Framework\Cron\Attribute\CronTestHelp;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: '子域 HTTPS 证书维护：每 10 分钟先校验已签发证书是否仍健康，再处理待申请队列；只要域名证书无效，就会在同一轮进入重新申请。',
    examples: [
        'php bin/w cron:test --task=websites_pool_certificate_maintenance --domain=example.com -v',
        'php bin/w cron:test --task=websites_certificate_health_daily --domain=example.com -v',
    ],
    manual_help: [
        '① 证书校验：高频执行 DomainPoolCertificateVerify，发现无效证书时立即回退到可申请状态。',
        '② 证书申请：紧接着执行 DomainPoolCertificateRequest，让刚被回退的域名在同一轮继续申请。',
        '每日任务 websites_certificate_health_daily 仍保留，作为补充巡检入口。',
    ],
)]
class WebsitesPoolCertificateMaintenance implements CronTaskInterface
{
    public function name(): string
    {
        return __('子域 HTTPS 证书维护');
    }

    public function execute_name(): string
    {
        return 'websites_pool_certificate_maintenance';
    }

    public function tip(): string
    {
        return __('每 10 分钟先校验证书有效性，再处理待申请队列');
    }

    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 45;
    }

    /**
     * @param array<string, mixed> $options cert_full / hourly 保留兼容旧测试参数
     */
    public function test(array $options): string
    {
        $domain = isset($options['domain']) ? (string) $options['domain'] : null;
        if ($domain === '') {
            $domain = null;
        }
        $verbose = !empty($options['verbose']);
        $full = !empty($options['cert_full']) || !empty($options['hourly']);
        WebsitesCronTestContext::begin($domain, $verbose, false);
        if ($full) {
            WebsitesCronTestContext::setForcePoolCertVerify(true);
        }
        try {
            return $this->execute();
        } finally {
            WebsitesCronTestContext::setForcePoolCertVerify(false);
            WebsitesCronTestContext::end();
        }
    }

    public function execute(): string
    {
        $parts = [];

        try {
            $parts[] = '[1/2 ' . __('① 证书校验') . '] ' . $this->runCertificateVerify();
        } catch (\Throwable $e) {
            $parts[] = '[1/2] ' . $e->getMessage();
            w_log_error('[websites_pool_certificate_maintenance] verify: ' . $e->getMessage(), [], 'domain_pool_cert');
        }

        try {
            $parts[] = '[2/2 ' . __('② 证书申请') . '] ' . $this->runCertificateRequest();
        } catch (\Throwable $e) {
            $parts[] = '[2/2] ' . $e->getMessage();
            w_log_error('[websites_pool_certificate_maintenance] request: ' . $e->getMessage(), [], 'domain_pool_cert');
        }

        return \implode("\n---\n", $parts);
    }

    protected function runCertificateVerify(): string
    {
        return (new DomainPoolCertificateVerify())->execute();
    }

    protected function runCertificateRequest(): string
    {
        return (new DomainPoolCertificateRequest())->execute();
    }
}
