<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteHtmlBlocksBuildService
{
    public function __construct(
        private readonly ?AiSitePageBlueprintService $pageBlueprintService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return list<array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}>
     */
    public function buildPlaceholderBlocksForPageType(string $pageType, array $websiteProfile, array $scope = []): array
    {
        $pageBlueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
        $blueprint = $pageBlueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile);
        $blocks = [
            $this->buildHeaderBlock($pageType, $websiteProfile, $scope),
        ];

        foreach ($blueprint['sections'] as $section) {
            $blockId = \str_replace(['content/', '/'], ['', '-'], (string)$section['code']);
            $template = (string)($section['template'] ?? 'hero');
            $config = \is_array($section['config'] ?? null) ? $section['config'] : [];
            $blocks[] = $this->buildBlockRecord(
                $blockId !== '' ? $blockId : ('block-' . \bin2hex(\random_bytes(4))),
                $template,
                $config
            );
        }

        $blocks[] = $this->buildFooterBlock($pageType, $websiteProfile, $scope);

        return $blocks;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $configPatch
     * @return array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}
     */
    public function rebuildBlock(array $block, array $websiteProfile, array $scope = [], array $configPatch = []): array
    {
        $blockId = \trim((string)($block['block_id'] ?? ''));
        $type = \trim((string)($block['type'] ?? 'hero'));
        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        $config = $this->normalizeBlockConfig($type, \array_replace_recursive($config, $configPatch), $websiteProfile, $scope);

        return [
            'block_id' => $blockId !== '' ? $blockId : ('block-' . \bin2hex(\random_bytes(4))),
            'type' => $type !== '' ? $type : 'hero',
            'html' => $this->renderBlockHtml($type !== '' ? $type : 'hero', $config),
            'config' => $config,
            'field_schema' => $this->buildFieldSchema($type !== '' ? $type : 'hero'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}
     */
    private function buildBlockRecord(string $blockId, string $template, array $config, array $websiteProfile = [], array $scope = []): array
    {
        $normalizedConfig = $this->normalizeBlockConfig($template, $config, $websiteProfile, $scope);

        return [
            'block_id' => $blockId,
            'type' => $template,
            'html' => $this->renderBlockHtml($template, $normalizedConfig),
            'config' => $normalizedConfig,
            'field_schema' => $this->buildFieldSchema($template),
        ];
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}
     */
    private function buildHeaderBlock(string $pageType, array $websiteProfile, array $scope): array
    {
        return $this->buildBlockRecord(
            \str_replace('_', '-', $pageType) . '-site-header',
            'site_header',
            [
                'site_title' => (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? 'AI Site'),
                'site_tagline' => (string)($websiteProfile['site_tagline'] ?? $scope['site_tagline'] ?? ''),
                'current_page_label' => (string)(Page::getPageTypes()[$pageType] ?? $pageType),
                'nav_items' => $this->buildNavItems($scope),
            ],
            $websiteProfile,
            $scope
        );
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}
     */
    private function buildFooterBlock(string $pageType, array $websiteProfile, array $scope): array
    {
        return $this->buildBlockRecord(
            \str_replace('_', '-', $pageType) . '-site-footer',
            'site_footer',
            [
                'site_title' => (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? 'AI Site'),
                'brief_description' => (string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? ''),
                'domain' => (string)($websiteProfile['target_domain'] ?? $scope['target_domain'] ?? ''),
                'nav_items' => $this->buildNavItems($scope),
            ],
            $websiteProfile,
            $scope
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderBlockHtml(string $template, array $config): string
    {
        return match ($template) {
            'site_header' => $this->renderHeaderBlock($config),
            'site_footer' => $this->renderFooterBlock($config),
            'cards' => $this->renderCardsBlock($config),
            'checklist' => $this->renderChecklistBlock($config),
            'cta' => $this->renderCtaBlock($config),
            default => $this->renderHeroBlock($config),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFieldSchema(string $template): array
    {
        return match ($template) {
            'site_header' => [
                'identity' => [
                    'label' => '页头信息',
                    'fields' => [
                        'site_title' => ['type' => 'text', 'label' => '站点名称'],
                        'site_tagline' => ['type' => 'text', 'label' => '站点副标题'],
                        'current_page_label' => ['type' => 'text', 'label' => '当前页标签'],
                        'nav_items' => ['type' => 'textarea', 'label' => '导航项', 'format' => 'nav-items'],
                    ],
                ],
            ],
            'site_footer' => [
                'identity' => [
                    'label' => '页脚信息',
                    'fields' => [
                        'site_title' => ['type' => 'text', 'label' => '站点名称'],
                        'brief_description' => ['type' => 'textarea', 'label' => '页脚简介'],
                        'domain' => ['type' => 'text', 'label' => '展示域名'],
                        'nav_items' => ['type' => 'textarea', 'label' => '底部链接', 'format' => 'nav-items'],
                    ],
                ],
            ],
            'cards' => [
                'content' => [
                    'label' => '内容信息',
                    'fields' => [
                        'section_title' => ['type' => 'text', 'label' => '分组标题'],
                        'section_intro' => ['type' => 'textarea', 'label' => '分组说明'],
                        'items' => ['type' => 'textarea', 'label' => '卡片列表', 'format' => 'card-items'],
                    ],
                ],
            ],
            'checklist' => [
                'content' => [
                    'label' => '内容信息',
                    'fields' => [
                        'section_title' => ['type' => 'text', 'label' => '分组标题'],
                        'section_intro' => ['type' => 'textarea', 'label' => '分组说明'],
                        'points' => ['type' => 'textarea', 'label' => '条目列表', 'format' => 'lines'],
                    ],
                ],
            ],
            'cta' => [
                'content' => [
                    'label' => '内容信息',
                    'fields' => [
                        'section_title' => ['type' => 'text', 'label' => '标题'],
                        'section_text' => ['type' => 'textarea', 'label' => '说明文本'],
                        'button_label' => ['type' => 'text', 'label' => '按钮文案'],
                        'assist_text' => ['type' => 'text', 'label' => '辅助文案'],
                    ],
                ],
            ],
            default => [
                'content' => [
                    'label' => '内容信息',
                    'fields' => [
                        'eyebrow' => ['type' => 'text', 'label' => '上方标签'],
                        'headline' => ['type' => 'text', 'label' => '主标题'],
                        'description' => ['type' => 'textarea', 'label' => '描述文本'],
                        'chips' => ['type' => 'textarea', 'label' => '标签列表', 'format' => 'lines'],
                        'primary_cta' => ['type' => 'text', 'label' => '主按钮'],
                        'secondary_note' => ['type' => 'text', 'label' => '辅助说明'],
                    ],
                ],
            ],
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderHeroBlock(array $config): string
    {
        $eyebrow = $this->escape($config['eyebrow'] ?? '');
        $headline = $this->escape($config['headline'] ?? '');
        $description = \nl2br($this->escape($config['description'] ?? ''));
        $chips = $this->renderChipList($config['chips'] ?? []);
        $cta = $this->escape($config['primary_cta'] ?? '');

        return '<section class="ai-block ai-block-hero" style="padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;">'
            . ($eyebrow !== '' ? '<span style="font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;">' . $eyebrow . '</span>' : '')
            . '<h1 style="margin:0;font-size:40px;line-height:1.08;color:#0f172a;">' . $headline . '</h1>'
            . ($description !== '' ? '<p style="margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;">' . $description . '</p>' : '')
            . $chips
            . ($cta !== '' ? '<div><span style="display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;">' . $cta . '</span></div>' : '')
            . '</section>';
    }

    /**
     * @param list<array{label:string,href:string,active:bool}> $navItems
     */
    private function renderHeaderBlock(array $config): string
    {
        $siteTitle = $this->escape($config['site_title'] ?? 'AI Site');
        $siteTagline = $this->escape($config['site_tagline'] ?? '');
        $currentPageLabel = $this->escape($config['current_page_label'] ?? '');
        $navItems = \is_array($config['nav_items'] ?? null) ? $config['nav_items'] : [];
        $navHtml = [];
        foreach ($navItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $navHtml[] = '<a href="' . $this->escape($item['href'] ?? '#') . '" style="color:' . (!empty($item['active']) ? '#0f172a' : '#475569') . ';font-size:14px;font-weight:' . (!empty($item['active']) ? '700' : '600') . ';text-decoration:none;">'
                . $this->escape($item['label'] ?? '')
                . '</a>';
        }

        return '<header class="ai-block ai-block-site-header" style="padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;">'
            . '<div style="display:grid;gap:4px;min-width:0;">'
            . '<strong style="font-size:18px;line-height:1.2;color:#0f172a;">' . $siteTitle . '</strong>'
            . ($siteTagline !== '' ? '<span style="font-size:13px;line-height:1.6;color:#64748b;">' . $siteTagline . '</span>' : '')
            . '</div>'
            . '<div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;">'
            . \implode('', $navHtml)
            . '<span style="display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;">' . $currentPageLabel . '</span>'
            . '</div></header>';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderCardsBlock(array $config): string
    {
        $title = $this->escape($config['section_title'] ?? '');
        $intro = \nl2br($this->escape($config['section_intro'] ?? ''));
        $items = [];
        foreach (($config['items'] ?? []) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $items[] = '<article style="display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;">'
                . (!empty($item['eyebrow']) ? '<span style="font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;">' . $this->escape($item['eyebrow']) . '</span>' : '')
                . '<h3 style="margin:0;font-size:20px;line-height:1.2;color:#0f172a;">' . $this->escape($item['title'] ?? '') . '</h3>'
                . (!empty($item['description']) ? '<p style="margin:0;font-size:15px;line-height:1.7;color:#475569;">' . \nl2br($this->escape($item['description'])) . '</p>' : '')
                . '</article>';
        }

        return '<section class="ai-block ai-block-cards" style="padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;">'
            . ($title !== '' ? '<h2 style="margin:0;font-size:28px;line-height:1.2;color:#0f172a;">' . $title . '</h2>' : '')
            . ($intro !== '' ? '<p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;">' . $intro . '</p>' : '')
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;">' . \implode('', $items) . '</div>'
            . '</section>';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderChecklistBlock(array $config): string
    {
        $title = $this->escape($config['section_title'] ?? '');
        $intro = \nl2br($this->escape($config['section_intro'] ?? ''));
        $points = [];
        foreach (($config['points'] ?? []) as $index => $point) {
            if (!\is_scalar($point) || \trim((string)$point) === '') {
                continue;
            }
            $points[] = '<div style="display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;">'
                . '<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;">' . ((int)$index + 1) . '</span>'
                . '<p style="margin:0;font-size:15px;line-height:1.7;color:#334155;">' . \nl2br($this->escape((string)$point)) . '</p>'
                . '</div>';
        }

        return '<section class="ai-block ai-block-checklist" style="padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;">'
            . ($title !== '' ? '<h2 style="margin:0;font-size:26px;line-height:1.25;color:#0f172a;">' . $title . '</h2>' : '')
            . ($intro !== '' ? '<p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;">' . $intro . '</p>' : '')
            . '<div style="display:grid;gap:12px;">' . \implode('', $points) . '</div>'
            . '</section>';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderCtaBlock(array $config): string
    {
        $title = $this->escape($config['section_title'] ?? '');
        $text = \nl2br($this->escape($config['section_text'] ?? ''));
        $button = $this->escape($config['button_label'] ?? '');
        $assist = $this->escape($config['assist_text'] ?? '');

        return '<section class="ai-block ai-block-cta" style="padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);">'
            . '<div style="padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;">'
            . ($title !== '' ? '<h2 style="margin:0;font-size:28px;line-height:1.2;color:#fff;">' . $title . '</h2>' : '')
            . ($text !== '' ? '<p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);">' . $text . '</p>' : '')
            . '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">'
            . ($button !== '' ? '<span style="display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;">' . $button . '</span>' : '')
            . ($assist !== '' ? '<span style="font-size:13px;color:rgba(255,255,255,.72);">' . $assist . '</span>' : '')
            . '</div></div></section>';
    }

    /**
     * @param list<array{label:string,href:string,active:bool}> $navItems
     */
    private function renderFooterBlock(array $config): string
    {
        $siteTitle = $this->escape($config['site_title'] ?? 'AI Site');
        $brief = $this->escape($config['brief_description'] ?? '');
        $domain = $this->escape($config['domain'] ?? '');
        $navItems = \is_array($config['nav_items'] ?? null) ? $config['nav_items'] : [];
        $linkHtml = [];
        foreach ($navItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $linkHtml[] = '<a href="' . $this->escape($item['href'] ?? '#') . '" style="color:#cbd5e1;font-size:13px;text-decoration:none;">'
                . $this->escape($item['label'] ?? '')
                . '</a>';
        }

        return '<footer class="ai-block ai-block-site-footer" style="padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:16px;">'
            . '<div style="display:grid;gap:8px;">'
            . '<strong style="font-size:18px;line-height:1.2;color:#fff;">' . $siteTitle . '</strong>'
            . ($brief !== '' ? '<p style="margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;">' . $brief . '</p>' : '')
            . '</div>'
            . '<div style="display:flex;flex-wrap:wrap;gap:14px 18px;">' . \implode('', $linkHtml) . '</div>'
            . '<div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;">'
            . '<span>© 2026 ' . $siteTitle . '</span>'
            . ($domain !== '' ? '<span>' . $domain . '</span>' : '<span>Generated for the current customer brief</span>')
            . '</div></footer>';
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{label:string,href:string,active:bool}>
     */
    private function buildNavItems(array $scope): array
    {
        $pageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT];
        $labels = Page::getPageTypes();
        $items = [];

        foreach ($pageTypes as $pageType) {
            if (!\is_string($pageType) || $pageType === '') {
                continue;
            }

            $href = $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType);
            $items[] = [
                'label' => (string)($labels[$pageType] ?? $pageType),
                'href' => $href,
                'active' => $pageType === Page::TYPE_HOME,
            ];
            if (\count($items) >= 5) {
                break;
            }
        }

        if ($items === []) {
            $items[] = ['label' => '首页', 'href' => '/', 'active' => true];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function normalizeBlockConfig(string $template, array $config, array $websiteProfile = [], array $scope = []): array
    {
        return match ($template) {
            'site_header' => [
                'site_title' => (string)($config['site_title'] ?? $websiteProfile['site_title'] ?? $scope['site_title'] ?? 'AI Site'),
                'site_tagline' => (string)($config['site_tagline'] ?? $websiteProfile['site_tagline'] ?? $scope['site_tagline'] ?? ''),
                'current_page_label' => (string)($config['current_page_label'] ?? ''),
                'nav_items' => $this->normalizeNavItems($config['nav_items'] ?? $this->buildNavItems($scope)),
            ],
            'site_footer' => [
                'site_title' => (string)($config['site_title'] ?? $websiteProfile['site_title'] ?? $scope['site_title'] ?? 'AI Site'),
                'brief_description' => (string)($config['brief_description'] ?? $websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? ''),
                'domain' => (string)($config['domain'] ?? $websiteProfile['target_domain'] ?? $scope['target_domain'] ?? ''),
                'nav_items' => $this->normalizeNavItems($config['nav_items'] ?? $this->buildNavItems($scope)),
            ],
            'cards' => [
                'section_title' => (string)($config['section_title'] ?? ''),
                'section_intro' => (string)($config['section_intro'] ?? ''),
                'items' => $this->normalizeCardItems($config['items'] ?? []),
            ],
            'checklist' => [
                'section_title' => (string)($config['section_title'] ?? ''),
                'section_intro' => (string)($config['section_intro'] ?? ''),
                'points' => $this->normalizeStringList($config['points'] ?? []),
            ],
            'cta' => [
                'section_title' => (string)($config['section_title'] ?? ''),
                'section_text' => (string)($config['section_text'] ?? ''),
                'button_label' => (string)($config['button_label'] ?? ''),
                'assist_text' => (string)($config['assist_text'] ?? ''),
            ],
            default => [
                'eyebrow' => (string)($config['eyebrow'] ?? ''),
                'headline' => (string)($config['headline'] ?? ''),
                'description' => (string)($config['description'] ?? ''),
                'chips' => $this->normalizeStringList($config['chips'] ?? []),
                'primary_cta' => (string)($config['primary_cta'] ?? ''),
                'secondary_note' => (string)($config['secondary_note'] ?? ''),
            ],
        };
    }

    /**
     * @param mixed $items
     * @return list<array{label:string,href:string,active:bool}>
     */
    private function normalizeNavItems(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $label = \trim((string)($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $normalized[] = [
                'label' => $label,
                'href' => \trim((string)($item['href'] ?? '#')),
                'active' => !empty($item['active']),
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $items
     * @return list<array{eyebrow:string,title:string,description:string}>
     */
    private function normalizeCardItems(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $title = \trim((string)($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $normalized[] = [
                'eyebrow' => \trim((string)($item['eyebrow'] ?? '')),
                'title' => $title,
                'description' => \trim((string)($item['description'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $items
     * @return list<string>
     */
    private function normalizeStringList(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $text = \trim((string)$item);
            if ($text === '') {
                continue;
            }
            $normalized[] = $text;
        }

        return $normalized;
    }

    /**
     * @param mixed $chips
     */
    private function renderChipList(mixed $chips): string
    {
        if (!\is_array($chips) || $chips === []) {
            return '';
        }

        $items = [];
        foreach ($chips as $chip) {
            if (!\is_scalar($chip) || \trim((string)$chip) === '') {
                continue;
            }
            $items[] = '<span style="display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;">' . $this->escape((string)$chip) . '</span>';
        }

        if ($items === []) {
            return '';
        }

        return '<div style="display:flex;flex-wrap:wrap;gap:10px;">' . \implode('', $items) . '</div>';
    }

    private function escape(mixed $value): string
    {
        return \htmlspecialchars(\trim((string)$value), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
