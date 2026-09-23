<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * 默认页脚链接分组 / 分项 / 扩展槽（Partials 兼容 + footer-container 主部件共用）
 *
 * 标准四列：了解我们 / 合作信息 / 支付与账户 / 帮助中心；固定 slot 挂在标准 key 上。
 */
final class FooterDefaultLinksHelper
{
    public const SLOT_ABOUT = 'footer-about-links';
    public const SLOT_PARTNER = 'footer-partner-links';
    public const SLOT_PAYMENT = 'footer-payment-account-links';
    public const SLOT_HELP = 'footer-help-links';

    /**
     * Keep contact/same-page links intact; let @url localize internal routes.
     *
     * @return array{url:string,url_path:string}
     */
    public static function splitUrlForTaglib(string $url, ?string $prefix = null): array
    {
        $url = trim($url);
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|[?\#])#i', $url) === 1) {
            return ['url' => $url, 'url_path' => ''];
        }

        return ProductCardUrl::splitForTaglib($url, $prefix);
    }

    /**
     * @return list<string>
     */
    public static function standardExtensionSlotIds(): array
    {
        return [
            self::SLOT_ABOUT,
            self::SLOT_PARTNER,
            self::SLOT_PAYMENT,
            self::SLOT_HELP,
        ];
    }

    /**
     * @param iterable<int, array<string, mixed>> $widgets
     */
    public static function usesExtensionSlots(iterable $widgets): bool
    {
        $slots = array_fill_keys(self::standardExtensionSlotIds(), true);
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $slotId = (string)($widget['slot_id'] ?? '');
            if (!isset($slots[$slotId]) || !(bool)($widget['is_active'] ?? true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param iterable<int, array<string, mixed>> $widgets
     */
    public static function hasFooterContainer(iterable $widgets): bool
    {
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if ((string)($widget['widget_code'] ?? '') !== 'footer-container') {
                continue;
            }
            if ((string)($widget['slot_id'] ?? '') !== 'footer') {
                continue;
            }
            if (!(bool)($widget['is_active'] ?? true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function synthesizedFooterContainerWidget(): array
    {
        return [
            'widget_module' => 'Weline_Theme',
            'widget_type' => 'footer',
            'widget_code' => 'footer-container',
            'slot_id' => 'footer',
            'area' => 'footer',
            'sort_order' => 0,
            'is_active' => true,
            'config' => [],
        ];
    }

    /**
     * 扩展槽部件已配置但缺少 footer-container 时，补齐父容器（非 default_injections 空槽回填）。
     *
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public static function ensureFooterContainerInLayout(array $layout): array
    {
        $allWidgets = [];
        foreach ($layout as $areaData) {
            if (!is_array($areaData['widgets'] ?? null)) {
                continue;
            }
            foreach ($areaData['widgets'] as $widget) {
                if (is_array($widget)) {
                    $allWidgets[] = $widget;
                }
            }
        }

        if (!self::usesExtensionSlots($allWidgets) || self::hasFooterContainer($allWidgets)) {
            return $layout;
        }

        if (!isset($layout['footer']) || !is_array($layout['footer'])) {
            $layout['footer'] = [
                'label' => '底部区域',
                'widgets' => [],
            ];
        }
        if (!is_array($layout['footer']['widgets'] ?? null)) {
            $layout['footer']['widgets'] = [];
        }

        array_unshift($layout['footer']['widgets'], self::synthesizedFooterContainerWidget());

        return $layout;
    }

    /**
     * @return array<string, array{id:string,name:string,accept:string,section_class:string}>
     */
    public static function standardGroupSlots(): array
    {
        return [
            'about' => [
                'id' => self::SLOT_ABOUT,
                'name' => '了解我们扩展',
                'accept' => 'footer-blog-link,footer-news-link,layout-footer-about-links',
                'section_class' => 'footer-section--about',
            ],
            'partner' => [
                'id' => self::SLOT_PARTNER,
                'name' => '合作信息扩展',
                'accept' => 'footer-publish-link,footer-promote-link,layout-footer-partner-links',
                'section_class' => 'footer-section--partner',
            ],
            'payment' => [
                'id' => self::SLOT_PAYMENT,
                'name' => '支付与账户扩展',
                'accept' => 'footer-payment-methods-link,footer-social-login-link,footer-currency-rates-link,footer-campaign-link,layout-footer-payment-account-links',
                'section_class' => 'footer-section--payment',
            ],
            'help' => [
                'id' => self::SLOT_HELP,
                'name' => '帮助中心扩展',
                'accept' => 'footer-my-account-link,footer-my-orders-link,footer-shipping-info-link,footer-returns-policy-link,footer-faq-link,footer-contact-service-link,layout-footer-help-links',
                'section_class' => 'footer-section--help',
            ],
        ];
    }

    /**
     * @return list<array{key:string,enabled:bool,title:string}>
     */
    public static function defaultLinkGroups(): array
    {
        return [
            ['key' => 'about', 'enabled' => true, 'title' => '关于我们'],
            ['key' => 'partner', 'enabled' => true, 'title' => '定制与合作'],
            ['key' => 'payment', 'enabled' => true, 'title' => '支付与账户'],
            ['key' => 'help', 'enabled' => true, 'title' => '帮助中心'],
        ];
    }

    /**
     * @return list<array{group_key:string,label:string,url:string,open_in_new:bool}>
     */
    public static function defaultLinkItems(): array
    {
        return [
            [
                'group_key' => 'about',
                'label' => '关于我们',
                'url' => '/about',
                'open_in_new' => false,
            ],
            [
                'group_key' => 'partner',
                'label' => '供应商合作',
                'url' => '/inquiry/suppliers',
                'open_in_new' => false,
            ],
        ];
    }

    /**
     * @return list<array{title:string,links:list<array{label:string,url:string}>}>
     */
    public static function widgetLinkGroups(): array
    {
        $byKey = [];
        foreach (self::defaultLinkGroups() as $group) {
            $byKey[(string)$group['key']] = [
                'title' => (string)$group['title'],
                'links' => [],
            ];
        }
        foreach (self::defaultLinkItems() as $item) {
            $key = (string)($item['group_key'] ?? '');
            if ($key === '' || !isset($byKey[$key])) {
                continue;
            }
            $byKey[$key]['links'][] = [
                'label' => (string)($item['label'] ?? ''),
                'url' => (string)($item['url'] ?? '#'),
            ];
        }

        return array_values($byKey);
    }

    /**
     * 底部法律/政策链接（亚马逊版权行风格）
     *
     * @return list<array{text:string,url:string,open_in_new?:bool}>
     */
    public static function legalLinks(): array
    {
        return [
            ['text' => '使用条件', 'url' => '/terms', 'open_in_new' => false],
            ['text' => '隐私声明', 'url' => '/policy/privacy', 'open_in_new' => false],
            ['text' => 'Cookie 政策', 'url' => '/policy/cookie', 'open_in_new' => false],
            ['text' => '无障碍声明', 'url' => '/policy/accessibility', 'open_in_new' => false],
        ];
    }

    /**
     * @return list<array{name:string,icon:string,url:string}>
     */
    public static function defaultSocialItems(): array
    {
        // Official Chang'an Hanfu profiles (ops-registered 2026-09-22). Keep http(s)
        // so Organization.sameAs / footer launch readiness emit actionable Trust signals — never `#`.
        return [
            ['name' => 'YouTube', 'icon' => 'fab fa-youtube', 'url' => 'https://www.youtube.com/@changanhanfu'],
            ['name' => 'X', 'icon' => 'fab fa-twitter', 'url' => 'https://x.com/changanhanfu'],
            ['name' => 'Instagram', 'icon' => 'fab fa-instagram', 'url' => 'https://www.instagram.com/changanhanfu/'],
            ['name' => 'TikTok', 'icon' => 'fab fa-tiktok', 'url' => 'https://www.tiktok.com/@changanhanfu_hq'],
        ];
    }

    /**
     * Map a footer social_items row to SocialIconHelper platform code.
     *
     * @param array{name?:mixed,icon?:mixed,url?:mixed,platform?:mixed} $item
     */
    public static function resolveSocialPlatformCode(array $item): string
    {
        $explicit = strtolower(trim((string)($item['platform'] ?? '')));
        if ($explicit !== '' && SocialIconHelper::getIcon($explicit) !== null) {
            return $explicit === 'twitter' ? 'x' : $explicit;
        }

        $icon = strtolower(trim((string)($item['icon'] ?? '')));
        $name = strtolower(trim((string)($item['name'] ?? $item['label'] ?? '')));
        $host = strtolower((string)(parse_url(trim((string)($item['url'] ?? '')), PHP_URL_HOST) ?? ''));

        $haystack = $icon . ' ' . $name . ' ' . $host;
        $map = [
            'youtube' => 'youtube',
            'youtu.be' => 'youtube',
            'instagram' => 'instagram',
            'tiktok' => 'tiktok',
            'pinterest' => 'pinterest',
            'linkedin' => 'linkedin',
            'facebook' => 'facebook',
            'weibo' => 'weibo',
            'wechat' => 'wechat',
            'weixin' => 'wechat',
            'github' => 'github',
            'telegram' => 'telegram',
            'whatsapp' => 'whatsapp',
            'discord' => 'discord',
            'reddit' => 'reddit',
            'snapchat' => 'snapchat',
            'x.com' => 'x',
            'twitter' => 'x',
            'fab fa-x' => 'x',
            'fa-twitter' => 'x',
        ];
        foreach ($map as $needle => $platform) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $platform;
            }
        }

        return 'link';
    }

    /**
     * Absolute http(s) profile URLs for Organization.sameAs (Trust / entity example).
     *
     * @return list<string>
     */
    public static function defaultSameAsUrls(): array
    {
        $urls = [];
        foreach (self::defaultSocialItems() as $item) {
            $url = trim((string)($item['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @return list<array{title:string,items:list<array{text:string,url:string}>}>
     */
    public static function partialLinkGroups(): array
    {
        $groups = [];
        foreach (self::widgetLinkGroups() as $group) {
            $items = [];
            foreach ($group['links'] as $link) {
                $items[] = [
                    'text' => (string)($link['label'] ?? ''),
                    'url' => (string)($link['url'] ?? '#'),
                ];
            }
            $groups[] = [
                'title' => (string)($group['title'] ?? ''),
                'items' => $items,
            ];
        }

        return $groups;
    }

    /**
     * 归一化编辑器配置为可渲染分组（含 slot 元数据；跳过 enabled=false）。
     *
     * @param mixed $groupsRaw
     * @param mixed $itemsRaw
     * @return list<array{
     *   key:string,
     *   title:string,
     *   section_class:string,
     *   items:list<array{label:string,url:string,open_in_new:bool}>,
     *   slot:?array{id:string,name:string,accept:string}
     * }>
     */
    public static function normalizeRenderableGroups(mixed $groupsRaw, mixed $itemsRaw): array
    {
        $groups = self::normalizeGroups($groupsRaw);
        if ($groups === []) {
            $groups = self::defaultLinkGroups();
        }
        $items = self::normalizeItems($itemsRaw);
        if ($items === []) {
            $items = self::defaultLinkItems();
        }

        $itemsByGroup = [];
        foreach ($items as $item) {
            $gk = (string)($item['group_key'] ?? '');
            if ($gk === '') {
                continue;
            }
            $itemsByGroup[$gk][] = $item;
        }

        $slotMap = self::standardGroupSlots();
        $out = [];
        foreach ($groups as $group) {
            if (!(bool)($group['enabled'] ?? true)) {
                continue;
            }
            $key = (string)($group['key'] ?? '');
            if ($key === '') {
                $key = 'custom_' . substr(sha1((string)($group['title'] ?? '') . count($out)), 0, 8);
            }
            $slotMeta = $slotMap[$key] ?? null;
            $out[] = [
                'key' => $key,
                'title' => (string)($group['title'] ?? ''),
                'section_class' => is_array($slotMeta) ? (string)($slotMeta['section_class'] ?? '') : '',
                'items' => $itemsByGroup[$key] ?? [],
                'slot' => is_array($slotMeta)
                    ? [
                        'id' => (string)$slotMeta['id'],
                        'name' => (string)$slotMeta['name'],
                        'accept' => (string)$slotMeta['accept'],
                    ]
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{key:string,enabled:bool,title:string}>
     */
    public static function normalizeGroups(mixed $raw): array
    {
        $list = self::decodeList($raw);
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string)($row['key'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            if ($key === '' && $title === '') {
                continue;
            }
            if ($key === '') {
                $key = 'custom_' . substr(sha1($title . count($out)), 0, 8);
            }
            $enabled = $row['enabled'] ?? true;
            if (is_string($enabled)) {
                $enabled = !in_array(strtolower($enabled), ['0', 'false', 'no', 'off'], true);
            }
            $out[] = [
                'key' => $key,
                'enabled' => (bool)$enabled,
                'title' => $title !== '' ? $title : $key,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{group_key:string,label:string,url:string,open_in_new:bool}>
     */
    public static function normalizeItems(mixed $raw): array
    {
        $list = self::decodeList($raw);
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? $row['text'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            $groupKey = trim((string)($row['group_key'] ?? ''));
            if ($label === '' && $url === '') {
                continue;
            }
            $open = $row['open_in_new'] ?? false;
            if (is_string($open)) {
                $open = in_array(strtolower($open), ['1', 'true', 'yes', 'on'], true);
            }
            $out[] = [
                'group_key' => $groupKey,
                'label' => $label !== '' ? $label : $url,
                'url' => $url !== '' ? $url : '#',
                'open_in_new' => (bool)$open,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{text:string,url:string,open_in_new:bool}>
     */
    public static function normalizeLegalLinks(mixed $raw): array
    {
        $list = self::decodeList($raw);
        if ($list === []) {
            return self::legalLinks();
        }
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = trim((string)($row['text'] ?? $row['label'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            if ($text === '' && $url === '') {
                continue;
            }
            $open = $row['open_in_new'] ?? false;
            if (is_string($open)) {
                $open = in_array(strtolower($open), ['1', 'true', 'yes', 'on'], true);
            }
            $out[] = [
                'text' => $text !== '' ? $text : $url,
                'url' => $url !== '' ? $url : '#',
                'open_in_new' => (bool)$open,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name:string,icon:string,url:string}>
     */
    public static function normalizeSocialItems(mixed $raw): array
    {
        $list = self::decodeList($raw);
        $usedDefaults = false;
        if ($list === []) {
            $list = self::defaultSocialItems();
            $usedDefaults = true;
        }
        $out = self::filterActionableSocialItems($list);
        // Published layouts often still store `#` placeholders; treat that as empty
        // so Organization.sameAs / footer can fall back to actionable defaults.
        if ($out === [] && !$usedDefaults) {
            $out = self::filterActionableSocialItems(self::defaultSocialItems());
        }

        return $out;
    }

    /**
     * @param list<mixed> $list
     * @return list<array{name:string,icon:string,url:string}>
     */
    private static function filterActionableSocialItems(array $list): array
    {
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? $row['label'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
            $isProfileUrl = in_array($scheme, ['http', 'https'], true)
                || str_starts_with($url, '//');
            if (($name === '' && $url === '') || !$isProfileUrl) {
                continue;
            }
            $out[] = [
                'name' => $name !== '' ? $name : $url,
                'icon' => trim((string)($row['icon'] ?? '')),
                'url' => $url,
            ];
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private static function decodeList(mixed $raw): array
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values($raw);
    }
}
