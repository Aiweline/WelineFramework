<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Helper\FooterDefaultLinksHelper;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;

/**
 * 从主题页脚社交配置收集 Organization.sameAs（仅 http/https 真实链接）。
 */
class ThemeSocialSameAsSeoContextService
{
    public function __construct(
        private readonly ThemeLayoutService $layoutService
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolve(array $context): array
    {
        $existing = $context['organization']['sameAs'] ?? null;
        if (is_array($existing) && $this->normalizeUrls($existing) !== []) {
            return [];
        }

        $theme = ThemeData::getCurrentTheme();
        if ($theme !== null && (int) $theme->getId() > 0) {
            $themeId = (int) $theme->getId();
            $pageTypes = [];
            $current = trim((string) ($context['page_type'] ?? ''));
            if ($current === 'home') {
                $current = ThemeLayout::PAGE_TYPE_HOME;
            }
            foreach ([$current, ThemeLayout::PAGE_TYPE_HOME, ThemeLayout::PAGE_TYPE_DEFAULT] as $pageType) {
                $pageType = trim((string) $pageType);
                if ($pageType === '' || isset($pageTypes[$pageType])) {
                    continue;
                }
                $pageTypes[$pageType] = true;
                try {
                    $layout = $this->layoutService->getPublishedLayout($themeId, $pageType);
                } catch (\Throwable) {
                    continue;
                }
                $urls = $this->extractSocialUrls(is_array($layout) ? $layout : []);
                if ($urls !== []) {
                    return [
                        'organization' => [
                            'sameAs' => $urls,
                        ],
                    ];
                }
            }
        }

        $fallback = FooterDefaultLinksHelper::defaultSameAsUrls();
        if ($fallback === []) {
            return [];
        }

        return [
            'organization' => [
                'sameAs' => $fallback,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $layout
     * @return list<string>
     */
    private function extractSocialUrls(array $layout): array
    {
        $urls = [];
        foreach ($layout as $areaData) {
            if (!is_array($areaData)) {
                continue;
            }
            foreach ($areaData['widgets'] ?? [] as $widget) {
                $this->appendWidgetSocialUrls($urls, $widget);
            }
            foreach ($areaData['slots'] ?? [] as $slotWidgets) {
                if (!is_array($slotWidgets)) {
                    continue;
                }
                foreach ($slotWidgets as $widget) {
                    $this->appendWidgetSocialUrls($urls, $widget);
                }
            }
        }

        return $this->normalizeUrls($urls);
    }

    /**
     * @param list<string> $urls
     * @param mixed $widget
     */
    private function appendWidgetSocialUrls(array &$urls, mixed $widget): void
    {
        if (!is_array($widget)) {
            return;
        }

        $code = strtolower(trim((string) ($widget['widget_code'] ?? $widget['code'] ?? '')));
        $type = strtolower(trim((string) ($widget['widget_type'] ?? $widget['type'] ?? '')));
        $config = $widget['config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($config)) {
            return;
        }

        if ($code === 'footer-container' || $type === 'footer-container') {
            // Respect merchant social_items as-is (incl. intentional []). Do not
            // substitute Hanfu brand defaults here — that caused DaoCharms sameAs leak.
            foreach (FooterDefaultLinksHelper::actionableSocialItems($config['social_items'] ?? []) as $item) {
                $urls[] = (string) ($item['url'] ?? '');
            }
            return;
        }

        if ($code === 'footer-social' || $type === 'social' || $code === 'sidebar-social') {
            $custom = $config['custom_links'] ?? null;
            if (is_string($custom) && trim($custom) !== '') {
                $decoded = json_decode($custom, true);
                $custom = is_array($decoded) ? $decoded : [];
            }
            if (is_array($custom)) {
                foreach ($custom as $link) {
                    if (!is_array($link)) {
                        continue;
                    }
                    $urls[] = (string) ($link['url'] ?? '');
                }
            }
            foreach (['facebook', 'twitter', 'instagram', 'youtube', 'pinterest', 'tiktok', 'linkedin', 'weibo'] as $platform) {
                if (!empty($config[$platform])) {
                    $urls[] = (string) $config[$platform];
                }
            }
        }
    }

    /**
     * @param mixed $urls
     * @return list<string>
     */
    private function normalizeUrls(mixed $urls): array
    {
        if (!is_array($urls)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($urls as $url) {
            if (!is_string($url) && !is_numeric($url)) {
                continue;
            }
            $url = trim((string) $url);
            if ($url === '' || str_starts_with($url, '#')) {
                continue;
            }
            if (str_starts_with($url, '//')) {
                $url = 'https:' . $url;
            }
            $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
            if (!in_array($scheme, ['http', 'https'], true)) {
                continue;
            }
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $url;
        }

        return $out;
    }
}
