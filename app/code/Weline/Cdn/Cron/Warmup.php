<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Cron;

use Weline\Cdn\Service\WarmupCollectService;
use Weline\Cdn\Service\WarmupRunner;
use Weline\Framework\Cron\CronTaskInterface;

/**
 * CDN预热定时任务：按 Provider 分批收集后执行预热。
 *
 * @package Weline_Cdn
 */
class Warmup implements CronTaskInterface
{
    public function __construct(
        private readonly WarmupCollectService $collectService,
        private readonly WarmupRunner $warmupRunner,
    ) {
    }

    public function name(): string
    {
        return 'CDN预热任务';
    }

    public function execute_name(): string
    {
        return 'cdn_warmup';
    }

    public function tip(): string
    {
        return '按 WarmupProvider 分批收集预热 URL 并执行预热，每5分钟一次。';
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
    }

    public function execute(): string
    {
        $collect = $this->collectService->collectAllProviders();
        if (\Weline\Framework\Setup\Lock\SetupUpgradeIntent::shouldYield()) {
            return (string)__('因系统升级意图提前结束计划任务。');
        }
        $result = $this->warmupRunner->run(50);

        $summary = sprintf(
            'CDN预热完成: providers=%d inserted=%d updated=%d filtered=%d processed=%d success=%d fail=%d skipped=%d',
            $collect['providers'],
            $collect['inserted'],
            $collect['updated'],
            $collect['filtered'],
            $result['processed'],
            $result['success'],
            $result['fail'],
            $result['skipped']
        );
        if (!empty($result['yielded_for_upgrade'])) {
            $summary .= ' ' . (string)__('因系统升级意图提前结束计划任务。');
        }

        return $summary;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
