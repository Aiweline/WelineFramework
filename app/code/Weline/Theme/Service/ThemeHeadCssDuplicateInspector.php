<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 头部 CSS 重复投递检查。
 *
 * 背景：PACK_TOKENS / PACK_STOREFRONT / PACK_OVERLAY 已经把主题叶合并成包（一次请求、内容哈希、
 * immutable）。页面/布局再挂同一个叶，或 body 末尾再挂一次，就是同一份字节投递两次，并多出
 * 渲染阻塞请求（PageSpeed「渲染阻塞请求」正是这么来的）。
 *
 * 两个入口：
 * - inspectTheme()：静态扫描主题源码，给文件 + 行号，便于直接修；
 * - inspectHtml()：扫描最终响应，唯一能看到真实装配结果的入口（head chrome 走缓存时会跳过
 *   发包，页面/layout 里的「本次请求是否已发包」判断不可靠）。
 *
 * 级别：
 * - duplicate：硬失败（只允许 DEV 断言，PROD 不因它拦住发版）；
 * - guard_unsafe：page/layout 用 themeOverlayAbsorbed() 做守卫 —— chrome 缓存下恒 false，实际仍会重复；
 * - late_override：body 末尾再挂（层叠排序用途），不阻塞渲染但仍重复投递；
 * - fallback：merge=off 回退、chrome partial 内回退、或按 overlay 覆盖判定后再挂（合法）；
 * - preload_no_fallback：warning（非硬失败）——preload as=style 缺 noscript 回退，禁用 JS 时整份不生效。
 *
 * noscript 感知：`<link rel="preload" as="style" onload=…><noscript><link rel="stylesheet" …></noscript>`
 * 是同一份 CSS 的唯一一次加载（noscript 只是无 JS 回退），两处 href 相同不算重复投递。
 *
 * work_mode=theme_module_runtime
 */
final class ThemeHeadCssDuplicateInspector
{
    public const SEVERITY_DUPLICATE = 'duplicate';
    public const SEVERITY_GUARD_UNSAFE = 'guard_unsafe';
    public const SEVERITY_LATE_OVERRIDE = 'late_override';
    public const SEVERITY_FALLBACK = 'fallback';
    public const SEVERITY_STANDALONE = 'standalone';
    /** preload 缺 noscript 回退：warning 级，不进 hardFindings()。 */
    public const SEVERITY_PRELOAD_NO_FALLBACK = 'preload_no_fallback';

    public const REASON_DUPLICATE = '叶已并入 PACK_OVERLAY，layout/page 又挂了一次（重复投递 + 多一个阻塞请求）';
    public const REASON_GUARD_UNSAFE = 'guard 用 themeOverlayAbsorbed()：依赖本次请求发包状态，head chrome 走缓存时恒 false';
    public const REASON_LATE_OVERRIDE = 'body 末尾再挂同一叶（层叠排序用途），不阻塞首屏但仍重复投递';
    public const REASON_FALLBACK = 'merge=off / chrome 回退 / 按 overlay 覆盖判定后再挂（合法）';
    public const REASON_RESPONSE = '最终 HTML 里同时出现 overlay 包与该叶（同一份 CSS 投递两次）';
    public const REASON_STANDALONE = '该壳页不发射 theme-head 包（独立页自备叶清单），不构成重复';
    public const REASON_DUPLICATE_URL = '同一 URL 重复加载（noscript 回退之外同一份 CSS 出现 ≥2 次，投递两次 + 多一个请求）';
    public const REASON_PRELOAD_NO_FALLBACK = 'preload as=style 缺 noscript 回退：禁用 JS 时该包整份不生效（warning，非硬失败）';

    /** 回退/守卫识别的向上检索行数。 */
    private const GUARD_WINDOW = 60;

    /** inspectHtml() 里视为「真正加载一次」的 <link> 形态。 */
    private const LOADING_STYLESHEET = 'stylesheet';
    private const LOADING_PRELOAD = 'preload';

    private const SCAN_SUFFIXES = ['phtml', 'php'];

    public function __construct(
        private readonly ThemeHeadChromeCssPack $headPack,
    ) {
    }

    /**
     * 静态扫描某个主题的源码。非设计主题（没有 app/design 目录）返回空。
     *
     * @return list<array{file:string,line:int,leaf:string,severity:string,reason:string}>
     */
    public function inspectTheme(int $themeId, string $area = 'frontend'): array
    {
        $theme = $this->loadTheme($themeId);
        if (!$theme instanceof WelineTheme) {
            return [];
        }
        $root = $this->themeSourceRoot($theme);
        if ($root === '') {
            return [];
        }

        return $this->inspectSourceRoot($root, $area, $this->headPack->overlayManifestLeaves($area, $theme));
    }

    /**
     * @param list<string> $leafPaths theme 相对叶路径（theme/frontend/assets/css/x.css）
     * @return list<array{file:string,line:int,leaf:string,severity:string,reason:string}>
     */
    public function inspectSourceRoot(string $root, string $area, array $leafPaths): array
    {
        $leafBaseNames = $this->leafBaseNames($leafPaths);
        if ($leafBaseNames === []) {
            return [];
        }
        $areaDir = rtrim(str_replace('\\', '/', $root), '/') . '/' . trim($area, '/');
        if (!is_dir($areaDir)) {
            return [];
        }
        $findings = [];
        $seen = [];
        foreach ($this->iterateSourceFiles($areaDir) as $file) {
            $raw = @file($file);
            if (!\is_array($raw) || $raw === []) {
                continue;
            }
            $lines = array_map(static fn(string $l): string => rtrim($l, "\r\n"), $raw);
            $headCloseAt = $this->lastHeadCloseIndex($lines);
            // 独立壳页（自己列叶、不发射 theme-head 包）不算重复；判据是本文件是否会出包。
            $emitsPacks = $this->fileEmitsHeadPacks(\implode("\n", $lines));
            foreach ($lines as $index => $line) {
                $leaf = $this->matchLeafBaseName($line, $leafBaseNames);
                if ($leaf === null || !$this->looksLikeStylesheetEmission($line)) {
                    continue;
                }
                [$severity, $reason] = $this->classify($file, $lines, $index, $headCloseAt, $emitsPacks);
                $key = $file . '|' . $leaf;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $findings[] = [
                    'file' => $this->relativePath($root, $file),
                    'line' => $index + 1,
                    'leaf' => $leaf,
                    'severity' => $severity,
                    'reason' => $reason,
                ];
            }
        }

        return $findings;
    }

    /**
     * 运行时：最终 HTML 里同一份 CSS 被真加载两次以上才算重复投递。
     *
     * 两条判据（都只在 noscript 之外成立）：
     * - 同一 URL 出现 ≥2 次 → duplicate（同一份字节投递两次）；
     * - overlay 包在场时，manifest 叶又单独加载一次 → duplicate（既有逻辑，不回归）。
     *
     * `<noscript>…</noscript>` 内的 stylesheet link 是 preload+onload 的无 JS 回退，
     * 正常路径不会加载第二次，既不算「真实加载面」，也不参与重复计数；反过来，
     * preload 若找不到同 URL 的 noscript 回退，给 warning 级（禁用 JS 时整份不生效）。
     *
     * @param list<string> $leafPaths
     * @return list<array{file:string,line:int,leaf:string,severity:string,reason:string}>
     */
    public function inspectHtml(string $html, array $leafPaths): array
    {
        if ($html === '') {
            return [];
        }
        // noscript 回退单独收集：既要从真实加载面里剔除，也要用于 preload ↔ 回退配对。
        $noscriptHtml = '';
        if (\preg_match_all('/<noscript\b[^>]*>(.*?)<\/noscript\s*>/is', $html, $noscriptMatches) === false) {
            return [];
        }
        if (($noscriptMatches[1] ?? []) !== []) {
            $noscriptHtml = \implode("\n", (array)$noscriptMatches[1]);
        }
        $effective = \preg_replace('/<noscript\b[^>]*>.*?<\/noscript\s*>/is', ' ', $html);
        if (!\is_string($effective)) {
            return [];
        }
        if (!\preg_match_all('/<link\b[^>]*>/i', $effective, $matches)) {
            return [];
        }
        $leafBaseNames = $this->leafBaseNames($leafPaths);
        $overlayPresent = \str_contains($html, 'data-weline-theme-head-pack="overlay"');
        /** @var array<string, list<string>> $loads url → 该 URL 在 noscript 之外被加载的形态 */
        $loads = [];
        /** @var array<string, string> $hrefs url → 原始 href */
        $hrefs = [];
        foreach ($matches[0] as $tag) {
            $kind = $this->loadingKind($tag);
            if ($kind === '' || !\preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                continue;
            }
            $href = \trim($hrefMatch[1]);
            if ($href === '') {
                continue;
            }
            $key = $this->urlKey($href);
            $loads[$key][] = $kind;
            $hrefs[$key] = $href;
        }
        if ($loads === []) {
            return [];
        }
        $findings = [];
        $seen = [];
        foreach ($loads as $key => $kinds) {
            $href = $hrefs[$key];
            if (\count($kinds) > 1) {
                $label = $this->urlBaseName($href);
                if (!isset($seen[$label])) {
                    $seen[$label] = true;
                    $findings[] = [
                        'file' => '<response>',
                        'line' => 0,
                        'leaf' => $label,
                        'severity' => self::SEVERITY_DUPLICATE,
                        'reason' => self::REASON_DUPLICATE_URL,
                    ];
                }
                continue;
            }
            if (!$overlayPresent) {
                continue;
            }
            $leaf = $leafBaseNames === [] ? null : $this->matchLeafBaseName($href, $leafBaseNames);
            if ($leaf === null || isset($seen[$leaf])) {
                continue;
            }
            // preload + 同 URL 的 noscript 回退 = 合法延迟加载，不算与 overlay 包重复投递。
            if ($kinds[0] === self::LOADING_PRELOAD && $this->hasNoscriptFallback($noscriptHtml, $key)) {
                continue;
            }
            $seen[$leaf] = true;
            $findings[] = [
                'file' => '<response>',
                'line' => 0,
                'leaf' => $leaf,
                'severity' => self::SEVERITY_DUPLICATE,
                'reason' => self::REASON_RESPONSE,
            ];
        }
        $seenPreload = [];
        foreach ($loads as $key => $kinds) {
            if (!\in_array(self::LOADING_PRELOAD, $kinds, true) || $this->hasNoscriptFallback($noscriptHtml, $key)) {
                continue;
            }
            $label = $this->urlBaseName($hrefs[$key]);
            if (isset($seenPreload[$label])) {
                continue;
            }
            $seenPreload[$label] = true;
            $findings[] = [
                'file' => '<response>',
                'line' => 0,
                'leaf' => $label,
                'severity' => self::SEVERITY_PRELOAD_NO_FALLBACK,
                'reason' => self::REASON_PRELOAD_NO_FALLBACK,
            ];
        }

        return $findings;
    }

    /**
     * 该 <link> 是否「真正加载一次」样式：阻塞 stylesheet，或 preload as=style（onload 切换）。
     * preload 的 noscript 回退是同一份加载的兜底，不算独立一次。
     */
    private function loadingKind(string $tag): string
    {
        if (\preg_match('/\brel\s*=\s*["\']([^"\']*)["\']/i', $tag, $relMatch) !== 1) {
            return '';
        }
        $rel = \strtolower(\trim($relMatch[1]));
        if ($rel === 'stylesheet') {
            return self::LOADING_STYLESHEET;
        }
        if (\str_contains($rel, 'preload') && \preg_match('/\bas\s*=\s*["\']style["\']/i', $tag) === 1) {
            return self::LOADING_PRELOAD;
        }

        return '';
    }

    /** noscript 里是否存在同一 URL 的 stylesheet 回退。 */
    private function hasNoscriptFallback(string $noscriptHtml, string $urlKey): bool
    {
        if ($noscriptHtml === '' || !\preg_match_all('/<link\b[^>]*>/i', $noscriptHtml, $matches)) {
            return false;
        }
        foreach ($matches[0] as $tag) {
            if (\preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $hrefMatch) !== 1) {
                continue;
            }
            if ($this->urlKey($hrefMatch[1]) === $urlKey) {
                return true;
            }
        }

        return false;
    }

    private function urlKey(string $href): string
    {
        return \trim($href);
    }

    private function urlBaseName(string $href): string
    {
        $path = \parse_url($href, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $href;
        $base = \basename(\str_replace('\\', '/', $path));

        return $base !== '' ? $base : $href;
    }

    /**
     * @param list<array{file:string,line:int,leaf:string,severity:string,reason:string}> $findings
     * @return list<array{file:string,line:int,leaf:string,severity:string,reason:string}>
     */
    public function hardFindings(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn(array $f): bool => ($f['severity'] ?? '') === self::SEVERITY_DUPLICATE
        ));
    }

    /**
     * @param list<array{file:string,line:int,leaf:string,severity:string,reason:string}> $findings
     */
    public function format(array $findings): string
    {
        $rows = [];
        foreach ($findings as $finding) {
            $rows[] = sprintf(
                '[%s] %s%s %s — %s',
                (string)$finding['severity'],
                (string)$finding['file'],
                (int)$finding['line'] > 0 ? ':' . (int)$finding['line'] : '',
                (string)$finding['leaf'],
                (string)$finding['reason']
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param list<string> $lines
     * @return array{0:string,1:string}
     */
    private function classify(string $file, array $lines, int $index, int $headCloseAt, bool $emitsPacks): array
    {
        // </head> 之后再挂的叶属于 body 级覆盖（层叠排序），不阻塞首屏但仍重复投递。
        if ($headCloseAt >= 0 && $index > $headCloseAt) {
            return [self::SEVERITY_LATE_OVERRIDE, self::REASON_LATE_OVERRIDE];
        }
        $window = \implode("\n", \array_slice($lines, \max(0, $index - self::GUARD_WINDOW), self::GUARD_WINDOW));
        if (str_contains($window, 'overlayLeavesForCurrentRequest')) {
            return [self::SEVERITY_FALLBACK, self::REASON_FALLBACK];
        }
        if (str_contains($window, 'absorb') || str_contains($window, 'Absorbed')) {
            if (str_contains(str_replace('\\', '/', $file), '/partials/head/')) {
                return [self::SEVERITY_FALLBACK, self::REASON_FALLBACK];
            }

            return [self::SEVERITY_GUARD_UNSAFE, self::REASON_GUARD_UNSAFE];
        }
        if (!$emitsPacks) {
            return [self::SEVERITY_STANDALONE, self::REASON_STANDALONE];
        }

        return [self::SEVERITY_DUPLICATE, self::REASON_DUPLICATE];
    }

    /** 本文件是否会发射 theme-head 包（Block\Partials / tryLinkHtml / theme-head 标记）。 */
    private function fileEmitsHeadPacks(string $source): bool
    {
        foreach (['tryLinkHtml', 'Block\\Partials', 'theme-head'] as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $leafPaths
     * @return list<string>
     */
    private function leafBaseNames(array $leafPaths): array
    {
        $names = [];
        foreach ($leafPaths as $leaf) {
            $base = \basename(\str_replace('\\', '/', (string)$leaf));
            if ($base !== '' && \str_ends_with($base, '.css')) {
                $names[$base] = true;
            }
        }

        return \array_keys($names);
    }

    /**
     * @param list<string> $leafBaseNames
     */
    private function matchLeafBaseName(string $line, array $leafBaseNames): ?string
    {
        foreach ($leafBaseNames as $leaf) {
            if (str_contains($line, $leaf)) {
                return $leaf;
            }
        }

        return null;
    }

    private function looksLikeStylesheetEmission(string $line): bool
    {
        if (str_contains($line, 'theme:css')) {
            return true;
        }
        if (\preg_match('/<link\b/i', $line) === 1) {
            return true;
        }

        return \preg_match('/\bhref\s*=/i', $line) === 1;
    }

    /**
     * @param list<string> $lines
     */
    private function lastHeadCloseIndex(array $lines): int
    {
        $last = -1;
        foreach ($lines as $i => $line) {
            if (\preg_match('/<\/head\b/i', $line) === 1) {
                $last = $i;
            }
        }

        return $last;
    }

    /**
     * @return \Generator<int, string>
     */
    private function iterateSourceFiles(string $dir): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $extension = \strtolower($file->getExtension());
            if (!\in_array($extension, self::SCAN_SUFFIXES, true)) {
                continue;
            }
            if ($file->getFilename() === 'theme-head-css-overlay.php') {
                continue;
            }
            yield $file->getPathname();
        }
    }

    private function relativePath(string $root, string $file): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }

    private function themeSourceRoot(WelineTheme $theme): string
    {
        try {
            $path = str_replace('\\', '/', trim((string)$theme->getPath()));
        } catch (\Throwable) {
            return '';
        }
        if ($path === '') {
            return '';
        }
        // path 有三种落库形态：绝对路径（/…/app/design/Weline/playinginchina/）、
        // 'app/design/Weline/hanfu'、以及 'Weline/playinginchina'。
        $base = rtrim((string)\BP, '/\\');
        $relative = trim(ltrim($path, '/'), '/');
        $underDesign = trim((string)\preg_replace('#^.?/?app/design/#', '', $relative), '/');
        $candidates = [
            $base . '/' . $relative,
            $base . '/app/design/' . $relative,
            $base . '/app/design/' . $underDesign,
        ];
        if (str_starts_with($path, '/')) {
            $candidates = array_merge([$path], $candidates);
        }
        foreach (array_unique($candidates) as $candidate) {
            if (is_dir($candidate)) {
                return rtrim($candidate, '/\\');
            }
        }

        return '';
    }

    private function loadTheme(int $themeId): ?WelineTheme
    {
        if ($themeId < 1) {
            return null;
        }
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->reset()->clearQuery()->where(WelineTheme::schema_fields_ID, $themeId)->find()->fetch();

        return (int)$theme->getId() > 0 ? $theme : null;
    }
}
