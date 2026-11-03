<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

/**
 * One phtml fragment destined for a global region.
 *
 * Everything is derived from the file's path — there is no manifest to keep in
 * sync, which is the whole point: dropping a file in the right directory is the
 * entire registration step.
 *
 *   {base}/{region}/{group}/{nn}-{name}.phtml
 *            │        │       │      └ display name
 *            │        │       └ order prefix (optional)
 *            │        └ panel group
 *            └ head | body_open | body_end
 */
final class GlobalRegionFragment
{
    public const SOURCE_THEME = 'theme';

    public const SOURCE_MODULE = 'module';

    public function __construct(
        public readonly string $id,
        public readonly string $region,
        public readonly string $group,
        public readonly int $order,
        public readonly string $name,
        public readonly string $source,
        public readonly string $path,
        public readonly bool $defaultEnabled,
        // Widget code this fragment replaces, when the content used to be a widget.
        // Declared in the fragment itself (`@widget.code {…}`) so the legacy widget
        // can be skipped instead of rendering a second copy.
        public readonly string $widgetCode = '',
        // Inline (blocking) vs async hydration. Global contributions default to async:
        // the page carries a placeholder and the browser fetches the markup afterwards,
        // which keeps every storefront page's HTML small. Head-critical infrastructure
        // (analytics bootstrap) opts in with `@global-region.eager {true}`.
        public readonly bool $eager = false,
        // 浮层片段要落在默认主题的浮动层容器里（否则 position:absolute 会相对正文定位，
        // 表现就是「客服不在右下角」「社媒图标贴在一起」）。空串表示不是浮层。
        public readonly string $floatSlot = '',
        // 与槽位部件同构的外壳身份：后台编辑器按 data-widget-module/type/code 认部件，
        // 缺了这三个属性它就会把片段当成「没有身份的部件」，连带禁止保存/发布。
        public readonly string $widgetModule = '',
        public readonly string $widgetType = 'content',
        // 片段渲染结果缓存秒数（`@global-region.cache {60}`）。0 = 不缓存。
        // 仅用于与访客无关的部件（音乐/App 下载/社媒/联盟）；购物车等用户相关片段必须留 0。
        public readonly int $cacheTtl = 0,
    ) {
    }

    /**
     * Sort key used for a stable render order across every root.
     *
     * The region weight follows the document order declared by the contract
     * (head, body_open, body_end) — sorting regions alphabetically would put
     * body_end first, which is why it is an explicit index and not the name.
     */
    public function sortKey(): string
    {
        $regionWeight = \array_search($this->region, GlobalRegionSourceRewriter::REGIONS, true);

        return \sprintf(
            '%02d|%s|%06d|%s',
            $regionWeight === false ? 99 : (int)$regionWeight,
            $this->group,
            $this->order,
            $this->id,
        );
    }
}
