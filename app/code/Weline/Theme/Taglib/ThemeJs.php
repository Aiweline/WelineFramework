<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\CompileTimeStaticMirror;
use Weline\Framework\Taglib\StaticMirrorCapableInterface;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;

/**
 * theme:js — load JS from a module's `view/theme/` (default module: Weline_Theme).
 *
 * Module statics stay on `@static(...)` / built-in `<js>`; do not convert those here.
 *
 * When the path (and attrs) are compile-time literals, the callback bakes the final
 * `<script>` HTML (same shape as built-in `<js>`). Dynamic embeds still emit PHP.
 *
 * Examples:
 * - `<theme:js>frontend/js/catalog-page.js</theme:js>`
 * - `<theme:js>Vendor_Theme::frontend/js/catalog-page.js</theme:js>`
 */
class ThemeJs implements TaglibInterface, StaticMirrorCapableInterface
{
    public static function name(): string
    {
        return 'theme:js';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return [];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes) {
            [$content, $attrs] = self::resolveContentAndAttrs((string)$tag_key, (array)$tag_data);
            if ($content === '') {
                return '';
            }

            $mirrored = self::tryStaticMirror((string)$tag_key, (array)$tag_data, is_array($attributes) ? $attributes : []);
            if ($mirrored !== null) {
                return $mirrored;
            }

            $contentPhp = self::buildRuntimeSourceExpression($content);
            if (self::hasLoadAttribute(is_array($attributes) ? $attributes : [], $attrs)) {
                // 声明加载：不直出 <script src>，只留标记，由 theme.js 在 DOMContentLoaded 后按序加载。
                $markerAttrsPhp = var_export(self::buildLoadMarkerAttrs(is_array($attributes) ? $attributes : [], $attrs), true);

                return "<?php \$__themeJsSrc = \Weline\Theme\Taglib\ThemeJs::resolveGenerationFallbackPublic((string)\$this->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_THEME, {$contentPhp}));"
                    . " if (\$__themeJsSrc !== '') { echo '<script type=\'application/x-weline-theme-js\' data-weline-theme-js=\''"
                    . " . htmlspecialchars((string)\$__themeJsSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')"
                    . " . '\'' . {$markerAttrsPhp} . '></script>'; } ?>";
            }
            $attrsPhp = var_export($attrs !== '' ? ' ' . trim($attrs) : '', true);

            return "<?php \$__themeJsSrc = \$this->fetchTagSource(\\Weline\\Framework\\View\\Data\\DataInterface::dir_type_THEME, {$contentPhp});"
                . " if (\$__themeJsSrc !== '') { echo '<script' . {$attrsPhp} . ' src=\\''"
                . " . htmlspecialchars((string)\$__themeJsSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')"
                . " . '\\'></script>'; } ?>";
        };
    }

    public static function tryStaticMirror(string $tagKey, array $tagData, array $attributes): ?string
    {
        [$content, $attrs] = self::resolveContentAndAttrs($tagKey, $tagData);
        if ($content === '') {
            return '';
        }
        if (!CompileTimeStaticMirror::isLiteralMarkup($content) || !CompileTimeStaticMirror::isLiteralMarkup($attrs)) {
            return null;
        }
        // 属性必须全为字面量才能固化；含 PHP 内联或 $var 的属性退回运行期。
        if (!CompileTimeStaticMirror::attributesAreLiteral($attributes)) {
            return null;
        }

        $path = ThemeAssetSource::normalize($content);
        if ($path === '') {
            return '';
        }

        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);
        // 编译期镜像禁止烘入 preview Token；DEV 仍走内容指纹，PROD 走全局戳。
        $src = (string)$template->fetchTagSource(DataInterface::dir_type_THEME, $path, true, false);
        if ($src === '') {
            return '';
        }
        $src = self::resolveGenerationFallback($src);

        if (self::hasLoadAttribute($attributes, $attrs)) {
            return '<script type="application/x-weline-theme-js" data-weline-theme-js="'
                . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"' . self::buildLoadMarkerAttrs($attributes, $attrs) . '></script>';
        }

        return '<script' . self::buildMirrorAttrPart($attrs, $attributes) . ' src=\''
            . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '\'></script>';
    }

    /**
     * 编译期静态镜像必须原样保留原生属性（defer / async / data-* …）。
     *
     * 历史缺陷：tryStaticMirror 只读 tag_data 里的 raw 属性串，而编译期
     * tag_data[1] 是**资源路径**（运行期才是 rawAttributes），属性只存在于
     * $attributes —— 于是 `<theme:js data-order="60" defer>` 被固化成无属性的
     * `<script>`，com 阶段排序与前端验收都拿不到标记。
     * 取值顺序与运行期 callback 保持一致：raw 优先，为空时回落到 $attributes。
     *
     * @param array<string, mixed> $attributes
     */
    /**
     * 主题更新代次根（/static/tu-<gen>/...）可能在代次轮换后被清理：
     * 烘焙/运行期若发现该文件已不存在，回退到规范路径（并加构建代次）。
     */
    public static function fallbackGenerationUrl(string $url, callable $exists): string
    {
        if (\preg_match('#^/static/tu-[A-Za-z0-9-]+/(.+)$#', $url, $m) !== 1) {
            return $url;
        }
        $query = \str_contains($url, '?') ? '?' . \explode('?', $url, 2)[1] : '';
        $generationPath = \strtok($url, '?');
        if ($exists($generationPath)) {
            return $url;
        }
        // 正则会把 query 一起吞进来，规范路径必须去掉 query 再判存在/加代次。
        $canonical = '/static/' . \strtok($m[1], '?');
        if (!$exists($canonical)) {
            return $url;
        }

        return \Weline\Framework\Deploy\StaticBuildId::versionedPath($canonical) . $query;
    }

    public static function resolveGenerationFallbackPublic(string $url): string
    {
        return self::resolveGenerationFallback($url);
    }

    private static function resolveGenerationFallback(string $url): string
    {
        return self::fallbackGenerationUrl(
            $url,
            static fn(string $publicPath): bool => \is_file(PUB . \ltrim($publicPath, '/')),
        );
    }

    /** 是否声明加载：<theme:js load>（运行期属性或编译期 raw 属性均可）。 */
    private static function hasLoadAttribute(array $attributes, string $rawAttrs = ''): bool
    {
        foreach ($attributes as $name => $value) {
            if (\strtolower(\trim((string)$name)) === 'load') {
                return true;
            }
        }

        return \preg_match('/(?:^|\s)load(?:\s|=|$)/', \trim($rawAttrs)) === 1;
    }

    /** 声明加载标记只保留普通属性（data-* / nonce / data-order），去掉 load/defer。 */
    private static function buildLoadMarkerAttrs(array $attributes, string $rawAttrs = ''): string
    {
        $parts = [];
        foreach ($attributes as $name => $value) {
            $name = \trim((string)$name);
            if ($name === '' || \preg_match('/^[a-zA-Z_:][\w:.-]*$/', $name) !== 1) {
                continue;
            }
            $lower = \strtolower($name);
            if ($lower === 'load' || $lower === 'defer') {
                continue;
            }
            if ($value === true || $value === '' || $value === null) {
                $parts[] = $name;
                continue;
            }
            $parts[] = $name . '="' . \htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $parts === [] ? '' : ' ' . \implode(' ', $parts);
    }

    private static function buildMirrorAttrPart(string $rawAttrs, array $attributes): string
    {
        $filtered = trim((string)preg_replace('/[a-zA-Z_][\w]*::[^\s>]*/u', '', trim($rawAttrs)));
        if ($filtered !== '') {
            return ' ' . $filtered;
        }

        $parts = [];
        foreach ($attributes as $name => $value) {
            $name = trim((string)$name);
            if ($name === '' || !preg_match('/^[a-zA-Z_:][\w:.-]*$/', $name)) {
                continue;
            }
            if ($value === true || $value === '' || $value === null) {
                $parts[] = $name;
                continue;
            }
            $parts[] = $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    /**
     * @param array<int|string, mixed> $tagData
     * @return array{0: string, 1: string}
     */
    private static function resolveContentAndAttrs(string $tagKey, array $tagData): array
    {
        $raw1 = trim((string)($tagData[1] ?? ''));
        $raw2 = trim((string)($tagData[2] ?? ''));
        $looksLikePath = $raw1 !== '' && (str_contains($raw1, '::') || str_contains($raw1, '/'));

        if ($tagKey === 'tag') {
            $content = $raw2 !== '' ? $raw2 : $raw1;
            $attrs = (!$looksLikePath && $raw1 !== '') ? $raw1 : '';
        } elseif ($tagKey === '@tag()' || $tagKey === '@tag{}') {
            $content = $raw1;
            $attrs = '';
        } else {
            $content = $raw2 !== '' ? $raw2 : $raw1;
            $attrs = '';
        }

        return [$content, $attrs];
    }

    private static function buildRuntimeSourceExpression(string $content): string
    {
        return '\\Weline\\Theme\\Taglib\\ThemeAssetSource::normalize('
            . self::buildRuntimeStringExpression($content)
            . ')';
    }

    private static function buildRuntimeStringExpression(string $content): string
    {
        $segments = [];
        $offset = 0;
        $pattern = '/<\?(?:php\s+echo|=)\s*(.*?)\s*;?\s*\?>/s';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $match) {
                [$fullTag, $position] = $match;
                $literal = substr($content, $offset, $position - $offset);
                if ($literal !== '') {
                    $segments[] = var_export($literal, true);
                }

                $expression = trim((string)$matches[1][$index][0]);
                if ($expression !== '') {
                    $segments[] = '(string)(' . $expression . ')';
                }

                $offset = $position + strlen($fullTag);
            }
        }

        $tail = substr($content, $offset);
        if ($tail !== '') {
            $segments[] = var_export($tail, true);
        }

        return $segments ? implode(' . ', $segments) : "''";
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return <<<'DOC'
加载模块 view/theme/ 下的 JS（默认模块 Weline_Theme，可写 Vendor_Module::相对路径）。

字面量路径在编译期直出最终 &lt;script&gt;（与内置 &lt;js&gt; 同形）；含 &lt;?= ?&gt; 的动态路径仍吐运行期 PHP。

示例：
<theme:js>frontend/js/catalog-page.js</theme:js>
<theme:js>Vendor_Theme::frontend/js/catalog-page.js</theme:js>
<theme:js>Weline_Other::frontend/js/custom.js</theme:js>

模块 statics 请继续用 @static(...) 或内置 <js>，不要改写成 theme:js。
DOC;
    }
}
