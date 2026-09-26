<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

/**
 * 部署生命周期共享：删除 theme-layout-entities 派生磁盘并失效店面缓存。
 *
 * 调用方：setup:upgrade / deploy:upgrade / core:update 的 after Observer。
 * 同进程只执行一次（setup 内嵌 deploy:upgrade 时避免连清两次）。
 */
final class ThemeLayoutEntityUpgradePurgeService
{
    private static bool $hasRun = false;

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
        private readonly Printing $printing,
    ) {
    }

    /**
     * @param non-empty-string $invalidationReason ThemeRuntimeCacheCleaner 原因标记
     * @return int 删除的节点数；本进程已跑过则 0
     */
    public function runOnce(string $invalidationReason): int
    {
        if (self::$hasRun) {
            return 0;
        }
        self::$hasRun = true;

        $reason = trim($invalidationReason) !== ''
            ? trim($invalidationReason)
            : 'layout_entities_invalidated';

        $this->printing->note((string)__('正在删除主题布局固化磁盘产物（theme-layout-entities）…'));

        $deleted = $this->paths->purgeAllEntities();
        $this->printing->success((string)__(
            '主题布局固化磁盘已清理：删除节点 %{count}',
            ['count' => $deleted]
        ));

        $result = $this->cacheCleaner->clearAllThemeRelatedCaches(null, $reason);
        $failures = \is_array($result['failures'] ?? null) ? $result['failures'] : [];
        if ($failures !== []) {
            $detail = [];
            foreach ($failures as $step => $message) {
                $detail[] = $step . '=' . $message;
            }
            throw new \RuntimeException(
                'layout_entities_cache_clear_partial_failure:' . \implode(';', $detail)
            );
        }

        $this->printing->success((string)__('主题布局固化失效后的店面缓存清理完成'));

        return $deleted;
    }

    /** @internal UT / fixture */
    public static function resetHasRunFlag(): void
    {
        self::$hasRun = false;
    }
}
