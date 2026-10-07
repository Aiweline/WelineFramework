<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\CompileTimeStaticMirror;
use Weline\Framework\Taglib\StaticMirrorCapableInterface;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Theme\Font\FontFaceService;

/**
 * Load a module font with language (or custom chars) subsetting.
 *
 * Source files live in `{Module}/view/fonts/`.
 * - `Relative/Path.ttf` — defaults to Weline_Theme
 * - `Vendor_Module::Relative/Path.ttf` — explicit module
 *
 * Literal attributes bake the final `<style>@font-face` HTML at Taglib/com_*
 * compile time (same contract as theme:css / @lang). Dynamic embeds still emit PHP.
 */
final class ThemeFont implements TaglibInterface, StaticMirrorCapableInterface
{
    public static function name(): string
    {
        return 'theme:font';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'src' => true,
            'family' => false,
            'lang' => false,
            'chars' => false,
            'weight' => false,
            'style' => false,
            'display' => false,
            'unicode-range' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $attrs = is_array($attributes) ? $attributes : [];
            $mirrored = self::tryStaticMirror((string)$tagKey, is_array($tagData) ? $tagData : [], $attrs);
            if ($mirrored !== null) {
                return $mirrored;
            }

            $code = AttributeCodeCompiler::attributes($attrs);
            // Only pass chars when the attribute is present on THIS tag (avoid leak across tags).
            $charsExpr = array_key_exists('chars', $attrs)
                ? '(string)($Taglib__chars ?? \'\')'
                : "''";

            return '<?php ' . $code
                . ' echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance('
                . '\\Weline\\Theme\\Font\\FontFaceService::class)->renderStyleTag(['
                . '\'src\' => (string)($Taglib__src ?? \'\'),'
                . '\'family\' => (string)($Taglib__family ?? \'\'),'
                . '\'lang\' => (string)($Taglib__lang ?? \'\'),'
                . '\'chars\' => ' . $charsExpr . ','
                . '\'weight\' => (string)($Taglib__weight ?? \'400\'),'
                . '\'style\' => (string)($Taglib__style ?? \'normal\'),'
                . '\'display\' => (string)($Taglib__display ?? \'swap\'),'
                . '\'unicode-range\' => (string)($Taglib__unicode_range ?? \'\'),'
                . ']); ?>';
        };
    }

    public static function tryStaticMirror(string $tagKey, array $tagData, array $attributes): ?string
    {
        if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs' && $tagKey !== 'tag') {
            return null;
        }

        $keys = ['src', 'family', 'lang', 'chars', 'weight', 'style', 'display', 'unicode-range', 'unicode_range'];
        if (!CompileTimeStaticMirror::attributesAreLiteral($attributes, $keys)) {
            return null;
        }

        $src = CompileTimeStaticMirror::literalString($attributes['src'] ?? '');
        if ($src === '') {
            return null;
        }

        $options = [
            'src' => $src,
            'family' => CompileTimeStaticMirror::literalString($attributes['family'] ?? ''),
            'lang' => CompileTimeStaticMirror::literalString($attributes['lang'] ?? ''),
            'chars' => array_key_exists('chars', $attributes)
                ? CompileTimeStaticMirror::literalString($attributes['chars'] ?? '')
                : '',
            'weight' => CompileTimeStaticMirror::literalString($attributes['weight'] ?? '', '400'),
            'style' => CompileTimeStaticMirror::literalString($attributes['style'] ?? '', 'normal'),
            'display' => CompileTimeStaticMirror::literalString($attributes['display'] ?? '', 'swap'),
            'unicode-range' => CompileTimeStaticMirror::literalString(
                $attributes['unicode-range'] ?? $attributes['unicode_range'] ?? ''
            ),
        ];

        /** @var FontFaceService $face */
        $face = ObjectManager::getInstance(FontFaceService::class);

        return $face->renderStyleTag($options);
    }

    public static function document(): string
    {
        return <<<'DOC'
按语言或指定字符子集化加载模块字体（源文件在 Module/view/fonts/）。
省略模块时默认 Weline_Theme；写 Vendor_Module:: 可指定其他模块。

字面量 src/family/lang/chars/… 在 Taglib/com_* 编译期烘焙最终 <style>@font-face（对齐 theme:css / @lang）；
动态属性仍吐运行期 PHP。布局实体关系固化不处理本标签。

按语言（省略 lang 则跟编译时 State::getLangLocal()）：
<w:theme:font src="NotoSansSC-Regular.ttf" family="Noto Sans SC" weight="400" display="swap" />

只提取属性 chars 里的字符（忽略语言表）：
<w:theme:font src="NotoSansSC-Regular.ttf" family="Brand" chars="仅这些字ABC" weight="700" />

升级/网站语种变更会预热 view/fonts 下字体 × 语种；chars 子集在首次编译烘焙时生成并缓存。详见 Theme/doc/theme-font.md
DOC;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }
}
