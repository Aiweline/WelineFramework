<?php
declare(strict_types=1);

/**
 * 合并调度：证书 PEM 校验（原约 30 分钟一次）+ 待申请证书队列（原 5 分钟一次）
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Cron\CronTaskInterface;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: '证书 PEM 校验 + 待申请队列。测试时加 --cert_full 或 --hourly 可强制执行校验段（否则非整点/半点会跳过校验）。',
    examples: [
        'php bin/w cron:test --task=websites_pool_certificate_maintenance --domain=example.com -v --cert_full=1',
    ],
    manual_help: [
        '整点/半点跑 PEM 校验段，其余节拍仅证书申请；与定时逻辑一致。',
        '控制台调试：cron:test 可加 --domain=、-v、--cert_full=1 或 --hourly。',
        '后台「后缀」写入 WELINE_CRON_MANUAL_ARGS；本任务 execute() 未解析时与留空相同。',
    ],
)]
class WebsitesPoolCertificateMaintenance implements CronTaskInterface
{
    public function name(): string
    {
        return __('域名池证书维护');
    }

    public function execute_name(): string
    {
        return 'websites_pool_certificate_maintenance';
    }

    public function tip(): string
    {
        return __('合并：校验池内有效证书的 PEM/文件；为 origin_ready 等状态申请证书');
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
                $parts[] = '[1/2 ' . __('证书校验') . '] ' . (new DomainPoolCertificateVerify())->execute();
            } catch (\Throwable $e) {
                $parts[] = '[1/2] ' . $e->getMessage();
                w_log_error('[websites_pool_certificate_maintenance] verify: ' . $e->getMessage(), [], 'domain_pool_cert');
            }
        } else {
            $parts[] = '[1/2 ' . __('证书校验') . '] ' . (string) __('本节拍跳过（整点/半点执行）');
        }
        try {
            $parts[] = '[2/2 ' . __('证书申请') . '] ' . (new DomainPoolCertificateRequest())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[2/2] ' . $e->getMessage();
            w_log_error('[websites_pool_certificate_maintenance] request: ' . $e->getMessage(), [], 'domain_pool_cert');
        }

        return \implode("\n---\n", $parts);
    }
}
