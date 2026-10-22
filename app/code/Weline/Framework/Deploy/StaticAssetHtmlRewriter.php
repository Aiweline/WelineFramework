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
    /** 主题世代段占位符：/static/__PAIR__/… 出 HTML 时换成 {主题code}/v{版本号}。 */
    public const PAIR_PLACEHOLDER = '__W_PAIR__';

    /** 部署构建段占位符：/static/__DEPLOY__/… 出 HTML 时换成当前构建号。 */
    public const DEPLOY_PLACEHOLDER = '__W_deploy__';

    public static function rewrite(string $html, ?string $buildId = null): string
    {
        $buildId = $buildId ?? StaticBuildId::current();
        if ($html === '' || (!\str_contains($html, '/static/') && !\str_contains($html, self::PAIR_PLACEHOLDER) && !\str_contains($html, self::DEPLOY_PLACEHOLDER))) {
            return $html;
        }
        if (\str_contains($html, self::PAIR_PLACEHOLDER) || \str_contains($html, self::DEPLOY_PLACEHOLDER)) {
            $html = self::resolvePlaceholders($html, $buildId);
        }
        if ($buildId === '') {
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

    /**
     * 占位符 → 当前世代。主题段按主题/模块路径区分；无主题世代时退回部署段。
     */
    private static function resolvePlaceholders(string $html, string $buildId): string
    {
        $pair = '';
        try {
            if (\class_exists('\\Weline\\Theme\\Service\\ThemeUpdate\\ThemeUpdateRuntime')) {
                $pair = \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::pairSegment();
            }
        } catch (\Throwable) {
            $pair = '';
        }
        $html = \str_replace('/static/' . self::PAIR_PLACEHOLDER . '/', '/static/' . $pair . '/', $html);
        $deploy = $buildId !== '' ? $buildId : self::DEPLOY_PLACEHOLDER;

        return \str_replace('/static/' . self::DEPLOY_PLACEHOLDER . '/', '/static/' . $deploy . '/', $html);
    }
}
