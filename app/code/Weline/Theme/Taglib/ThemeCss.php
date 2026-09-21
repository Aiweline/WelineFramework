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
 * theme:css — load CSS from a module's `view/theme/` (default module: Weline_Theme).
 *
 * Module statics stay on `@static(...)` / built-in `<css>`; do not convert those here.
 *
 * When the path (and attrs) are compile-time literals, the callback bakes the final
 * `<link>` HTML (same shape as built-in `<css>`). Dynamic embeds still emit PHP.
 *
 * Examples:
 * - `<theme:css>frontend/css/catalog-page.css</theme:css>`
 * - `<theme:css>Vendor_Theme::frontend/css/catalog-page.css</theme:css>`
 */
class ThemeCss implements TaglibInterface, StaticMirrorCapableInterface
{
    public static function name(): string
    {
        return 'theme:css';
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
            $attrsPhp = var_export($attrs !== '' ? ' ' . trim($attrs) : '', true);

            return "<?php \$__themeCssHref = \$this->fetchTagSource(\\Weline\\Framework\\View\\Data\\DataInterface::dir_type_THEME, {$contentPhp});"
                . " if (\$__themeCssHref !== '') { echo '<link' . {$attrsPhp} . ' href=\\''"
                . " . htmlspecialchars((string)\$__themeCssHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')"
                . " . '\\' rel=\"stylesheet\" type=\"text/css\"/>'; } ?>";
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

        $path = ThemeAssetSource::normalize($content);
        if ($path === '') {
            return '';
        }

        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);
        $href = (string)$template->fetchTagSource(DataInterface::dir_type_THEME, $path);
        if ($href === '') {
            return '';
        }

        $attrPart = $attrs !== '' ? ' ' . trim($attrs) : '';

        return '<link' . $attrPart . ' href=\''
            . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '\' rel="stylesheet" type="text/css"/>';
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
加载模块 view/theme/ 下的 CSS（默认模块 Weline_Theme，可写 Vendor_Module::相对路径）。

字面量路径在编译期直出最终 &lt;link&gt;（与内置 &lt;css&gt; 同形）；含 &lt;?= ?&gt; 的动态路径仍吐运行期 PHP。

示例：
<theme:css>frontend/css/catalog-page.css</theme:css>
<theme:css>Vendor_Theme::frontend/css/catalog-page.css</theme:css>
<theme:css>Weline_Other::frontend/css/custom.css</theme:css>

模块 statics 请继续用 @static(...) 或内置 <css>，不要改写成 theme:css。
DOC;
    }
}
