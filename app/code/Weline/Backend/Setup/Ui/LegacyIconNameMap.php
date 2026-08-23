<?php

declare(strict_types=1);

namespace Weline\Backend\Setup\Ui;

/**
 * Upgrade-only mapping from historical font-icon values to Weline semantic names.
 *
 * This class is intentionally located below Setup: browser/runtime code must never
 * parse historical icon values after the Weline UI 2.0 cut-over.
 */
final class LegacyIconNameMap
{
    /** @var array<string, string> */
    private const EXACT = [
        'ip' => 'globe',
        'ip-network' => 'globe',
        'dns' => 'dns',
        'domain' => 'globe',
        'web' => 'globe',
        'puzzle' => 'puzzle',
        'puzzle-outline' => 'puzzle',
        'robot' => 'robot',
        'robot-outline' => 'robot',
        'api' => 'code',
        'tag' => 'tag',
        'tag-outline' => 'tag',
        'tag-multiple' => 'tag',
        'tag-multiple-outline' => 'tag',
        'tag-search-outline' => 'search',
        'loading' => 'spinner',
        'spinner' => 'spinner',
        'toggle-switch' => 'switch',
        'swap-horizontal' => 'swap',
        'import' => 'import',
        'export' => 'export',
        'database-export' => 'export',
        'database-arrow-up-outline' => 'export',
        'database-plus-outline' => 'database',
        'broom' => 'trash',
        'auto-fix' => 'sparkles',
        'rocket-launch-outline' => 'rocket',
        'lightning-bolt' => 'bolt',
        'lightning-bolt-outline' => 'bolt',
        'flashlight' => 'bolt',
        'cloud' => 'cloud',
        'wifi' => 'wifi',
        'access-point' => 'wifi',
        'webhook' => 'webhook',
        'source-branch' => 'branch',
        'source-branch-sync' => 'branch',
        'connection' => 'link',
        'sitemap' => 'tree',
        'file-tree' => 'tree',
        'file-tree-outline' => 'tree',
        'clipboard-list-outline' => 'clipboard',
        'clipboard-text-clock-outline' => 'clipboard',
        'table' => 'table',
        'book-open-variant' => 'book',
        'archive-arrow-down-outline' => 'archive',
        'cash-multiple' => 'cash',
        'cash-check' => 'cash',
        'cash-refund' => 'cash',
        'currency-usd' => 'cash',
        'flask-outline' => 'beaker',
        'test-tube' => 'beaker',
        'sort' => 'sort',
        'shape-outline' => 'grid',
        'sticker' => 'star',
        'radar' => 'search',
        'send' => 'arrow-right',
        'restore' => 'history',
        'backup-restore' => 'history',
        'replay' => 'refresh',
        'update' => 'refresh',
        'tune-variant' => 'settings',
        'tools' => 'settings',
    ];

    /** @var array<string, string> */
    private const KEYWORDS = [
        'dashboard' => 'grid',
        'view-dashboard' => 'grid',
        'home' => 'home',
        'account-group' => 'users',
        'users' => 'users',
        'user-group' => 'users',
        'account-multiple' => 'users',
        'account' => 'user',
        'user' => 'user',
        'person' => 'user',
        'cog' => 'settings',
        'gear' => 'settings',
        'settings' => 'settings',
        'wrench' => 'settings',
        'search' => 'search',
        'magnify' => 'search',
        'add' => 'plus',
        'plus' => 'plus',
        'remove' => 'minus',
        'minus' => 'minus',
        'check' => 'check',
        'done' => 'check',
        'times' => 'close',
        'close' => 'close',
        'cancel' => 'close',
        'pencil' => 'edit',
        'edit' => 'edit',
        'delete' => 'trash',
        'trash' => 'trash',
        'save' => 'save',
        'floppy' => 'save',
        'download' => 'download',
        'upload' => 'upload',
        'sync' => 'refresh',
        'reload' => 'refresh',
        'refresh' => 'refresh',
        'star' => 'star',
        'favorite' => 'heart',
        'heart' => 'heart',
        'notification' => 'bell',
        'bell' => 'bell',
        'email' => 'mail',
        'envelope' => 'mail',
        'mail' => 'mail',
        'comment' => 'message',
        'message' => 'message',
        'share' => 'share',
        'external' => 'external-link',
        'link' => 'link',
        'calendar' => 'calendar',
        'schedule' => 'calendar',
        'time' => 'clock',
        'clock' => 'clock',
        'map-marker' => 'pin',
        'location' => 'pin',
        'pin' => 'pin',
        'phone' => 'phone',
        'mobile' => 'device-mobile',
        'laptop' => 'laptop',
        'desktop' => 'monitor',
        'monitor' => 'monitor',
        'weather-sunny' => 'sun',
        'sun' => 'sun',
        'weather-night' => 'moon',
        'moon' => 'moon',
        'camera' => 'camera',
        'picture' => 'image',
        'image' => 'image',
        'play' => 'play',
        'pause' => 'pause',
        'stop' => 'stop',
        'arrow-up' => 'arrow-up',
        'arrow-down' => 'arrow-down',
        'arrow-left' => 'arrow-left',
        'arrow-right' => 'arrow-right',
        'chevron-up' => 'chevron-up',
        'chevron-down' => 'chevron-down',
        'chevron-left' => 'chevron-left',
        'chevron-right' => 'chevron-right',
        'menu' => 'menu',
        'apps' => 'grid',
        'grid' => 'grid',
        'th-large' => 'grid',
        'format-list' => 'list',
        'list' => 'list',
        'filter' => 'filter',
        'dots-horizontal' => 'more-horizontal',
        'ellipsis-h' => 'more-horizontal',
        'more' => 'more-horizontal',
        'dots-vertical' => 'more-vertical',
        'ellipsis-v' => 'more-vertical',
        'unlock' => 'unlock',
        'lock' => 'lock',
        'visibility-off' => 'eye-off',
        'eye-off' => 'eye-off',
        'visibility' => 'eye',
        'eye' => 'eye',
        'alert' => 'warning',
        'warning' => 'warning',
        'exclamation' => 'warning',
        'information' => 'info',
        'info' => 'info',
        'question' => 'help',
        'help' => 'help',
        'folder' => 'folder',
        'file' => 'file',
        'content-copy' => 'copy',
        'copy' => 'copy',
        'briefcase' => 'briefcase',
        'chart' => 'chart',
        'analytics' => 'chart',
        'credit-card' => 'credit-card',
        'payment' => 'credit-card',
        'shopping-cart' => 'cart',
        'cart' => 'cart',
        'storefront' => 'store',
        'store' => 'store',
        'package' => 'box',
        'cube' => 'box',
        'box' => 'box',
        'shipping' => 'truck',
        'truck' => 'truck',
        'code' => 'code',
        'console' => 'terminal',
        'terminal' => 'terminal',
        'database' => 'database',
        'server' => 'server',
        'world' => 'globe',
        'earth' => 'globe',
        'globe' => 'globe',
        'translate' => 'language',
        'language' => 'language',
        'palette' => 'palette',
        'paint' => 'palette',
        'security' => 'shield',
        'shield' => 'shield',
        'key' => 'key',
        'sign-out' => 'logout',
        'logout' => 'logout',
        'sign-in' => 'login',
        'login' => 'login',
        'fire' => 'fire',
        'history' => 'history',
    ];

    public function map(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || !$this->isLegacy($normalized)) {
            return null;
        }

        $candidate = $this->candidate($normalized);
        if (isset(self::EXACT[$candidate])) {
            return self::EXACT[$candidate];
        }
        foreach (self::KEYWORDS as $keyword => $semanticName) {
            if ($candidate === $keyword || str_contains($candidate, $keyword)) {
                return $semanticName;
            }
        }

        return 'circle';
    }

    public function isLegacy(string $value): bool
    {
        return preg_match(
            '/(?:^|\s)(?:mdi(?:-[a-z0-9]+)*|fa(?:s|r|b)?|fa-(?:solid|regular|brands)|ri|bi)-?[a-z0-9_-]*/i',
            trim($value),
        ) === 1;
    }

    private function candidate(string $value): string
    {
        $tokens = preg_split('/\s+/', $value) ?: [];
        foreach (array_reverse($tokens) as $token) {
            $token = preg_replace('/^(?:mdi|fa(?:s|r|b)?|fa-(?:solid|regular|brands)|ri|bi)-?/', '', $token) ?? '';
            $token = preg_replace('/-(?:line|fill|outline|outlined|round|sharp|solid|regular)$/', '', $token) ?? $token;
            if ($token !== '' && !in_array($token, ['mdi', 'fa', 'fas', 'far', 'fab', 'ri', 'bi'], true)) {
                return trim($token, '-_');
            }
        }

        return $value;
    }
}
