<?php

namespace Weline\Visitor\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Setup\Lock\SetupUpgradeIntent;
use Weline\Visitor\Model\Pixel as PixelModel;
use Weline\Visitor\Service\PixelCronSourceCompatService;

class Pixel implements CronTaskInterface
{
    /** Wall-clock budget for one cron tick (seconds). */
    private const RUN_BUDGET_SECONDS = 45;

    /**
     * @inheritDoc
     */
    function name(): string
    {
        return 'Pixel';
    }

    /**
     * @inheritDoc
     */
    function execute_name(): string
    {
        return 'pixel';
    }

    /**
     * @inheritDoc
     */
    function tip(): string
    {
        return '标记像素处理状态并同步渠道码到 source（旧 referer 反写默认停用）';
    }

    /**
     * @inheritDoc
     */
    function cron_time(): string
    {
        return '*/10 * * * *';
    }

    /**
     * @inheritDoc
     */
    function execute(): string
    {
        /** @var PixelCronSourceCompatService $compat */
        $compat = w_obj(PixelCronSourceCompatService::class);
        $started = microtime(true);
        $batches = 0;
        $agg = [
            'total' => 0,
            'updated' => 0,
            'synced' => 0,
            'legacy' => 0,
            'skipped' => 0,
            'failed' => 0,
            'legacy_enabled' => false,
        ];

        while ((microtime(true) - $started) < self::RUN_BUDGET_SECONDS) {
            if (SetupUpgradeIntent::shouldYield()) {
                break;
            }
            $rows = PixelModel::getUnDeaPixels(null, PixelModel::UNDEALT_BATCH_DEFAULT);
            if ($rows === []) {
                break;
            }
            $stat = $compat->process($rows);
            $batches++;
            $agg['total'] += (int)$stat['total'];
            $agg['updated'] += (int)$stat['updated'];
            $agg['synced'] += (int)$stat['synced'];
            $agg['legacy'] += (int)$stat['legacy'];
            $agg['skipped'] += (int)$stat['skipped'];
            $agg['failed'] += (int)$stat['failed'];
            $agg['legacy_enabled'] = (bool)$stat['legacy_enabled'];
            // Partial batch means backlog cleared for this tick.
            if (count($rows) < PixelModel::UNDEALT_BATCH_DEFAULT) {
                break;
            }
        }

        return sprintf(
            'ok batches=%d total=%d updated=%d synced=%d legacy=%d skipped=%d failed=%d legacy_enabled=%s',
            $batches,
            $agg['total'],
            $agg['updated'],
            $agg['synced'],
            $agg['legacy'],
            $agg['skipped'],
            $agg['failed'],
            $agg['legacy_enabled'] ? '1' : '0'
        );
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
