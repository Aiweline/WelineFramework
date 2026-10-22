<?php

declare(strict_types=1);

namespace Weline\Theme\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateAutoService;

/**
 * 发布后自动收敛（仅非开发模式）。
 *
 * 编辑器发布成功会写脏标记；本任务每分钟检查一次，有标记就构建新代次并原子切换。
 * 无标记时极快跳过（只 stat 目录）；构建/校验失败保留标记，下次重试，线上保持旧代次。
 *
 * 开发模式由 {@see LayoutOriginWatch} 就地自动固化/编译，本任务 skip:non_dev 反向跳过。
 */
final class UpdateAutoPublish implements CronTaskInterface
{
    public function __construct(
        private readonly ThemeUpdateAutoService $auto,
    ) {
    }

    public function name(): string
    {
        return '主题发布后自动更新代次（非开发模式）';
    }

    public function execute_name(): string
    {
        return 'theme_update_auto_publish';
    }

    public function tip(): string
    {
        return '仅 system.deploy≠dev。扫描 var/theme-update/dirty/*.json（发布时写入），'
            . '对每个主题构建更新代次→校验→翻指针→清标记；无标记快速跳过。';
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        $ids = ThemeUpdateAutoService::dirtyThemeIds();
        if ($ids === []) {
            return 'skip:no_dirty';
        }

        $done = 0;
        $skipped = 0;
        $failed = [];
        foreach ($ids as $themeId) {
            try {
                $result = $this->auto->run($themeId);
                $mode = (string)($result['mode'] ?? '');
                if ($mode === 'dev') {
                    return 'skip:dev_mode';
                }
                if (($result['skipped'] ?? '') === 'verify_failed') {
                    $failed[] = $themeId . ':verify';
                    continue;
                }
                if (($result['skipped'] ?? '') === 'no_dirty') {
                    $skipped++;
                    continue;
                }
                $done++;
            } catch (\Throwable $e) {
                $failed[] = $themeId . ':' . \substr($e->getMessage(), 0, 80);
            }
        }

        $text = \sprintf(
            'theme_update_auto_publish ok：themes=%d promoted=%d skipped=%d failed=%d',
            \count($ids),
            $done,
            $skipped,
            \count($failed)
        );

        return $failed === [] ? $text : ($text . '; ' . \implode('|', \array_slice($failed, 0, 5)));
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return \max(30, $minute);
    }
}
