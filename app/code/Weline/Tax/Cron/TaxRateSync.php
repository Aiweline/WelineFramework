<?php

declare(strict_types=1);

namespace Weline\Tax\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Tax\Service\RateSync\TaxRateAggregateSyncService;

/**
 * Daily offline tax rate aggregate sync (skipped when cron_enabled is off).
 */
class TaxRateSync implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('税率多源聚合同步');
    }

    public function execute_name(): string
    {
        return 'tax_rate_aggregate_sync';
    }

    public function tip(): string
    {
        return (string)__('每日聚合免费税率源并可选专业覆盖，写入 TaxRule');
    }

    public function cron_time(): string
    {
        return '0 3 * * *';
    }

    public function execute(): string
    {
        try {
            /** @var TaxRateAggregateSyncService $sync */
            $sync = ObjectManager::getInstance(TaxRateAggregateSyncService::class);
            if (!$sync->cronEnabled()) {
                return 'tax ratesync cron disabled; skipped';
            }
            $result = $sync->sync(0, false);
            if (!empty($result['skipped'])) {
                return 'tax ratesync skipped: ' . (string)($result['reason'] ?? 'unknown');
            }

            return sprintf(
                'tax ratesync ok merged=%d created=%d updated=%d conflict_max=%d pro_overlay=%d failed=%d',
                (int)($result['merged_count'] ?? 0),
                (int)($result['rules_created'] ?? 0),
                (int)($result['rules_updated'] ?? 0),
                (int)($result['conflict_max_count'] ?? 0),
                (int)($result['professional_overlay_count'] ?? 0),
                count((array)($result['failed_sources'] ?? [])),
            );
        } catch (\Throwable $e) {
            $msg = 'tax ratesync cron failed: ' . $e->getMessage();
            if (function_exists('w_log_error')) {
                w_log_error($msg);
            }

            return $msg;
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 30;
    }
}
