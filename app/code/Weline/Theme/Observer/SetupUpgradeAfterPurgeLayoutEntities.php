<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

/**
 * setup:upgrade 后删除全部布局固化磁盘产物，避免源模板已改仍直读旧 shell/layout。
 *
 * 恢复主路径：当前激活主题首访 {@see \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::dynamicSolidifyPublishedPage}。
 * 禁止在此 Observer 内按 DB 旧 structure 全量 rematerialize / 注入收集后重固。
 *
 * @see app/code/Weline/Theme/doc/布局固化与默认注入.md §3.4
 */
final class SetupUpgradeAfterPurgeLayoutEntities implements ObserverInterface
{
    private static bool $hasRun = false;

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
        private readonly Printing $printing,
    ) {
    }

    public function execute(Event &$event): void
    {
        if (self::$hasRun) {
            return;
        }
        self::$hasRun = true;

        $this->printing->note((string)__('正在删除主题布局固化磁盘产物（theme-layout-entities）…'));

        $deleted = $this->paths->purgeAllEntities();
        $this->printing->success((string)__(
            '主题布局固化磁盘已清理：删除节点 %{count}',
            ['count' => $deleted]
        ));

        $result = $this->cacheCleaner->clearAllThemeRelatedCaches(
            null,
            'setup_upgrade_layout_entities_invalidated'
        );
        $failures = \is_array($result['failures'] ?? null) ? $result['failures'] : [];
        if ($failures !== []) {
            $detail = [];
            foreach ($failures as $step => $message) {
                $detail[] = $step . '=' . $message;
            }
            throw new \RuntimeException(
                'setup_upgrade_layout_entities_cache_clear_partial_failure:' . \implode(';', $detail)
            );
        }

        $this->printing->success((string)__('主题布局固化失效后的店面缓存清理完成'));
    }

    /** @internal */
    public static function resetHasRunFlag(): void
    {
        self::$hasRun = false;
    }
}
