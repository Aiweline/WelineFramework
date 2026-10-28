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

use Weline\Framework\Cron\Attribute\CronTestHelp;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Lock\SetupUpgradeIntent;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\StaticErrorPageMaintenanceService;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: '站点运维五块：① DNS/CDN 切换 ② 站点域名健康检查（增量） ③ 整点 NS 检测 ④ 整点 HTTPS 同步 ⑤ 404/维护静态页（7 天）。--hourly 可立即执行 ③④。',
    examples: [
        'php bin/w cron:test --task=websites_operations_maintenance --domain=example.com -v --hourly',
    ],
    manual_help: [
        '① 每 5 分钟：处理购买后待切换 DNS/CDN 的根域（DnsSwitchService NS 预检 + 可选 CDN verify）。',
        '② 每 5 分钟：健康检查只探「超过 websites.health_check_fresh_hours（默认 6 小时）未检查」的域名，避免全量重探。',
        '③ 仅整点：根域 NS 检测；cron_resolved 的根域跳过。',
        '④ 仅整点：HttpsSync；建站锁定的绑定域名跳过。',
        '⑤ 距上次成功发布 ≥ websites.static_error_pages_refresh_days（默认 7 天）时，重发前台 404 与维护静态页。',
    ],
)]
class WebsitesOperationsMaintenance implements CronTaskInterface
{
    use WebsitesCronTestRunnerTrait;

    public function name(): string
    {
        return __('站点运维（DNS 切换 + 健康检查 + NS/HTTPS + 静态错误页）');
    }

    public function execute_name(): string
    {
        return 'websites_operations_maintenance';
    }

    public function tip(): string
    {
        return __('① DNS/CDN 切换 ② 站点健康检查（增量） ③ 整点 NS 检测 ④ 整点 HTTPS 同步 ⑤ 404/维护静态页（7 天）');
    }

    /**
     * 健康检查的新鲜窗口（小时）；0 表示不增量（保守全量）。
     */
    private function healthFreshHours(): int
    {
        try {
            $hours = (int)\Weline\Framework\App\Env::getInstance()->getConfig('websites.health_check_fresh_hours', 6);
        } catch (\Throwable) {
            return 6;
        }

        return \max(0, $hours);
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
        if (SetupUpgradeIntent::shouldYield()) {
            $parts[] = (string)__('因系统升级意图提前结束计划任务。');
            return \implode("\n---\n", $parts);
        }
        try {
            $parts[] = '[2] ' . __('健康检查') . ': ' . (new HealthCheck())->execute($this->healthFreshHours());
        } catch (\Throwable $e) {
            $parts[] = '[2] ' . $e->getMessage();
            w_log_error('[websites_operations_maintenance] health: ' . $e->getMessage(), [], 'websites_operations_maintenance');
        }
        if (SetupUpgradeIntent::shouldYield()) {
            $parts[] = (string)__('因系统升级意图提前结束计划任务。');
            return \implode("\n---\n", $parts);
        }
        $minute = (int) \date('i');
        if ($minute === 0 || WebsitesCronTestContext::forceHourlyAddons()) {
            try {
                $parts[] = '[3] NS: ' . (new DomainNsCheck())->execute();
            } catch (\Throwable $e) {
                $parts[] = '[3] ' . $e->getMessage();
                w_log_error('[websites_operations_maintenance] ns: ' . $e->getMessage(), [], 'websites_operations_maintenance');
            }
            if (SetupUpgradeIntent::shouldYield()) {
                $parts[] = (string)__('因系统升级意图提前结束计划任务。');
                return \implode("\n---\n", $parts);
            }
            try {
                $parts[] = '[4] HTTPS: ' . (new HttpsSync())->execute();
            } catch (\Throwable $e) {
                $parts[] = '[4] ' . $e->getMessage();
                w_log_error('[websites_operations_maintenance] https: ' . $e->getMessage(), [], 'websites_operations_maintenance');
            }
        }

        try {
            /** @var StaticErrorPageMaintenanceService $staticPages */
            $staticPages = ObjectManager::getInstance(StaticErrorPageMaintenanceService::class);
            if ($staticPages->isDue()) {
                $report = $staticPages->publish();
                $parts[] = '[5] ' . __('静态错误页') . ': ' . \sprintf(
                    'not_found=%d maintenance=%d failed=%d',
                    (int)$report['not_found'],
                    (int)$report['maintenance'],
                    (int)$report['failed'],
                ) . ($report['messages'] === [] ? '' : ' ' . \implode('|', $report['messages']));
            } else {
                $parts[] = '[5] ' . __('静态错误页：未到刷新时间，跳过');
            }
        } catch (\Throwable $e) {
            $parts[] = '[5] ' . $e->getMessage();
            w_log_error('[websites_operations_maintenance] static_error_pages: ' . $e->getMessage(), [], 'websites_operations_maintenance');
        }

        return \implode("\n---\n", $parts);
    }
}
