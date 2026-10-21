<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * 把最终 HTML 里本站的 /static/ 资源 URL 统一改写为构建代次版本。
 *
 * 主题/模块模板里历史遗留的硬编码 `/static/...`（含手写 ?v=）不必逐处改：
 * 渲染完成后统一走这里，保证页面上的 CSS/JS/图片与构建目录同代。
 * 已内容寻址（32~64 位 hex 文件名）与已带构建段的 URL 原样保留。
 */
final class StaticAssetHtmlRewriter
{
    public static function rewrite(string $html, ?string $buildId = null): string
    {
        $buildId = $buildId ?? StaticBuildId::current();
        if ($buildId === '' || $html === '' || !\str_contains($html, '/static/')) {
            return $html;
        }

        $rewrite = static fn (array $match): string => $match[1]
            . StaticBuildId::versionedPath($match[2], $buildId)
            . $match[3];

        $html = \preg_replace_callback(
            '#((?:src|href)\s*=\s*["\'])(/static/[^"\']*)(["\'])#i',
            $rewrite,
            $html,
        ) ?? $html;

        return \preg_replace_callback(
            '#(url\(\s*["\']?)(/static/[^"\')\s]+)(["\']?\s*\))#i',
            $rewrite,
            $html,
        ) ?? $html;
    }
}
