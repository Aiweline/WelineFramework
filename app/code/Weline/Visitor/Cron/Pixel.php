<?php

namespace Weline\Visitor\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Visitor\Service\PixelCronSourceCompatService;

class Pixel implements CronTaskInterface
{

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
        $rows = \Weline\Visitor\Model\Pixel::getUnDeaPixels();
        /**@var PixelCronSourceCompatService $compat */
        $compat = w_obj(PixelCronSourceCompatService::class);
        $stat = $compat->process($rows);

        return sprintf(
            'ok total=%d updated=%d synced=%d legacy=%d skipped=%d failed=%d legacy_enabled=%s',
            $stat['total'],
            $stat['updated'],
            $stat['synced'],
            $stat['legacy'],
            $stat['skipped'],
            $stat['failed'],
            $stat['legacy_enabled'] ? '1' : '0'
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