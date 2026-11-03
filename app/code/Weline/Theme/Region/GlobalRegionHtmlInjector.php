<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

use Weline\Framework\Runtime\GlobalRegionInjectorInterface;

/**
 * Last-resort, HTML-level placement of the global regions.
 *
 * The compile-time anchors are the primary mechanism, but not every page reaches the
 * compiler: when the solidify gate falls back to the original design layout the markup
 * is rendered straight from the source tree, so no anchor exists. This injector runs in
 * the HTML finalizer (the one place every storefront response passes through) and only
 * acts when the anchors are absent, which keeps it a no-op on the normal path.
 */
final class GlobalRegionHtmlInjector implements GlobalRegionInjectorInterface
{
    public const MARK = 'data-weline-global-region';

    public function ensure(string $html, string $area = 'frontend'): string
    {
        // 只认「本类写下的注释标记」；MARK 裸串会命中水合器脚本的
        // `data-weline-global-region-hydrator` 属性，导致注入器误判为已注入而整体跳过。
        if ($html === '' || str_contains($html, '<!--' . self::MARK . ':')) {
            return $html;
        }
        // 布局锚点已经产出了全局区内容（异步占位或内联标记）时不再整段补一份：
        // 编译期注入是首选路径，这里只兜底「布局没有锚点」的页面。
        $hasRegionContent = str_contains($html, 'data-global-region-async')
            || str_contains($html, 'data-widget-code="storefront-pixel-bootstrap"');

        if (!$hasRegionContent) {
            $html = $this->injectRegions($html);
        }

        // 兜底：已启用但页面里缺席的异步片段（某些布局处理会吞掉单个节点）。
        // 放在 <body> 开标签之后而不是 </body> 之前——尾部节点更易被收尾处理裁掉。
        $missing = GlobalRegion::missingAsyncPlaceholders($html);
        if ($missing !== '') {
            // 兜底补出来的占位同样需要水合器；页面里没有就一起补上。
            if (!str_contains($html, 'data-weline-global-region-hydrator')) {
                $missing = FragmentRegionProvider::hydratorMarkup() . $missing;
            }
            $bodyPos = stripos($html, '<body');
            $tagEnd = $bodyPos === false ? false : stripos($html, '>', $bodyPos);
            if ($tagEnd !== false) {
                // 不带注释标记：下游链路里 HTML 注释会被清掉，
                // 曾把紧随其后的节点一起带走（实测）。
                $html = substr($html, 0, $tagEnd + 1)
                    . $missing
                    . substr($html, $tagEnd + 1);
            }
        }

        return $html;
    }

    private function injectRegions(string $html): string
    {
        $head = GlobalRegion::render(GlobalRegionSourceRewriter::REGION_HEAD);
        $bodyOpen = GlobalRegion::render(GlobalRegionSourceRewriter::REGION_BODY_OPEN);
        $bodyEnd = GlobalRegion::render(GlobalRegionSourceRewriter::REGION_BODY_END);
        if ($head === '' && $bodyOpen === '' && $bodyEnd === '') {
            return $html;
        }

        $wrap = static fn(string $region, string $markup): string => $markup === ''
            ? ''
            : '<!--' . self::MARK . ':' . $region . '-->' . $markup;

        if ($bodyEnd !== '') {
            $pos = strripos($html, '</body>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $wrap(GlobalRegionSourceRewriter::REGION_BODY_END, $bodyEnd) . substr($html, $pos);
            }
        }
        if ($head !== '') {
            $pos = stripos($html, '</head>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $wrap(GlobalRegionSourceRewriter::REGION_HEAD, $head) . substr($html, $pos);
            }
        }
        if ($bodyOpen !== '') {
            $pos = stripos($html, '<body');
            $tagEnd = $pos === false ? false : stripos($html, '>', $pos);
            if ($tagEnd !== false) {
                $html = substr($html, 0, $tagEnd + 1) . $wrap(GlobalRegionSourceRewriter::REGION_BODY_OPEN, $bodyOpen) . substr($html, $tagEnd + 1);
            }
        }

        return $html;
    }

    public function render(string $region): string
    {
        return (string)GlobalRegion::render($region);
    }

    public function resetRegions(): void
    {
        GlobalRegion::reset();
    }
}
