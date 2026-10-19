<?php

declare(strict_types=1);

namespace Weline\Framework\Cron;

use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOrchestrator;
use Weline\Framework\Manager\ObjectManager;

/**
 * Framework cache-domain storefront FPC warm (replaces WLS Worker deferred warm).
 *
 * Lives under module Cron/ so cron:task:collect discovers it (same as ProcessGc).
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
        return __('每分钟推进店面 FPC 预热相位 A→B(Sitemap 文档)→C(补源)→D(地图 loc)；真机 busy 门禁；进度账本续跑。WLS Worker 不再编排店面预热。');
    }

    public function cron_time(): string
    {
        return '* * * * *';
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
            /** @var StorefrontWarmupOrchestrator $orchestrator */
            $orchestrator = ObjectManager::getInstance(StorefrontWarmupOrchestrator::class);
            $report = $orchestrator->runSlice(['source' => 'cron']);
            $elapsed = \round((\microtime(true) - $started), 2);

            return \sprintf(
                'storefront_warmup source=%s phase=%s reason=%s warmed=%d skipped=%d failed=%d band=%s designed_conc=%d designed_shard=%d effective_conc=%d effective_shard=%d idle_ratio=%.3f remaining=%d yield=%d elapsed=%ss',
                (string)($report['source'] ?? 'cron'),
                (string)($report['phase'] ?? ''),
                (string)($report['reason'] ?? ''),
                (int)($report['warmed'] ?? 0),
                (int)($report['skipped'] ?? 0),
                (int)($report['failed'] ?? 0),
                (string)($report['band'] ?? ($report['capacity']['band'] ?? '')),
                (int)($report['designed_conc'] ?? ($report['capacity']['designed_concurrency'] ?? 0)),
                (int)($report['designed_shard'] ?? ($report['capacity']['designed_shard'] ?? 0)),
                (int)($report['effective_conc'] ?? ($report['capacity']['effective_concurrency'] ?? 0)),
                (int)($report['effective_shard'] ?? ($report['capacity']['effective_shard'] ?? 0)),
                (float)($report['idle_ratio'] ?? (1.0 - (float)($report['capacity']['ratio'] ?? 0))),
                (int)($report['remaining'] ?? ($report['capacity']['remaining'] ?? 0)),
                (($report['reason'] ?? '') === 'yield_traffic' || ($report['band'] ?? '') === 'yield') ? 1 : 0,
                (string)$elapsed,
            );
        } catch (\Throwable $e) {
            return 'storefront_warmup error: ' . $e->getMessage();
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return \max(5, \min(15, $minute));
    }
}
