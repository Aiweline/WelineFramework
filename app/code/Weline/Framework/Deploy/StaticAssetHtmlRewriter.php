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
        if (StaticBuildId::flatUrlsEnabled()) {
            return self::normalizeToFlat($html);
        }
        if (\str_contains($html, self::PAIR_PLACEHOLDER) || \str_contains($html, self::DEPLOY_PLACEHOLDER)) {
            $html = self::resolvePlaceholders($html, $buildId);
        }
        if ($buildId === '') {
            return $html;
        }

        $rewrite = static fn (array $match): string => $match[1]
            . StaticBuildId::versionedPath(self::alignThemePair($match[2], $buildId), $buildId)
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
     * flat 契约出口归一：占位符解成 /static，并把 HTML 里一切版本化路径段
     * （{12hex} / tu-* / {code}/v{n}/{deploy}）剥回稳定 flat 路径。幂等。
     */
    private static function normalizeToFlat(string $html): string
    {
        $flat = static fn (array $match): string => $match[1]
            . StaticBuildId::stablePath($match[2])
            . $match[3];

        $html = \str_replace(
            ['/static/' . self::PAIR_PLACEHOLDER . '/', '/static/' . self::DEPLOY_PLACEHOLDER . '/'],
            '/static/',
            $html,
        );
        $html = \preg_replace_callback(
            '#((?:src|href)\s*=\s*["\'])(/static/[^"\']*)(["\'])#i',
            $flat,
            $html,
        ) ?? $html;

        return \preg_replace_callback(
            '#(url\(\s*["\']?)(/static/[^"\')\s]+)(["\']?\s*\))#i',
            $flat,
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
        $deploy = $buildId !== '' ? $buildId : self::DEPLOY_PLACEHOLDER;
        // 没有主题对时（指针未写 pair_segment），主题自有资源也退回部署段；
        // 否则会产出 `/static//Weline/...` 这种双斜杠地址。
        $pairSegment = $pair !== '' ? $pair : $deploy;
        $html = \str_replace('/static/' . self::PAIR_PLACEHOLDER . '/', '/static/' . $pairSegment . '/', $html);

        return \str_replace('/static/' . self::DEPLOY_PLACEHOLDER . '/', '/static/' . $deploy . '/', $html);
    }

    /**
     * 已带主题对、但 code/版本不是当前应用主题的 URL，改成当前 pair。
     * 纯部署号调用 {@see StaticBuildId::versionedPath()} 会保留旧的 code/v{n}。
     */
    private static function alignThemePair(string $url, string $buildId): string
    {
        $pair = '';
        try {
            if (\class_exists('\\Weline\\Theme\\Service\\ThemeUpdate\\ThemeUpdateRuntime')) {
                $pair = \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::pairSegment();
            }
        } catch (\Throwable) {
            $pair = '';
        }
        if ($pair === '' || \preg_match('#^[A-Za-z0-9_.-]+/v[0-9]+$#', $pair) !== 1) {
            return $url;
        }
        [$path, $query] = \str_contains($url, '?') ? \explode('?', $url, 2) : [$url, null];
        if (\preg_match('#^/static/([A-Za-z0-9_.-]+)/(v[0-9]+)/([A-Za-z0-9_-]+)(/.*)?$#', $path, $match) !== 1) {
            return $url;
        }
        if ($match[1] . '/' . $match[2] === $pair) {
            return $url;
        }
        $deploy = $match[3];
        if ($buildId !== '' && \str_contains($buildId, '/') === false) {
            $deploy = $buildId;
        }
        $aligned = '/static/' . $pair . '/' . $deploy . ($match[4] ?? '');

        return $query === null ? $aligned : $aligned . '?' . $query;
    }
}
