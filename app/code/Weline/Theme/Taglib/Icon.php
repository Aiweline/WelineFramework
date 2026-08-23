<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;

final class Icon implements TaglibInterface
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
            $code = AttributeCodeCompiler::attributes($attributes);
            return '<?php ' . $code . ' echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Theme\\Service\\Ui\\IconRegistry::class)->render((string)$Taglib__name, (string)($Taglib__size ?? \'md\'), (string)($Taglib__label ?? \'\'), (string)($Taglib__class ?? \'\')); ?>';
        };
    }

    public static function document(): string
    {
        return '<w:icon name="settings" size="sm" label="设置" />';
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
