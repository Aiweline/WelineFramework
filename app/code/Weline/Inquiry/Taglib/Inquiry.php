<?php

declare(strict_types=1);

namespace Weline\Inquiry\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Inquiry\Api\InquiryRendererInterface;

final class Inquiry implements TaglibInterface
{
    public static function name(): string { return 'inquiry'; }
    public static function tag(): bool { return false; }
    public static function attr(): array { return ['code' => true, 'mode' => false, 'id' => false, 'trigger-selector' => false, 'custom-css' => false, 'custom-js' => false]; }
    public static function tag_start(): bool { return false; }
    public static function tag_end(): bool { return false; }
    public static function callback(): callable { return static function ($tagKey, $config, $tagData, $attributes): string { return '<?php echo \\Weline\\Inquiry\\Taglib\\Inquiry::render(' . var_export($attributes, true) . '); ?>'; }; }
    /** @param array<string,mixed> $attributes */
    public static function render(array $attributes): string
    {
        $code = trim((string)($attributes['code'] ?? '')); if ($code === '') { return '<!-- inquiry: missing code -->'; }
        return ObjectManager::getInstance(InquiryRendererInterface::class)->render($code, ['mode' => $attributes['mode'] ?? 'inline', 'id' => $attributes['id'] ?? '', 'trigger_selector' => $attributes['trigger-selector'] ?? '', 'custom_css' => $attributes['custom-css'] ?? '', 'custom_js' => $attributes['custom-js'] ?? '']);
    }
    public static function tag_self_close(): bool { return true; }
    public static function tag_self_close_with_attrs(): bool { return true; }
    public static function parent(): ?string { return null; }
    public static function document(): string { return htmlentities('<w:inquiry code="motorcycle-dealer-quote" mode="modal" trigger-selector="#quote" />'); }
}
