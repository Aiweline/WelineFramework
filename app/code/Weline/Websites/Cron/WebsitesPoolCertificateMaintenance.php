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
    description: '子域 HTTPS 证书维护：整点/半点校验已签发证书的 PEM；每节拍处理待申请队列。--cert_full 或 --hourly 可强制执行校验段。',
    examples: [
        'php bin/w cron:test --task=websites_pool_certificate_maintenance --domain=example.com -v --cert_full=1',
    ],
    manual_help: [
        '① 证书校验：仅整点/半点执行。检查池内 https_status=valid 的证书在服务器上是否仍有有效 PEM/文件；丢失或无效则回退为 none 并标记需重新申请。',
        '② 证书申请：每 10 分钟执行。对生命周期 origin_ready 或 cert_pending 的子域发起 ACME 申请（HTTP-01 或 DNS-01），成功后更新为可建站。',
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
        return __('① 整点/半点校验已签发证书 ② 每节拍处理待申请队列');
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
        $minute = (int) \date('i');
        // 原 Verify 为 */30，在整点与半点附近执行校验，其余节拍仅跑申请
        if ($minute === 0 || $minute === 30 || WebsitesCronTestContext::forcePoolCertVerify()) {
            try {
                $parts[] = '[1/2 ' . __('① 证书校验') . '] ' . (new DomainPoolCertificateVerify())->execute();
            } catch (\Throwable $e) {
                $parts[] = '[1/2] ' . $e->getMessage();
                w_log_error('[websites_pool_certificate_maintenance] verify: ' . $e->getMessage(), [], 'domain_pool_cert');
            }
        } else {
            $parts[] = '[1/2 ' . __('① 证书校验') . '] ' . (string) __('本节拍跳过（整点/半点执行）');
        }
        try {
            $parts[] = '[2/2 ' . __('② 证书申请') . '] ' . (new DomainPoolCertificateRequest())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[2/2] ' . $e->getMessage();
            w_log_error('[websites_pool_certificate_maintenance] request: ' . $e->getMessage(), [], 'domain_pool_cert');
        }

        return \implode("\n---\n", $parts);
    }
}
