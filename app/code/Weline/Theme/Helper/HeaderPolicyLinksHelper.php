<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * Header 政策/关于链接：默认项派生自 Theme 壳布局，可关闭、可增自定义。
 * 顶栏露出「关于我们」；政策进下拉并细分为法律/履约等分组。
 */
final class HeaderPolicyLinksHelper
{
    public const GROUP_ABOUT = 'about';
    public const GROUP_LEGAL = 'legal';
    public const GROUP_FULFILLMENT = 'fulfillment';
    public const GROUP_POLICY = 'policy';

    /**
     * 与 Policy 控制器标题字典对齐（不含 generic default 壳）。
     *
     * @return array<string, string> layoutOption => 中文源串
     */
    public static function policyLayoutTitles(): array
    {
        return [
            'cookie' => 'Cookie 政策',
            'privacy' => '隐私政策',
            'term-condition' => '服务条款',
            'refund' => '退款政策',
            'disclaimer' => '免责声明',
            'shipping' => '配送政策',
            'accessibility' => '无障碍声明',
        ];
    }

    /**
     * 政策下拉分组标题（中文源串，可译）。
     *
     * @return array<string, string>
     */
    public static function groupTitles(): array
    {
        return [
            self::GROUP_LEGAL => '法律与隐私',
            self::GROUP_FULFILLMENT => '配送与售后',
            self::GROUP_POLICY => '其他政策',
        ];
    }

    /**
     * 扫描 Theme policy 布局文件（跳过 default）。
     *
     * @return array<string, string> option => title
     */
    public static function discoverPolicyOptions(?string $layoutsDir = null): array
    {
        $dir = $layoutsDir ?? (dirname(__DIR__) . '/view/theme/frontend/layouts/policy');
        $titles = self::policyLayoutTitles();
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        foreach (glob(rtrim($dir, '/\\') . '/*.phtml') ?: [] as $file) {
            $option = basename((string)$file, '.phtml');
            if ($option === '' || $option === 'default') {
                continue;
            }
            $out[$option] = $titles[$option] ?? $option;
        }
        ksort($out);

        return $out;
    }

    /**
     * 稳定键 → 默认分组。
     */
    public static function defaultGroupForKey(string $key): string
    {
        $key = strtolower(trim($key));
        if ($key === 'about' || str_starts_with($key, 'about_')) {
            return self::GROUP_ABOUT;
        }
        if (in_array($key, [
            'terms',
            'policy_privacy',
            'policy_cookie',
            'policy_accessibility',
            'policy_term_condition',
            'policy_disclaimer',
        ], true)) {
            return self::GROUP_LEGAL;
        }
        if (in_array($key, ['policy_shipping', 'policy_refund'], true)) {
            return self::GROUP_FULFILLMENT;
        }

        return self::GROUP_POLICY;
    }

    /**
     * @return list<string>
     */
    public static function allowedGroups(): array
    {
        return [
            self::GROUP_ABOUT,
            self::GROUP_LEGAL,
            self::GROUP_FULFILLMENT,
            self::GROUP_POLICY,
        ];
    }

    /**
     * 默认链接：关于我们 + 使用条件 + 全部 Theme policy 布局。
     *
     * @return list<array{key:string,group:string,enabled:bool,label:string,url:string,open_in_new:bool}>
     */
    public static function defaultLinks(?string $layoutsDir = null): array
    {
        $links = [
            [
                'key' => 'about',
                'group' => self::GROUP_ABOUT,
                'enabled' => true,
                'label' => '关于我们',
                'url' => '/about',
                'open_in_new' => false,
            ],
            [
                'key' => 'terms',
                'group' => self::GROUP_LEGAL,
                'enabled' => true,
                'label' => '使用条件',
                'url' => '/terms',
                'open_in_new' => false,
            ],
        ];
        foreach (self::discoverPolicyOptions($layoutsDir) as $option => $title) {
            $key = 'policy_' . str_replace('-', '_', $option);
            $links[] = [
                'key' => $key,
                'group' => self::defaultGroupForKey($key),
                'enabled' => true,
                'label' => $title,
                'url' => '/policy/' . $option,
                'open_in_new' => false,
            ];
        }

        return $links;
    }

    /**
     * 顶栏直出链接（关于我们），不进下拉。
     *
     * @param list<array{key?:string,group?:string,label:string,url:string,open_in_new?:bool}> $links
     * @return list<array{key:string,label:string,url:string,open_in_new:bool}>
     */
    public static function inlineLinks(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $group = self::normalizeGroupKey(
                (string)($link['group'] ?? ''),
                (string)($link['key'] ?? '')
            );
            if ($group !== self::GROUP_ABOUT) {
                continue;
            }
            $label = (string)($link['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $out[] = [
                'key' => (string)($link['key'] ?? ''),
                'label' => $label,
                'url' => (string)($link['url'] ?? '#'),
                'open_in_new' => (bool)($link['open_in_new'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * 下拉内政策细分：法律与隐私 / 配送与售后 / 其他政策（不含关于我们）。
     *
     * @param list<array{key?:string,group?:string,label:string,url:string,open_in_new?:bool}> $links
     * @return list<array{key:string,title:string,items:list<array{key:string,label:string,url:string,open_in_new:bool}>}>
     */
    public static function menuGroups(array $links): array
    {
        $buckets = [
            self::GROUP_LEGAL => [],
            self::GROUP_FULFILLMENT => [],
            self::GROUP_POLICY => [],
        ];
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $key = (string)($link['key'] ?? '');
            $group = self::normalizeGroupKey((string)($link['group'] ?? ''), $key);
            if ($group === self::GROUP_ABOUT) {
                continue;
            }
            $item = [
                'key' => $key,
                'label' => (string)($link['label'] ?? ''),
                'url' => (string)($link['url'] ?? '#'),
                'open_in_new' => (bool)($link['open_in_new'] ?? false),
            ];
            if ($item['label'] === '') {
                continue;
            }
            if (!isset($buckets[$group])) {
                $group = self::GROUP_POLICY;
            }
            $buckets[$group][] = $item;
        }

        $titles = self::groupTitles();
        $out = [];
        foreach ([self::GROUP_LEGAL, self::GROUP_FULFILLMENT, self::GROUP_POLICY] as $groupKey) {
            if ($buckets[$groupKey] === []) {
                continue;
            }
            $out[] = [
                'key' => $groupKey,
                'title' => $titles[$groupKey] ?? $groupKey,
                'items' => $buckets[$groupKey],
            ];
        }

        return $out;
    }

    /**
     * 归一化为可渲染链接（跳过 enabled=false；空配置回退默认）。
     *
     * @return list<array{key:string,group:string,label:string,url:string,open_in_new:bool}>
     */
    public static function normalizeRenderableLinks(mixed $raw, ?string $layoutsDir = null): array
    {
        $list = self::normalizeLinks($raw, $layoutsDir);
        $out = [];
        foreach ($list as $row) {
            if (!(bool)($row['enabled'] ?? true)) {
                continue;
            }
            $out[] = [
                'key' => (string)$row['key'],
                'group' => (string)($row['group'] ?? self::GROUP_POLICY),
                'label' => (string)$row['label'],
                'url' => (string)$row['url'],
                'open_in_new' => (bool)$row['open_in_new'],
            ];
        }

        return $out;
    }

    /**
     * 归一化编辑器配置（保留 enabled=false；空配置回退默认全开）。
     *
     * @return list<array{key:string,group:string,enabled:bool,label:string,url:string,open_in_new:bool}>
     */
    public static function normalizeLinks(mixed $raw, ?string $layoutsDir = null): array
    {
        $list = self::decodeList($raw);
        if ($list === []) {
            return self::defaultLinks($layoutsDir);
        }

        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? $row['text'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            if ($label === '' && $url === '') {
                continue;
            }
            $key = trim((string)($row['key'] ?? ''));
            if ($key === '') {
                $key = 'custom_' . substr(sha1($label . $url . count($out)), 0, 8);
            }
            $group = self::normalizeGroupKey((string)($row['group'] ?? ''), $key);
            $enabled = $row['enabled'] ?? true;
            if (is_string($enabled)) {
                $enabled = !in_array(strtolower($enabled), ['0', 'false', 'no', 'off'], true);
            }
            $open = $row['open_in_new'] ?? false;
            if (is_string($open)) {
                $open = in_array(strtolower($open), ['1', 'true', 'yes', 'on'], true);
            }
            $out[] = [
                'key' => $key,
                'group' => $group,
                'enabled' => (bool)$enabled,
                'label' => $label !== '' ? $label : $url,
                'url' => $url !== '' ? $url : '#',
                'open_in_new' => (bool)$open,
            ];
        }

        return $out;
    }

    public static function normalizeGroupKey(string $group, string $key = ''): string
    {
        $group = strtolower(trim($group));
        // 兼容旧配置：policy 桶按键再细分
        if ($group === 'policy' && $key !== '') {
            $inferred = self::defaultGroupForKey($key);
            if ($inferred !== self::GROUP_POLICY) {
                return $inferred;
            }
        }
        if ($group !== '' && in_array($group, self::allowedGroups(), true)) {
            return $group;
        }

        return self::defaultGroupForKey($key);
    }

    /**
     * @return list<mixed>
     */
    private static function decodeList(mixed $raw): array
    {
        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') {
                return [];
            }
            $decoded = json_decode($trim, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        if ($raw !== [] && array_is_list($raw)) {
            return $raw;
        }

        return [];
    }
}
