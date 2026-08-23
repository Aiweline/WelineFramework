<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * theme:css — load CSS from a module's `view/theme/` (default module: Weline_Theme).
 *
 * Module statics stay on `@static(...)` / built-in `<css>`; do not convert those here.
 *
 * Examples:
 * - `<theme:css>frontend/css/catalog-page.css</theme:css>`
 * - `<theme:css>Vendor_Theme::frontend/css/catalog-page.css</theme:css>`
 */
class ThemeCss implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'theme:css';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 框架约定：tag_data[0]=rawTag, [1]=rawAttributes/内联内容, [2]=子内容
            // 运行期 tag-start 时内容在 [2]，编译期 tag 时内容在 [2]、属性在 [1]
            // 若 [1] 被误当作路径（含 :: 或 /），不得作为 HTML 属性输出，并优先当作 content
            $raw1 = trim((string)($tag_data[1] ?? ''));
            $raw2 = trim((string)($tag_data[2] ?? ''));
            $looksLikePath = $raw1 !== '' && (str_contains($raw1, '::') || str_contains($raw1, '/'));

            if ($tag_key === 'tag') {
                $content = $raw2 !== '' ? $raw2 : $raw1;
                $attrs = (!$looksLikePath && $raw1 !== '') ? $raw1 : '';
            } elseif ($tag_key === '@tag()' || $tag_key === '@tag{}') {
                $content = $raw1;
                $attrs = '';
            } else {
                // tag-start 等：运行期内容在 [2]
                $content = $raw2 !== '' ? $raw2 : $raw1;
                $attrs = '';
            }
            if ($content === '') {
                return '';
            }

            $contentPhp = self::buildRuntimeSourceExpression($content);
            $attrsPhp = var_export($attrs ? ' ' . trim($attrs) : '', true);

            return "<?php \$__themeCssHref = \$this->fetchTagSource(\\Weline\\Framework\\View\\Data\\DataInterface::dir_type_THEME, {$contentPhp});"
                . " if (\$__themeCssHref !== '') { echo '<link' . {$attrsPhp} . ' href=\\''"
                . " . htmlspecialchars((string)\$__themeCssHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')"
                . " . '\\' rel=\"stylesheet\" type=\"text/css\"/>'; } ?>";
        };
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

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function parent(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public static function document(): string
    {
        return <<<'DOC'
加载模块 view/theme/ 下的 CSS（默认模块 Weline_Theme，可写 Vendor_Module::相对路径）。

示例：
<theme:css>frontend/css/catalog-page.css</theme:css>
<theme:css>Vendor_Theme::frontend/css/catalog-page.css</theme:css>
<theme:css>Weline_Other::frontend/css/custom.css</theme:css>

模块 statics 请继续用 @static(...) 或内置 <css>，不要改写成 theme:css。
DOC;
    }
}
