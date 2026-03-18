<?php
declare(strict_types=1);

/**
 * 合并调度：DNS/CDN 自动切换、站点健康检查；每小时整点附带 NS 检测与根域 HTTPS 同步
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Cron\CronTaskInterface;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: 'DNS/CDN 切换、健康检查；加 hourly=true（--hourly）时强制执行 NS 检测与 HTTPS 同步（不等整点）。',
    examples: [
        'php bin/w cron:test --task=websites_operations_maintenance --domain=example.com -v --hourly',
    ],
)]
class WebsitesOperationsMaintenance implements CronTaskInterface
{
    use WebsitesCronTestRunnerTrait;

    public function name(): string
    {
        return __('站点运维（合并）');
    }

    public function execute_name(): string
    {
        return 'websites_operations_maintenance';
    }

    public function tip(): string
    {
        return __('DNS/CDN 切换、健康检查；整点执行 NS 归属检测与 HTTPS 状态同步');
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 25;
    }

    public function execute(): string
    {
        $parts = [];
        try {
            $parts[] = '[1] DNS/CDN: ' . (new DnsCdnAutoSwitch())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[1] ' . $e->getMessage();
            w_log_error('[websites_operations_maintenance] dns_cdn: ' . $e->getMessage(), [], 'websites_operations_maintenance');
        }
        try {
            $parts[] = '[2] ' . __('健康检查') . ': ' . (new HealthCheck())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[2] ' . $e->getMessage();
            w_log_error('[websites_operations_maintenance] health: ' . $e->getMessage(), [], 'websites_operations_maintenance');
        }
        $minute = (int) \date('i');
        if ($minute === 0 || WebsitesCronTestContext::forceHourlyAddons()) {
            try {
                $parts[] = '[3] NS: ' . (new DomainNsCheck())->execute();
            } catch (\Throwable $e) {
                $parts[] = '[3] ' . $e->getMessage();
                w_log_error('[websites_operations_maintenance] ns: ' . $e->getMessage(), [], 'websites_operations_maintenance');
            }
            try {
                $parts[] = '[4] HTTPS: ' . (new HttpsSync())->execute();
            } catch (\Throwable $e) {
                $parts[] = '[4] ' . $e->getMessage();
                w_log_error('[websites_operations_maintenance] https: ' . $e->getMessage(), [], 'websites_operations_maintenance');
            }
        }

        return \implode("\n---\n", $parts);
    }
}
