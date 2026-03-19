<?php
declare(strict_types=1);

/**
 * 合并调度：站点运维四块
 * ① DNS/CDN 自动切换（购买后待切换队列）
 * ② 已绑定站点的域名健康检查（连通性探测 + 按证书管理表同步 HTTPS 开关，二者解耦）
 * ③ 整点：根域 NS 归属检测与 DNS 服务商识别
 * ④ 整点：按证书管理表同步站点 HTTPS 开关（不依赖 HTTPS 请求校验证书）
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Cron\CronTaskInterface;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: '站点运维四块：① DNS/CDN 切换 ② 站点域名健康检查 ③ 整点 NS 检测 ④ 整点 HTTPS 同步。--hourly 可立即执行 ③④。',
    examples: [
        'php bin/w cron:test --task=websites_operations_maintenance --domain=example.com -v --hourly',
    ],
    manual_help: [
        '① 每 5 分钟：处理购买后待切换 DNS/CDN 的根域（DnsSwitchService NS 预检 + 可选 CDN verify）。',
        '② 每 5 分钟：健康检查更新可访问性；根域建站锁定（cron_resolved）的站点绑定域名可跳过探测与写库（见 HealthCheckService）。',
        '③ 仅整点：根域 NS 检测；cron_resolved 的根域跳过。',
        '④ 仅整点：HttpsSync；建站锁定的绑定域名跳过。',
    ],
)]
class WebsitesOperationsMaintenance implements CronTaskInterface
{
    use WebsitesCronTestRunnerTrait;

    public function name(): string
    {
        return __('站点运维（DNS 切换 + 健康检查 + NS/HTTPS）');
    }

    public function execute_name(): string
    {
        return 'websites_operations_maintenance';
    }

    public function tip(): string
    {
        return __('① DNS/CDN 切换 ② 站点健康检查 ③ 整点 NS 检测 ④ 整点 HTTPS 同步');
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
