<?php

declare(strict_types=1);

namespace Weline\Framework\Cron;

use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupMultiSiteRunner;

/**
 * Framework cache-domain storefront FPC warm (replaces WLS Worker deferred warm).
 *
 * Lives under module Cron/ so cron:task:collect discovers it (same as ProcessGc).
 * 每次 tick 遍历所有活跃站点，逐站用独立账本 + 独立 Host 头推进一片。
 */
final class StorefrontWarmup implements CronTaskInterface
{
    public function name(): string
    {
        return __('店面缓存预热');
    }

    public function execute_name(): string
    {
        return 'framework_storefront_warmup';
    }

    public function tip(): string
    {
        return __('每分钟推进全部站点店面 FPC 预热相位 A→B(Sitemap 文档)→C(补源)→D(地图 loc)；每站独立进度账本与 Host 头；真机 busy 门禁；单片时长按站点数分摊。系统权威时钟与账本世代不一致时立即标记需要预热并从相位 A 重跑。WLS Worker 不再编排店面预热。');
    }

    public function cron_time(): string
    {
        // 预热是后台加速，无实时性要求：降为 5 分钟，避免与 FPC 抢 worker。
        return '*/5 * * * *';
    }

    /**
     * Prefer exclusive scheduling when host cron supports locked().
     */
    public function locked(): bool
    {
        return true;
    }

    public function execute(): string
    {
        $started = \microtime(true);
        try {
            $runner = new StorefrontWarmupMultiSiteRunner();
            $report = $runner->run();
            $elapsed = \round((\microtime(true) - $started), 2);

            return self::formatReport($report, $elapsed);
        } catch (\Throwable $e) {
            return 'storefront_warmup error: ' . $e->getMessage();
        }
    }

    /**
     * 单行汇总（cron.log 友好）：逐站合计 + 站点数 + 逐站错误摘要。
     *
     * @param array<string,mixed> $report
     */
    public static function formatReport(array $report, float $elapsed): string
    {
        $sites = \is_array($report['sites'] ?? null) ? $report['sites'] : [];
        $errors = [];
        foreach ($sites as $site) {
            if (!\is_array($site)) {
                continue;
            }
            $reason = (string)($site['reason'] ?? '');
            if ($reason !== '' && ($reason === 'lock_busy' || \str_starts_with($reason, 'error:'))) {
                $errors[] = (string)($site['code'] ?? '') . ':' . $reason;
            }
        }

        return \sprintf(
            'storefront_warmup source=cron scopes=%d per_scope_wall_ms=%d warmed=%d skipped=%d failed=%d site_errors=%d sites=%s elapsed=%ss',
            (int)($report['scope_count'] ?? 0),
            (int)($report['per_scope_wall_ms'] ?? 0),
            (int)($report['warmed'] ?? 0),
            (int)($report['skipped'] ?? 0),
            (int)($report['failed'] ?? 0),
            \count($errors),
            $errors === [] ? '-' : \implode(',', \array_slice($errors, 0, 6)),
            (string)$elapsed,
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return \max(5, \min(15, $minute));
    }
}
