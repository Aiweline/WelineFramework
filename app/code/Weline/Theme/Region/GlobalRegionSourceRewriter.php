<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

/**
 * Rewrites a page-layout source so the framework can append its own output into
 * three fixed anchors, without touching anything the layout already contains.
 *
 * Contract (do not relax):
 *
 *   - ADDITIVE ONLY. Existing markup is never deleted, reordered, re-indented or
 *     normalised; the rewrite only inserts wrapped calls at three points:
 *       head       -> immediately before the first `</head>`
 *       body_open  -> immediately after the first `<body …>` tag
 *       body_end   -> immediately before the last `</body>`
 *   - Tags inside `<script>` / `<style>` are masked before matching, so a JS
 *     string such as `document.write('</head>')` can never be mistaken for the
 *     real anchor.
 *   - A layout that does not carry all four anchors is a broken layout: the
 *     rewrite throws, which makes the compile fail instead of silently shipping
 *     a page without global content.
 *   - Re-running is idempotent: previously injected blocks are stripped first,
 *     then re-inserted, so recompiles never stack duplicates and an upgrade of
 *     the injected call replaces the old one.
 */
final class GlobalRegionSourceRewriter implements \Weline\Framework\View\GlobalRegionContractInterface
{
    public const REGION_HEAD = 'head';

    public const REGION_BODY_OPEN = 'body_open';

    public const REGION_BODY_END = 'body_end';

    public const REGIONS = [self::REGION_HEAD, self::REGION_BODY_OPEN, self::REGION_BODY_END];

    /**
     * Explicit opt-out for layouts that are content fragments rather than whole
     * documents (no <html>/<head>/<body>), e.g. a size-guide snippet layout.
     *
     * Silent skipping is not allowed — a layout that simply lost its anchors must
     * still fail the bake — so the escape has to be written into the file itself
     * and stays visible in review.
     */
    public const SKIP_MARKER = '@weline-global:skip';

    /**
     * Bumped whenever the injected markup changes. It is mixed into the page
     * artifact input digest: without it a bake whose source files are unchanged
     * would keep reusing artifacts produced by the previous rewrite rules (this
     * actually happened — an artifact with the body_open block inside the <body>
     * tag survived the fix because its source digest had not moved).
     *
     * v2：浮层片段改为「带槽位占位 + 浏览器挂进主题浮动层」，外壳 bag 的 key 也吃这个版本，
     * 所以改这里会同时让旧外壳失效（水合器脚本是内联进外壳的，脚本变了必须换 key）。
     */
    public const VERSION = 'v8';

    private const BEGIN_TEMPLATE = '<!--@weline-global:%s:begin-->';

    private const END_TEMPLATE = '<!--@weline-global:%s:end-->';

    /**
     * 这个编译来源是不是「页面布局」——两种来源都要覆盖。
     *
     * 1. **主题布局**：`app/design/{theme}/frontend/layouts/…`；主题还可以覆写别的主题/模块的布局，
     *    覆写后仍然是 `…/layouts/…`，所以同一规则天然成立。
     * 2. **原生（框架）布局**：`app/code/Weline/Theme/view/theme/frontend/layouts/…`。
     * 3. **固化产物**：`generated/theme/…/pages/layouts/…`（请求执行的就是它）。
     *
     * 排除两类同名路径：hook 片段（`/view/hooks/`，本身没有 head/body）与编译产物
     * （`/view/tpl/`，再改写会让锚点嵌套）。
     */
    public static function appliesTo(string $originPath): bool
    {
        $normalized = \str_replace('\\', '/', $originPath);
        if (!\str_contains($normalized, '/layouts/') || !\str_ends_with($normalized, '.phtml')) {
            return false;
        }

        return !\str_contains($normalized, '/view/hooks/') && !\str_contains($normalized, '/view/tpl/');
    }

    /**
     * @throws \RuntimeException when the layout is missing one of the four anchors
     */
    public function rewrite(string $source): string
    {
        if ($source === '') {
            throw new \RuntimeException('theme_layout_global_anchor_empty_source');
        }

        if (\str_contains($source, self::SKIP_MARKER)) {
            return $source;
        }

        $clean = $this->stripInjected($source);

        // A layout that already carries hand-written anchors must not receive a second set.
        // Checked after stripping our own blocks so re-running stays idempotent.
        if (\str_contains($clean, 'GlobalRegion::render')) {
            return $clean;
        }
        $masked = $this->maskNonHtml($clean);

        $headOpen = \stripos($masked, '<head');
        $headClose = \stripos($masked, '</head>');
        $bodyOpen = \stripos($masked, '<body');
        $bodyClose = \strripos($masked, '</body>');

        $missing = [];
        if ($headOpen === false) {
            $missing[] = '<head';
        }
        if ($headClose === false) {
            $missing[] = '</head>';
        }
        if ($bodyOpen === false) {
            $missing[] = '<body';
        }
        if ($bodyClose === false) {
            $missing[] = '</body>';
        }
        if ($missing !== []) {
            throw new \RuntimeException('theme_layout_missing_global_anchor:' . \implode(',', $missing));
        }

        $bodyOpenEnd = $this->tagEnd($masked, $bodyOpen);
        if ($bodyOpenEnd < 0) {
            throw new \RuntimeException('theme_layout_missing_global_anchor:<body>');
        }

        // Apply from the rightmost offset to the leftmost so earlier offsets stay valid.
        $inserts = [
            [$bodyClose, $this->wrap(self::REGION_BODY_END)],
            [$bodyOpenEnd + 1, $this->wrap(self::REGION_BODY_OPEN)],
            [$headClose, $this->wrap(self::REGION_HEAD)],
        ];
        foreach ($inserts as [$offset, $chunk]) {
            $clean = \substr($clean, 0, $offset) . $chunk . \substr($clean, $offset);
        }

        return $clean;
    }

    /** Drop blocks a previous compile injected, so the rewrite stays idempotent. */
    private function stripInjected(string $source): string
    {
        if (!\str_contains($source, '@weline-global:')) {
            return $source;
        }
        foreach (self::REGIONS as $region) {
            $pattern = '~' . \preg_quote(\sprintf(self::BEGIN_TEMPLATE, $region), '~')
                . '.*?' . \preg_quote(\sprintf(self::END_TEMPLATE, $region), '~') . '~s';
            $stripped = \preg_replace($pattern, '', $source);
            if (\is_string($stripped)) {
                $source = $stripped;
            }
        }

        return $source;
    }

    /**
     * Replace the *content* of script/style blocks with spaces of the same length.
     *
     * Offsets stay identical, so anchor search can run on the masked copy while
     * the rewrite still happens on the real source.
     */
    private function maskNonHtml(string $source): string
    {
        foreach (['~<(script|style)\b[^>]*>.*?</\1\s*>~is', '~<\?(?:php|=)?.*?\?>~s'] as $pattern) {
            $masked = \preg_replace_callback(
                $pattern,
                static fn(array $m): string => \str_repeat(' ', \strlen($m[0])),
                $source,
            );
            if (\is_string($masked)) {
                $source = $masked;
            }
        }

        return $source;
    }

    /**
     * Offset of the `>` that closes the tag starting at $start.
     *
     * Attribute values may legitimately contain `>` (and a PHP echo such as
     * `<?= $class ?>` used to be the trap here: its `?>` was taken as the tag end,
     * which pushed the body_open block inside the tag). Quotes are tracked so only
     * the real end of the tag matches.
     */
    private function tagEnd(string $masked, int $start): int
    {
        $quote = '';
        $length = \strlen($masked);
        for ($i = $start; $i < $length; ++$i) {
            $char = $masked[$i];
            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return $i;
            }
        }

        return -1;
    }

    private function wrap(string $region): string
    {
        return \sprintf(self::BEGIN_TEMPLATE, $region)
            . '<?= \Weline\Theme\Region\GlobalRegion::render(\'' . $region . '\') ?>'
            . \sprintf(self::END_TEMPLATE, $region);
    }

    /**
     * {@inheritDoc}
     *
     * Framework-side try/catch stays at the caller; the contract only gates and rewrites.
     */
    public function applyContract(string $originPath, string $sourceBytes): string
    {
        if (!self::appliesTo($originPath)) {
            return $sourceBytes;
        }
        if (\str_contains($sourceBytes, self::SKIP_MARKER)) {
            return $sourceBytes;
        }

        return $this->rewrite($sourceBytes);
    }
}
