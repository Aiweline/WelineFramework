<?php

declare(strict_types=1);

namespace Weline\Geo\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

class Geo implements TaglibInterface
{
    public static function name(): string
    {
        return 'geo';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'slot' => false,
        ];
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
        return static function ($tag_key, $config, $tag_data, $attributes): string {
            $slot = addslashes((string) ($attributes['slot'] ?? 'head'));
            return "<?php echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Geo\\Service\\Head\\GeoDiscoveryRenderer::class)->render(\$this, ['slot' => '{$slot}']); ?>";
        };
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

    public static function document(): string
    {
        return htmlentities('<w:geo slot="head"/> outputs GEO discovery tags for generative engines.');
    }
}
