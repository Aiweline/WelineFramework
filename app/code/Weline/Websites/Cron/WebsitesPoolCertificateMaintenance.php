<?php
declare(strict_types=1);

/**
 * 合并调度：子域 HTTPS 证书两段维护
 * ① 证书校验：整点/半点校验已签发证书的 PEM 与文件，无效则回退状态并触发重新申请
 * ② 证书申请：每节拍处理待申请队列（origin_ready/cert_pending 子域，ACME 单域/泛域）
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Cron\CronTaskInterface;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: '子域 HTTPS 证书申请队列：每 10 分钟处理 origin_ready/cert_pending。PEM/文件校验已迁至每日任务 websites_certificate_health_daily；--cert_full 仅兼容旧参数。',
    examples: [
        'php bin/w cron:test --task=websites_pool_certificate_maintenance --domain=example.com -v',
        'php bin/w cron:test --task=websites_certificate_health_daily --domain=example.com -v',
    ],
    manual_help: [
        '① 证书校验：见 websites_certificate_health_daily（每日，与 SSL 续期策略一致）。',
        '② 证书申请：每 10 分钟。根域 cron_resolved=1 时不再申请；父域 dns_cutover_complete=0 时队列不取该池（DnsSwitchService 成功后置 1）。',
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
        return __('每日校验已迁出；本任务每节拍处理待申请队列');
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
     * @param array<string, mixed> $options cert_full / hourly 为真时强制执行证书校验段
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
        // PEM/文件校验已拆至每日任务 {@see WebsitesCertificateHealthDaily}，本任务仅保留高频证书申请队列
        $parts[] = '[1/2 ' . __('① 证书校验') . '] ' . (string) __('已迁至每日任务 websites_certificate_health_daily（与 SSL 续期策略一致）');
        try {
            $parts[] = '[2/2 ' . __('② 证书申请') . '] ' . (new DomainPoolCertificateRequest())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[2/2] ' . $e->getMessage();
            w_log_error('[websites_pool_certificate_maintenance] request: ' . $e->getMessage(), [], 'domain_pool_cert');
        }

        return \implode("\n---\n", $parts);
    }
}
