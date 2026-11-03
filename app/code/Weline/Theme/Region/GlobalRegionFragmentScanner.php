<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

/**
 * Discovers global-region fragments on disk.
 *
 * Roots are scanned in the order given and keyed by fragment id, so a later root
 * overrides an earlier one — that is how the theme overrides a module fragment
 * using the framework's existing theme override semantics rather than a new
 * priority scheme.
 *
 * Problems (unknown region directory, malformed file name, duplicate id inside
 * one root) are reported instead of silently dropped: the build turns them into
 * a hard failure, a runtime caller can log and carry on.
 */
final class GlobalRegionFragmentScanner
{
    /** @param list<array{source:string, path:string}> $roots */
    public function __construct(private readonly array $roots)
    {
    }

    /**
     * Standard roots for this repository layout, module first so the theme wins.
     *
     * @return list<array{source:string, path:string}>
     */
    public static function defaultRoots(string $projectRoot): array
    {
        $roots = [];
        foreach (\glob($projectRoot . '/app/code/*/*/view/theme/base', \GLOB_ONLYDIR) ?: [] as $path) {
            $roots[] = ['source' => GlobalRegionFragment::SOURCE_MODULE, 'path' => $path];
        }
        foreach (\glob($projectRoot . '/app/design/*/*/frontend/widget/base', \GLOB_ONLYDIR) ?: [] as $path) {
            $roots[] = ['source' => GlobalRegionFragment::SOURCE_THEME, 'path' => $path];
        }

        return $roots;
    }

    /**
     * @return array{fragments:list<GlobalRegionFragment>, problems:list<string>}
     */
    public function scan(): array
    {
        $fragments = [];
        $problems = [];

        foreach ($this->roots as $root) {
            $source = (string)($root['source'] ?? '');
            $rootPath = \rtrim((string)($root['path'] ?? ''), '/');
            if (!\in_array($source, [GlobalRegionFragment::SOURCE_MODULE, GlobalRegionFragment::SOURCE_THEME], true)
                || $rootPath === ''
                || !\is_dir($rootPath)) {
                continue;
            }
            foreach (GlobalRegionSourceRewriter::REGIONS as $region) {
                $regionPath = $rootPath . '/' . $region;
                if (!\is_dir($regionPath)) {
                    continue;
                }
                foreach ($this->groupDirs($regionPath, $problems) as $group => $groupPath) {
                    foreach ($this->files($groupPath, $problems) as $file) {
                        $fragment = $this->fragment($file, $region, $group, $source, $problems);
                        if ($fragment === null) {
                            continue;
                        }
                        // Later roots win: the theme overrides a module fragment with the same id.
                        $fragments[$fragment->id] = $fragment;
                    }
                }
            }
        }

        $fragments = \array_values($fragments);
        \usort($fragments, static fn(GlobalRegionFragment $a, GlobalRegionFragment $b): int => \strcmp($a->sortKey(), $b->sortKey()));

        return ['fragments' => $fragments, 'problems' => $problems];
    }

    /** @return array<string,string> group name => absolute path */
    private function groupDirs(string $regionPath, array &$problems): array
    {
        $groups = [];
        foreach ((array)@\scandir($regionPath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $regionPath . '/' . $entry;
            if (!\is_dir($path)) {
                // A stray file directly under {region}/ cannot be classified.
                $problems[] = 'fragment_group_expected:' . $path;

                continue;
            }
            if (\preg_match('/^[a-z0-9][a-z0-9-]*$/', $entry) !== 1) {
                $problems[] = 'fragment_group_invalid:' . $path;

                continue;
            }
            $groups[$entry] = $path;
        }

        return $groups;
    }

    /** @return list<string> absolute paths of *.phtml files */
    private function files(string $groupPath, array &$problems): array
    {
        $files = [];
        foreach ((array)@\scandir($groupPath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $groupPath . '/' . $entry;
            if (!\is_file($path)) {
                continue;
            }
            if (!\str_ends_with($entry, '.phtml')) {
                $problems[] = 'fragment_extension_invalid:' . $path;

                continue;
            }
            $files[] = $path;
        }

        return $files;
    }

    private function fragment(
        string $file,
        string $region,
        string $group,
        string $source,
        array &$problems,
    ): ?GlobalRegionFragment {
        $base = \basename($file, '.phtml');
        if (\preg_match('/^(?:(\d{1,3})-)?([a-z0-9][a-z0-9-]*)$/', $base, $m) !== 1) {
            $problems[] = 'fragment_name_invalid:' . $file;

            return null;
        }
        $order = ($m[1] ?? '') !== '' ? (int)$m[1] : \PHP_INT_MAX;
        $name = $m[2];
        $id = 'base.' . $region . '.' . $group . '.' . $base;

        return new GlobalRegionFragment(
            id: $id,
            region: $region,
            group: $group,
            order: $order,
            name: $name,
            source: $source,
            path: $file,
            // Modules ship switched off: a site has to opt in from the panel.
            defaultEnabled: $source === GlobalRegionFragment::SOURCE_THEME,
            widgetCode: $this->declaredWidgetCode($file),
            eager: $this->declaredEager($file),
            floatSlot: $this->declaredFloatSlot($file),
            widgetModule: $this->derivedWidgetModule($file, $source),
            widgetType: $this->declaredWidgetType($file),
            cacheTtl: $this->declaredCacheTtl($file),
        );
    }

    /** 片段的部件归属模块：优先 `@widget.module`，否则由路径推导（app/code/Weline/Foo → Weline_Foo）。 */
    private function derivedWidgetModule(string $file, string $source): string
    {
        $head = @\file_get_contents($file, false, null, 0, 4096);
        if (\is_string($head) && \preg_match('/@widget\.module\s*\{([A-Za-z0-9_\\\\]+)\}/', $head, $m) === 1) {
            return $m[1];
        }
        if ($source === GlobalRegionFragment::SOURCE_THEME) {
            return 'Weline_Theme';
        }
        $normalized = \str_replace('\\', '/', $file);
        if (\preg_match('#/app/code/Weline/([^/]+)/#', $normalized, $m) === 1) {
            return 'Weline_' . $m[1];
        }
        if (\preg_match('#/app/code/([^/]+)/([^/]+)/#', $normalized, $m) === 1) {
            return $m[1] . '_' . $m[2];
        }

        return '';
    }

    /** 片段对应的部件类型（`@widget.type`），缺省按内容型。 */
    private function declaredWidgetType(string $file): string
    {
        $head = @\file_get_contents($file, false, null, 0, 4096);
        if (\is_string($head) && \preg_match('/@widget\.type\s*\{([a-z0-9_-]+)\}/i', $head, $m) === 1) {
            return \strtolower($m[1]);
        }

        return 'content';
    }

    /** 片段渲染缓存秒数：`@global-region.cache {60}`（`{true}` 视为 60，`{0}` 关闭）。 */
    private function declaredCacheTtl(string $file): int
    {
        $head = @\file_get_contents($file, false, null, 0, 4096);
        if (!\is_string($head)) {
            return 0;
        }
        if (\preg_match('/@global-region\.cache\s*\{([a-z0-9]+)\}/i', $head, $m) !== 1) {
            return 0;
        }
        $raw = \strtolower($m[1]);
        if ($raw === 'true' || $raw === 'yes') {
            return 60;
        }

        return \max(0, (int)$raw);
    }

    /**
     * 浮层槽位（默认主题约定：start / end / side / middle-start / top-start / top-center / top-end）。
     *
     * 浮层片段必须落进 `#w-storefront-float-layer` 容器里，否则 `position: absolute`
     * 会相对正文定位——线上表现是「客服不在右下角」「社媒图标挤成一排」。
     */
    private function declaredFloatSlot(string $file): string
    {
        $head = @\file_get_contents($file, false, null, 0, 4096);
        if (!\is_string($head) || $head === '') {
            return '';
        }
        if (\preg_match('/@global-region\.float-slot\s*\{([a-z-]+)\}/i', $head, $m) === 1) {
            return \strtolower($m[1]);
        }

        return '';
    }

    /**
     * Whether the fragment must render inline (blocking) rather than be hydrated.
     *
     * Base contributions are global and most of them are invisible until needed, so they
     * default to async: the page only carries a small placeholder and the browser fetches
     * the markup later. Analytics/consent-free infrastructure that must run before paint
     * declares `@global-region.eager {true}` (the pixel bootstrap does).
     */
    private function declaredEager(string $file): bool
    {
        $head = @\file_get_contents($file, false, null, 0, 4096);
        if (!\is_string($head) || $head === '') {
            return false;
        }

        return \preg_match('/@global-region\.eager\s*\{\s*true\s*\}/i', $head) === 1;
    }

    /**
     * Widget code the fragment replaces, read from its own header.
     *
     * Base contributions used to be widgets placed into a theme slot; the fragment
     * keeps that identity so the legacy widget can be skipped (no double render) and
     * the panel still knows which widget an entry corresponds to.
     */
    private function declaredWidgetCode(string $file): string
    {
        $head = @\file_get_contents($file, false, null, 0, 4096);
        if (!\is_string($head) || $head === '') {
            return '';
        }
        if (\preg_match('/@widget\.code\s*\{([a-zA-Z0-9._-]+)\}/', $head, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
