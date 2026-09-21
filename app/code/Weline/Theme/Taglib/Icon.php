<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\CompileTimeStaticMirror;
use Weline\Framework\Taglib\StaticMirrorCapableInterface;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Service\Ui\IconRegistry;

final class Icon implements TaglibInterface, StaticMirrorCapableInterface
{
    public static function name(): string
    {
        return 'icon';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return ['name' => true, 'size' => false, 'label' => false, 'class' => false];
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

            return '<?php ' . $code . ' echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Theme\\Service\\Ui\\IconRegistry::class)->render((string)$Taglib__name, (string)($Taglib__size ?? \'md\'), (string)($Taglib__label ?? \'\'), (string)($Taglib__class ?? \'\')); ?>';
        };
    }

    public static function tryStaticMirror(string $tagKey, array $tagData, array $attributes): ?string
    {
        if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs' && $tagKey !== 'tag') {
            return null;
        }

        $keys = ['name', 'size', 'label', 'class'];
        if (!CompileTimeStaticMirror::attributesAreLiteral($attributes, $keys)) {
            return null;
        }

        $name = CompileTimeStaticMirror::literalString($attributes['name'] ?? '');
        if ($name === '') {
            return null;
        }

        /** @var IconRegistry $registry */
        $registry = ObjectManager::getInstance(IconRegistry::class);

        return $registry->render(
            $name,
            CompileTimeStaticMirror::literalString($attributes['size'] ?? '', 'md'),
            CompileTimeStaticMirror::literalString($attributes['label'] ?? ''),
            CompileTimeStaticMirror::literalString($attributes['class'] ?? ''),
        );
    }

    /**
     * AST 动态属性路径会走 renderRuntimeTag，此处必须直接输出 SVG/HTML。
     */
    public static function runtimeCallback(): callable
    {
        return static function (
            Template $template,
            string $tagKey,
            array $attributes,
            string $content,
        ): string {
            if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs') {
                return '';
            }

            $name = self::resolveRuntimeAttribute($attributes, 'name');
            $size = self::resolveRuntimeAttribute($attributes, 'size', 'md');
            $label = self::resolveRuntimeAttribute($attributes, 'label');
            $class = self::resolveRuntimeAttribute($attributes, 'class');

            /** @var IconRegistry $registry */
            $registry = ObjectManager::getInstance(IconRegistry::class);

            return $registry->render($name, $size, $label, $class);
        };
    }

    private static function resolveRuntimeAttribute(array $attributes, string $key, string $default = ''): string
    {
        $value = $attributes[$key] ?? $default;
        if (\is_scalar($value) || $value === null) {
            $string = trim((string)$value);

            return $string !== '' ? $string : $default;
        }

        return $default;
    }

    public static function document(): string
    {
        return '<w:icon name="settings" size="sm" label="设置" />'
            . ' — 字面量 name/size/label/class 在编译期直出 SVG；动态属性仍吐运行期 PHP 或走 runtimeCallback。';
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
