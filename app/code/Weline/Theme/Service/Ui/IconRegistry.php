<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ui;

final class IconRegistry
{
    private const ICONS = [
        'circle' => '<circle cx="12" cy="12" r="7"/>',
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5M9.5 20v-6h5v6"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.7-4 3.1-6 7-6s6.3 2 7 6"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c.6-4 2.7-6 6-6s5.4 2 6 6M15 15c3 0 4.8 1.7 5.3 5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 13.5v-3l-2.2-.7-.7-1.6 1.1-2-2.1-2.1-2 1.1-1.6-.7L10.5 2h-3l-.7 2.2-1.6.7-2-1.1-2.1 2.1 1.1 2-.7 1.6L0 10.5v3l2.2.7.7 1.6-1.1 2 2.1 2.1 2-1.1 1.6.7.7 2.2h3l.7-2.2 1.6-.7 2 1.1 2.1-2.1-1.1-2 .7-1.6z" transform="translate(2 -0.5) scale(.83)"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'check' => '<path d="m4 12 5 5L20 6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m7.5 12 3 3 6-7"/>',
        'close' => '<path d="m5 5 14 14M19 5 5 19"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8.5 8.5 7 7M15.5 8.5l-7 7"/>',
        'edit' => '<path d="m14.5 5.5 4 4M4 20l1-5L16 4l4 4L9 19z"/>',
        'trash' => '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 10v7M14 10v7"/>',
        'save' => '<path d="M4 3h13l3 3v15H4zM8 3v6h8V3M8 21v-8h8v8"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>',
        'upload' => '<path d="M12 21V9M7 14l5-5 5 5M4 4h16"/>',
        'refresh' => '<path d="M20 7V3l-2 2a8 8 0 1 0 1.2 10M20 3h-4"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.2.9-4.5 4.4 1 6.1-5.4-2.9L6.6 20l1-6.1-4.5-4.4 6.2-.9z"/>',
        'heart' => '<path d="M20 5.5c-2.4-2.3-5.8-1.5-8 1-2.2-2.5-5.6-3.3-8-1C1.2 8.2 2.4 12 5 14.5L12 21l7-6.5c2.6-2.5 3.8-6.3 1-9z"/>',
        'bell' => '<path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5zM10 20h4"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
        'message' => '<path d="M4 5h16v11H9l-5 4z"/>',
        'share' => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.5M8.2 13.2l7.6 4.5"/>',
        'link' => '<path d="M9.5 14.5 14.5 9M7 17H6a4 4 0 0 1 0-8h4M17 7h1a4 4 0 0 1 0 8h-4"/>',
        'external-link' => '<path d="M14 4h6v6M20 4l-9 9M18 13v7H4V6h7"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M7 14h2M11 14h2M15 14h2M7 18h2M11 18h2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/>',
        'pin' => '<path d="M12 22s7-6.1 7-13a7 7 0 1 0-14 0c0 6.9 7 13 7 13z"/><circle cx="12" cy="9" r="2.5"/>',
        'phone' => '<path d="M6 3 3.5 5.5c.8 7.4 6.6 13.2 14 14L20 17l-4-3-2 2c-2.6-1.1-4.9-3.4-6-6l2-2z"/>',
        'device-mobile' => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 5h4M11 19h2"/>',
        'laptop' => '<rect x="5" y="4" width="14" height="11" rx="1"/><path d="M3 19h18l-2-4H5z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5z"/>',
        'monitor' => '<rect x="3" y="3" width="18" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'camera' => '<path d="M4 7h4l2-3h4l2 3h4v13H4z"/><circle cx="12" cy="13" r="4"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8" cy="9" r="2"/><path d="m4 18 5-5 3 3 2-2 6 6"/>',
        'play' => '<path d="m8 5 11 7-11 7z"/>',
        'pause' => '<path d="M8 5v14M16 5v14"/>',
        'stop' => '<rect x="6" y="6" width="12" height="12" rx="1"/>',
        'arrow-up' => '<path d="M12 20V4M5 11l7-7 7 7"/>',
        'arrow-down' => '<path d="M12 4v16M5 13l7 7 7-7"/>',
        'arrow-left' => '<path d="M20 12H4M11 5l-7 7 7 7"/>',
        'arrow-right' => '<path d="M4 12h16M13 5l7 7-7 7"/>',
        'chevron-up' => '<path d="m5 15 7-7 7 7"/>',
        'chevron-down' => '<path d="m5 9 7 7 7-7"/>',
        'chevron-left' => '<path d="m15 5-7 7 7 7"/>',
        'chevron-right' => '<path d="m9 5 7 7-7 7"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'more-horizontal' => '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
        'more-vertical' => '<circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'unlock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7-2M12 14v3"/>',
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="m3 3 18 18M9.5 6.4A11 11 0 0 1 12 6c6.5 0 10 6 10 6a18 18 0 0 1-3 3.7M6.2 7.2C3.5 9 2 12 2 12s3.5 6 10 6a10 10 0 0 0 3-.4"/>',
        'warning' => '<path d="M12 3 2 21h20zM12 9v5M12 18h.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.7 2.2c-.8.4-1.2 1-1.2 2.3M12 17h.01"/>',
        'folder' => '<path d="M3 6h7l2 2h9v12H3z"/>',
        'file' => '<path d="M6 3h8l4 4v14H6zM14 3v5h4"/>',
        'printer' => '<path d="M7 8V3h10v5M7 17h10v4H7z"/><rect x="3" y="8" width="18" height="10" rx="2"/><path d="M17 12h.01"/>',
        'copy' => '<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h3"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V4h6v3M3 12h18M10 12v2h4v-2"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'credit-card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'cart' => '<path d="M3 4h2l2.5 11h10L20 7H6M9 20h.01M17 20h.01"/>',
        'store' => '<path d="M4 10v11h16V10M3 10l2-6h14l2 6M8 21v-6h8v6"/><path d="M3 10c0 2 3 2 4.5 0 1.5 2 4.5 2 6 0 1.5 2 4.5 2 6 0 1.5 2 4.5 2 4.5 0" transform="scale(.89) translate(1.5)"/>',
        'box' => '<path d="m4 7 8-4 8 4v10l-8 4-8-4zM4 7l8 4 8-4M12 11v10"/>',
        'truck' => '<path d="M3 6h11v11H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>',
        'code' => '<path d="m8 7-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/>',
        'terminal' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M12 16h5"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7"/>',
        'server' => '<rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="18" height="7" rx="1"/><path d="M7 6.5h.01M7 17.5h.01M11 6.5h7M11 17.5h7"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
        'language' => '<path d="M3 5h10M8 3v2c0 5-2 8-5 10M5 10c2 2 4 3 7 4M14 21l4-10 4 10M15.5 17h5"/>',
        'palette' => '<path d="M12 3a9 9 0 0 0 0 18h1.5a2 2 0 0 0 0-4H12a2 2 0 0 1 0-4h5a4 4 0 0 0 4-4c0-3.3-4-6-9-6z"/><circle cx="7" cy="9" r="1"/><circle cx="10" cy="6" r="1"/><circle cx="15" cy="7" r="1"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 3.2 8 8 10 4.8-2 8-5 8-10V6z"/><path d="m8 12 3 3 5-6"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M16 7l2 2M14 9l2 2"/>',
        'logout' => '<path d="M10 4H4v16h6M14 8l4 4-4 4M18 12H8"/>',
        'login' => '<path d="M14 4h6v16h-6M10 8l4 4-4 4M14 12H4"/>',
        'fire' => '<path d="M13 3c1 5-4 5-4 10 0 2 1 3 3 4-1-3 2-4 3-6 3 2 5 5 3 8-2 3-9 3-12-1-4-6 2-10 7-15z"/>',
        'history' => '<path d="M4 5v5h5M4.5 9A8 8 0 1 1 5 16M12 7v5l4 2"/>',
        'tag' => '<path d="M3 4h8l10 10-7 7L4 11z"/><circle cx="8" cy="8" r="1.5"/>',
        'puzzle' => '<path d="M4 5h6a2.5 2.5 0 1 1 4 0h6v6a2.5 2.5 0 1 0 0 4v5h-6a2.5 2.5 0 1 0-4 0H4v-5a2.5 2.5 0 1 0 0-4z"/>',
        'robot' => '<rect x="4" y="7" width="16" height="13" rx="3"/><path d="M12 3v4M8 12h.01M16 12h.01M8 16h8"/>',
        'dns' => '<rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/><path d="M7 7h.01M7 17h.01M11 7h7M11 17h7"/>',
        'spinner' => '<path d="M21 12a9 9 0 1 1-3-6.7"/>',
        'switch' => '<rect x="3" y="7" width="18" height="10" rx="5"/><circle cx="9" cy="12" r="3"/>',
        'swap' => '<path d="M4 7h14M14 3l4 4-4 4M20 17H6M10 13l-4 4 4 4"/>',
        'import' => '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>',
        'export' => '<path d="M12 21V9M7 14l5-5 5 5M4 4h16"/>',
        'sparkles' => '<path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2zM5 14l.8 2.2L8 17l-2.2.8L5 20l-.8-2.2L2 17l2.2-.8zM19 13l.8 2.2L22 16l-2.2.8L19 19l-.8-2.2L16 16l2.2-.8z"/>',
        'rocket' => '<path d="M14 4c3-2 5-1 6-1 0 1 1 3-1 6l-5 5-4-4zM9 11l-4 1-2 4 6-1M13 15l-1 6 4-2 1-4"/><circle cx="15.5" cy="7.5" r="1.5"/>',
        'bolt' => '<path d="m13 2-8 12h7l-1 8 8-12h-7z"/>',
        'cloud' => '<path d="M7 19h11a4 4 0 0 0 .5-8A7 7 0 0 0 5 9a5 5 0 0 0 2 10z"/>',
        'wifi' => '<path d="M3 9c5-4 13-4 18 0M6 13c3.5-2.7 8.5-2.7 12 0M9.5 17c1.5-1.1 3.5-1.1 5 0M12 21h.01"/>',
        'webhook' => '<circle cx="6" cy="12" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M9 11l6-3M9 13l6 3"/>',
        'branch' => '<circle cx="7" cy="5" r="2"/><circle cx="17" cy="7" r="2"/><circle cx="7" cy="19" r="2"/><path d="M7 7v10M9 10c4 0 6-1 6-3"/>',
        'tree' => '<path d="M12 3v5M6 21v-5h12v5M5 8h14v5H5zM12 13v3"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M8 9h8M8 13h8M8 17h5"/>',
        'table' => '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 9h18M3 14h18M9 4v16M15 4v16"/>',
        'book' => '<path d="M4 4h6a4 4 0 0 1 4 4v12H8a4 4 0 0 0-4 2zM20 4h-6v16h2a4 4 0 0 1 4 2z"/>',
        'archive' => '<path d="M4 8h16v13H4zM3 3h18v5H3zM9 12h6"/>',
        'cash' => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M7 9h.01M17 15h.01"/>',
        'beaker' => '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3M8 15h8"/>',
        'sort' => '<path d="M8 4v16M4 8l4-4 4 4M16 20V4M12 16l4 4 4-4"/>',
    ];

    public function names(): array
    {
        return array_keys(self::ICONS);
    }

    public function has(string $name): bool
    {
        return isset(self::ICONS[$name]);
    }

    public function render(string $name, string $size = 'md', string $label = '', string $class = ''): string
    {
        $name = $this->has($name) ? $name : 'circle';
        $size = in_array($size, ['xs', 'sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
        $classes = ['w-icon'];
        foreach (preg_split('/\s+/', trim($class)) ?: [] as $token) {
            if ($token !== '' && preg_match('/^w-[a-z0-9_-]+$/', $token) === 1) {
                $classes[] = $token;
            }
        }
        $classAttr = htmlspecialchars(implode(' ', array_unique($classes)), ENT_QUOTES, 'UTF-8');
        $nameAttr = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $label = trim($label);
        $accessibility = $label === ''
            ? 'aria-hidden="true"'
            : 'role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"';

        return sprintf(
            '<svg class="%s" data-size="%s" data-icon="%s" %s viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">%s</svg>',
            $classAttr,
            $size,
            $nameAttr,
            $accessibility,
            self::ICONS[$name],
        );
    }

    public function sprite(): string
    {
        $symbols = [];
        foreach (self::ICONS as $name => $elements) {
            $symbols[] = sprintf(
                '<symbol id="w-icon-%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">%s</symbol>',
                $name,
                $elements,
            );
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">'
            . implode('', $symbols)
            . "</svg>\n";
    }
}
