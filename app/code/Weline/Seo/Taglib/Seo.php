<?php

declare(strict_types=1);

namespace Weline\Seo\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

class Seo implements TaglibInterface
{
    public static function name(): string
    {
        return 'seo';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'slot' => false,
            'once' => false,
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
            return "<?php echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Seo\\Service\\Head\\HeadRenderer::class)->render(\$this, ['slot' => '{$slot}']); ?>";
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
        return htmlentities('<w:seo slot="head"/> outputs SEO head tags. Supported slot values: head, meta, canonical, social, schema, inspector.');
    }
}
