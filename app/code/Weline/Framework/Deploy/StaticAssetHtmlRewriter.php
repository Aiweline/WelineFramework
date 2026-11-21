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

        $themeBuild = self::pinnedThemeBuild();
        $pair = self::currentPairSegment();
        $themePrefix = self::currentThemePrefix();
        // 部署 .build.json 缺失（dev/未跑过部署）时，主题对段仍可改写主题命名空间 URL——
        // pair+themeBuild 来自主题更新指针，与部署号无关；两种号都没有才真正无事可做。
        if ($buildId === '' && ($themeBuild === '' || $pair === '')) {
            return $html;
        }
        $rewriteUrl = static function (string $url) use ($buildId, $themeBuild, $pair, $themePrefix): string {
            $url = self::alignThemePair($url, $buildId);
            $target = $buildId;
            // 主题自有资源（`{code}/v{n}/…` 或裸主题命名空间）用主题更新指针上的静态段
            // （每次换代换目录，旧目录留到 GC）；模块/框架资源继续用部署构建号。
            if ($themeBuild !== '' && $pair !== '') {
                $isPairShape = \preg_match('#^/static/[A-Za-z0-9_.-]+/v[0-9]+/#', $url) === 1;
                // 只有设计区字面路径（`/static/{ns}/{frontend|backend}/`，pair 目录独占、
                // flat 树从不覆盖）才重写到主题对段。模块覆盖路径（`{ns}/Weline/...`、
                // `Weline/Theme/...`）flat 树有实体文件，主题对目录可能滞后——重写会
                // 把可解析 URL 打 404。
                $isThemeNamespace = $themePrefix !== ''
                    && \preg_match('#^/static/' . \preg_quote($themePrefix, '#') . '/(?:frontend|backend)/#', $url) === 1;
                if ($isPairShape || $isThemeNamespace) {
                    $target = $pair . '/' . $themeBuild;
                }
            }

            return StaticBuildId::versionedPath($url, $target);
        };
        $rewrite = static fn(array $match): string => $match[1] . $rewriteUrl($match[2]) . $match[3];

        $html = \preg_replace_callback(
            '#((?:src|href)\s*=\s*["\'])(/static/[^"\']*)(["\'])#i',
            $rewrite,
            $html,
        ) ?? $html;

        $html = \preg_replace_callback(
            '#(url\(\s*["\']?)(/static/[^"\')\s]+)(["\']?\s*\))#i',
            $rewrite,
            $html,
        ) ?? $html;

        // srcset 是逗号分隔的「URL + 描述符」列表，逐条过同一个改写闭包。
        return \preg_replace_callback(
            '#(srcset\s*=\s*["\'])([^"\']*)(["\'])#i',
            static function (array $match) use ($rewriteUrl): string {
                $candidates = \preg_split('/\s*,\s*/', \trim($match[2])) ?: [];
                foreach ($candidates as &$candidate) {
                    if (\preg_match('#^(/static/\S+)(\s+.+)?$#', \trim($candidate), $urlMatch) === 1) {
                        $candidate = $rewriteUrl($urlMatch[1]) . ($urlMatch[2] ?? '');
                    }
                }
                unset($candidate);

                return $match[1] . \implode(', ', $candidates) . $match[3];
            },
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
            if (\class_exists('\\Weline\\Framework\\Deploy\\ThemeRuntimeState')) {
                $pair = \Weline\Framework\Deploy\ThemeRuntimeState::pairSegment();
            }
        } catch (\Throwable) {
            $pair = '';
        }
        $deploy = $buildId !== '' ? $buildId : self::DEPLOY_PLACEHOLDER;
        $themeBuild = self::pinnedThemeBuild();
        $pairBuild = $themeBuild !== '' ? $themeBuild : $deploy;
        // 没有主题对时（指针未写 pair_segment），主题自有资源也退回部署段；
        // 否则会产出 `/static//Weline/...` 这种双斜杠地址。
        $pairSegment = $pair !== '' ? $pair . '/' . $pairBuild : $deploy;
        $html = \str_replace('/static/' . self::PAIR_PLACEHOLDER . '/', '/static/' . $pairSegment . '/', $html);

        return \str_replace('/static/' . self::DEPLOY_PLACEHOLDER . '/', '/static/' . $deploy . '/', $html);
    }

    /** 主题更新指针上的 12 位静态段；没有代次机制/未 pin 时返回空串（退回部署构建号）。 */
    private static function pinnedThemeBuild(): string
    {
        try {
            if (\class_exists('\\Weline\\Framework\\Deploy\\ThemeRuntimeState')) {
                $build = \Weline\Framework\Deploy\ThemeRuntimeState::pinnedStaticBuild();

                return \preg_match('/^[0-9a-f]{12}$/', $build) === 1 ? $build : '';
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /** 当前应用主题的 pair 段（`{code}/v{n}`）；无主题上下文返回空串。 */
    private static function currentPairSegment(): string
    {
        try {
            if (\class_exists('\\Weline\\Framework\\Deploy\\ThemeRuntimeState')) {
                $pair = \Weline\Framework\Deploy\ThemeRuntimeState::pairSegment();

                return \preg_match('#^[A-Za-z0-9_.-]+/v[0-9]+$#', $pair) === 1 ? $pair : '';
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /** 当前应用主题的静态命名空间（如 `Weline/hanfu`）；无主题上下文返回空串。 */
    private static function currentThemePrefix(): string
    {
        try {
            if (\class_exists('\\Weline\\Framework\\Deploy\\ThemeRuntimeState')) {
                return \trim(\Weline\Framework\Deploy\ThemeRuntimeState::activeThemePrefix(), '/');
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * 已带主题对、但 code/版本不是当前应用主题的 URL，改成当前 pair。
     * 纯部署号调用 {@see StaticBuildId::versionedPath()} 会保留旧的 code/v{n}。
     */
    private static function alignThemePair(string $url, string $buildId): string
    {
        $pair = '';
        try {
            if (\class_exists('\\Weline\\Framework\\Deploy\\ThemeRuntimeState')) {
                $pair = \Weline\Framework\Deploy\ThemeRuntimeState::pairSegment();
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
