<?php

declare(strict_types=1);

namespace Weline\FileManager\Taglib;

use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;

final class Image implements TaglibInterface
{
    public static function name(): string { return 'file:image'; }
    public static function tag(): bool { return false; }
    public static function tag_start(): bool { return false; }
    public static function tag_end(): bool { return false; }
    public static function tag_self_close(): bool { return true; }
    public static function tag_self_close_with_attrs(): bool { return true; }
    public static function parent(): ?string { return null; }

    public static function attr(): array
    {
        return [
            'usage' => false,
            'asset' => false,
            'alt' => false,
            'decorative' => false,
            'locale' => false,
            'class' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $code = AttributeCodeCompiler::attributes($attributes);
            return '<?php ' . $code
                . ' echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\FileManager\\Service\\FileImageRenderer::class)'
                . '->renderFromMixed($Taglib__usage ?? null, (string)($Taglib__asset ?? \'\'), (string)($Taglib__alt ?? \'\'), filter_var($Taglib__decorative ?? false, FILTER_VALIDATE_BOOL), (string)($Taglib__locale ?? \'\'), (string)($Taglib__class ?? \'\')); ?>';
        };
    }

    public static function document(): string
    {
        return '<w:file:image usage="imageUsage" /> or <w:file:image asset="assetId" alt="已确认的替代文本" />';
    }
}
