<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;

class AiSiteHtmlBlocksBuildService
{
    public function __construct(
        private readonly ?AiSitePageBlueprintService $pageBlueprintService = null,
        private readonly ?AiSitePageComponentGenerationService $pageComponentGenerationService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return list<array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}>
     */
    public function buildPlaceholderBlocksForPageType(string $pageType, array $websiteProfile, array $scope = []): array
    {
        $generationService = $this->pageComponentGenerationService ?? ObjectManager::getInstance(AiSitePageComponentGenerationService::class);
        $pageSections = $generationService->generatePageSections($pageType, $websiteProfile, $scope);

        $blocks = [
            $this->buildHeaderBlock($pageType, $websiteProfile, $scope),
        ];

        foreach (($pageSections['sections'] ?? []) as $section) {
            if (!\is_array($section)) {
                continue;
            }

            $blockId = \str_replace(['content/', '/'], ['', '-'], (string)($section['code'] ?? ''));
            $blockId = $blockId !== '' ? $blockId : ('block-' . \bin2hex(\random_bytes(4)));
            $blocks[] = $this->buildGeneratedBlockRecord(
                $blockId,
                'ai_generated_section',
                (string)($section['html'] ?? ''),
                \array_replace(
                    ['region' => 'content', 'html_content' => (string)($section['html'] ?? '')],
                    \is_array($section['default_config'] ?? null) ? $section['default_config'] : [],
                    ['component_label' => (string)($section['name'] ?? $blockId)]
                )
            );
        }

        $blocks[] = $this->buildFooterBlock($pageType, $websiteProfile, $scope);

        return $blocks !== [] ? $blocks : [
            $this->buildHeaderBlock($pageType, $websiteProfile, $scope),
            $this->buildFooterBlock($pageType, $websiteProfile, $scope),
        ];
    }

    /**
     * 当 AI 生成不可用时，回退到可编辑的静态占位块，确保工作台仍可打开。
     *
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return list<array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}>
     */
    public function buildStaticPlaceholderBlocksForPageType(string $pageType, array $websiteProfile, array $scope = []): array
    {
        $blocks = [
            $this->buildHeaderBlock($pageType, $websiteProfile, $scope),
        ];

        foreach ($this->buildStaticSectionBlocks($pageType, $websiteProfile, $scope) as $block) {
            $blocks[] = $block;
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
        if (\str_starts_with($type, 'ai_generated_')) {
            $config = \array_replace_recursive($config, $configPatch);

            return [
                'block_id' => $blockId !== '' ? $blockId : ('block-' . \bin2hex(\random_bytes(4))),
                'type' => $type !== '' ? $type : 'ai_generated_section',
                'html' => \trim((string)($config['html_content'] ?? $block['html'] ?? '')),
                'config' => $config,
                'field_schema' => $this->buildFieldSchema($type !== '' ? $type : 'ai_generated_section'),
            ];
        }
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
     * @param array<string, mixed> $config
     * @return array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}
     */
    private function buildGeneratedBlockRecord(string $blockId, string $type, string $html, array $config): array
    {
        return [
            'block_id' => $blockId,
            'type' => $type,
            'html' => $html,
            'config' => $config,
            'field_schema' => $this->buildFieldSchema($type),
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
                'site_title' => (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
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
        $pageBlueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
        $footerGroups = $this->buildFooterLinkGroups($scope);
        return $this->buildBlockRecord(
            \str_replace('_', '-', $pageType) . '-site-footer',
            'site_footer',
            [
                'site_title' => (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
                'brief_description' => $pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $scope),
                'domain' => (string)($websiteProfile['target_domain'] ?? $scope['target_domain'] ?? ''),
                'links.column1_title' => (string)($footerGroups['column1_title'] ?? 'Featured'),
                'links.column1_items' => $footerGroups['column1_items'] ?? [],
                'links.column2_title' => (string)($footerGroups['column2_title'] ?? 'Policies'),
                'links.column2_items' => $footerGroups['column2_items'] ?? [],
                'links.column3_title' => (string)($footerGroups['column3_title'] ?? 'All Pages'),
                'links.column3_items' => $footerGroups['column3_items'] ?? [],
            ],
            $websiteProfile,
            $scope
        );
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return list<array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}>
     */
    private function buildStaticSectionBlocks(string $pageType, array $websiteProfile, array $scope): array
    {
        $blueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
        $blueprint = $blueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile);
        $sections = \is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : [];
        $blocks = [];

        foreach ($sections as $index => $section) {
            if (!\is_array($section)) {
                continue;
            }

            $code = \trim((string)($section['code'] ?? ''));
            $blockId = \str_replace(['content/', '/'], ['', '-'], $code);
            $blockId = $blockId !== '' ? $blockId : ('block-' . \bin2hex(\random_bytes(4)));
            $template = $this->resolveStaticSectionTemplate($sections, $index);
            $blocks[] = $this->buildBlockRecord(
                $blockId,
                $template,
                $this->buildStaticSectionConfig($template, $pageType, $section, $websiteProfile, $scope),
                $websiteProfile,
                $scope
            );
        }

        if ($blocks !== []) {
            return $blocks;
        }

        return [
            $this->buildBlockRecord(
                \str_replace('_', '-', $pageType) . '-overview',
                'hero',
                $this->buildStaticSectionConfig('hero', $pageType, [], $websiteProfile, $scope),
                $websiteProfile,
                $scope
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private function resolveStaticSectionTemplate(array $sections, int $index): string
    {
        $count = \count($sections);
        if ($index === 0) {
            return 'hero';
        }
        if ($count > 1 && $index === $count - 1) {
            return 'cta';
        }

        return $index % 2 === 0 ? 'checklist' : 'cards';
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildStaticSectionConfig(
        string $template,
        string $pageType,
        array $section,
        array $websiteProfile,
        array $scope
    ): array {
        $pageLabel = (string)(Page::getPageTypes()[$pageType] ?? $pageType);
        $siteTitle = (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? '');
        $sectionName = \trim((string)($section['name'] ?? $section['title'] ?? $pageLabel));
        $summary = $this->summarizeStaticSectionText($section, $websiteProfile, $scope);

        return match ($template) {
            'cards' => [
                'section_title' => $sectionName,
                'section_intro' => $summary,
                'items' => [
                    [
                        'eyebrow' => (string)__('亮点 01'),
                        'title' => $siteTitle !== '' ? $siteTitle : $sectionName,
                        'description' => $summary,
                    ],
                    [
                        'eyebrow' => (string)__('亮点 02'),
                        'title' => (string)__('当前已回退为静态内容'),
                        'description' => (string)__('AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。'),
                    ],
                    [
                        'eyebrow' => (string)__('亮点 03'),
                        'title' => $pageLabel,
                        'description' => (string)__('此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。'),
                    ],
                ],
            ],
            'checklist' => [
                'section_title' => $sectionName,
                'section_intro' => $summary,
                'points' => [
                    $summary !== '' ? $summary : (string)__('根据当前站点简介生成本节内容结构。'),
                    (string)__('AI 服务暂不可用，已自动切换到静态占位内容。'),
                    (string)__('你可以先继续编辑区块，稍后再重新触发 AI 生成。'),
                ],
            ],
            'cta' => [
                'section_title' => $sectionName !== '' ? $sectionName : (string)__('准备好继续完善网站了吗？'),
                'section_text' => $summary !== '' ? $summary : (string)__('当前区域先使用静态占位内容承接工作流，后续可重新生成。'),
                'button_label' => (string)__('继续编辑'),
                'assist_text' => (string)($websiteProfile['target_domain'] ?? $scope['target_domain'] ?? __('稍后可重新生成 AI 内容')),
            ],
            default => [
                'eyebrow' => $pageLabel,
                'headline' => $sectionName !== '' ? $sectionName : ($siteTitle !== '' ? $siteTitle : $pageLabel),
                'description' => $summary !== '' ? $summary : (string)__('当前页面先使用静态占位内容保证工作台可访问。'),
                'chips' => [
                    $siteTitle !== '' ? $siteTitle : $pageLabel,
                    (string)__('静态占位'),
                    (string)__('可继续编辑'),
                ],
                'primary_cta' => (string)__('继续完善'),
                'secondary_note' => (string)__('AI 可用后可重新生成该区块'),
            ],
        };
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     */
    private function summarizeStaticSectionText(array $section, array $websiteProfile, array $scope): string
    {
        $pageBlueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
        $sectionConfig = \is_array($section['config'] ?? null) ? $section['config'] : [];
        $candidates = [
            $sectionConfig['description'] ?? null,
            $sectionConfig['section_intro'] ?? null,
            $sectionConfig['section_text'] ?? null,
            $section['description'] ?? null,
            $section['prompt_instruction'] ?? null,
            $section['prompt'] ?? null,
            $pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $scope),
        ];

        foreach ($candidates as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }

            $text = \trim(\preg_replace('/\s+/u', ' ', (string)$candidate) ?? '');
            if ($text !== '') {
                return \mb_substr($text, 0, 180);
            }
        }

        return '';
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
            'ai_generated_header',
            'ai_generated_section',
            'ai_generated_footer' => [
                'content' => [
                    'label' => 'AI Generated Result',
                    'fields' => [
                        'html_content' => ['type' => 'textarea', 'label' => 'HTML Content'],
                    ],
                ],
            ],
            'site_header' => [
                'identity' => [
                    'label' => 'Header Fields',
                    'fields' => [
                        'site_title' => ['type' => 'text', 'label' => 'Site Title'],
                        'site_tagline' => ['type' => 'text', 'label' => 'Site Tagline'],
                        'current_page_label' => ['type' => 'text', 'label' => 'Current Page'],
                        'nav_items' => ['type' => 'textarea', 'label' => 'Header Links', 'format' => 'nav-items'],
                    ],
                ],
            ],
            'site_footer' => [
                'identity' => [
                    'label' => 'Footer Fields',
                    'fields' => [
                        'site_title' => ['type' => 'text', 'label' => 'Site Title'],
                        'brief_description' => ['type' => 'textarea', 'label' => 'Footer Summary'],
                        'domain' => ['type' => 'text', 'label' => 'Domain'],
                        'links.column1_title' => ['type' => 'text', 'label' => 'Group 1 Title'],
                        'links.column1_items' => ['type' => 'textarea', 'label' => 'Group 1 Links', 'format' => 'nav-items'],
                        'links.column2_title' => ['type' => 'text', 'label' => 'Group 2 Title'],
                        'links.column2_items' => ['type' => 'textarea', 'label' => 'Group 2 Links', 'format' => 'nav-items'],
                        'links.column3_title' => ['type' => 'text', 'label' => 'Group 3 Title'],
                        'links.column3_items' => ['type' => 'textarea', 'label' => 'Group 3 Links', 'format' => 'nav-items'],
                    ],
                ],
            ],
            'cards' => [
                'content' => [
                    'label' => 'Content Fields',
                    'fields' => [
                        'section_title' => ['type' => 'text', 'label' => 'Section Title'],
                        'section_intro' => ['type' => 'textarea', 'label' => 'Section Intro'],
                        'items' => ['type' => 'textarea', 'label' => 'Cards', 'format' => 'card-items'],
                    ],
                ],
            ],
            'checklist' => [
                'content' => [
                    'label' => 'Content Fields',
                    'fields' => [
                        'section_title' => ['type' => 'text', 'label' => 'Section Title'],
                        'section_intro' => ['type' => 'textarea', 'label' => 'Section Intro'],
                        'points' => ['type' => 'textarea', 'label' => 'Checklist Items', 'format' => 'lines'],
                    ],
                ],
            ],
            'cta' => [
                'content' => [
                    'label' => 'CTA Fields',
                    'fields' => [
                        'section_title' => ['type' => 'text', 'label' => 'Title'],
                        'section_text' => ['type' => 'textarea', 'label' => 'Text'],
                        'button_label' => ['type' => 'text', 'label' => 'Button Label'],
                        'assist_text' => ['type' => 'text', 'label' => 'Assist Text'],
                    ],
                ],
            ],
            default => [
                'content' => [
                    'label' => 'Content Fields',
                    'fields' => [
                        'eyebrow' => ['type' => 'text', 'label' => 'Eyebrow'],
                        'headline' => ['type' => 'text', 'label' => 'Headline'],
                        'description' => ['type' => 'textarea', 'label' => 'Description'],
                        'chips' => ['type' => 'textarea', 'label' => 'Chips', 'format' => 'lines'],
                        'primary_cta' => ['type' => 'text', 'label' => 'Primary CTA'],
                        'secondary_note' => ['type' => 'text', 'label' => 'Secondary Note'],
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
        $siteTitle = $this->escape($config['site_title'] ?? '');
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
        $siteTitle = $this->escape($config['site_title'] ?? '');
        $brief = $this->escape($config['brief_description'] ?? '');
        $domain = $this->escape($config['domain'] ?? '');
        $groups = [
            [
                'title' => (string)($config['links.column1_title'] ?? 'Featured'),
                'items' => \is_array($config['links.column1_items'] ?? null) ? $config['links.column1_items'] : [],
            ],
            [
                'title' => (string)($config['links.column2_title'] ?? 'Policies'),
                'items' => \is_array($config['links.column2_items'] ?? null) ? $config['links.column2_items'] : [],
            ],
            [
                'title' => (string)($config['links.column3_title'] ?? 'All Pages'),
                'items' => \is_array($config['links.column3_items'] ?? null) ? $config['links.column3_items'] : [],
            ],
        ];

        $groupHtml = [];
        foreach ($groups as $group) {
            $items = [];
            foreach (($group['items'] ?? []) as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $label = $this->escape($item['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $items[] = '<a href="' . $this->escape($item['href'] ?? '#') . '" style="color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;">'
                    . $label
                    . '</a>';
            }
            if ($items === []) {
                continue;
            }
            $groupHtml[] = '<div style="display:grid;gap:10px;min-width:180px;">'
                . '<strong style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;">' . $this->escape($group['title'] ?? '') . '</strong>'
                . '<div style="display:grid;gap:8px;">' . \implode('', $items) . '</div>'
                . '</div>';
        }

        return '<footer class="ai-block ai-block-site-footer" style="padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;">'
            . '<div style="display:grid;gap:8px;">'
            . '<strong style="font-size:18px;line-height:1.2;color:#fff;">' . $siteTitle . '</strong>'
            . ($brief !== '' ? '<p style="margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;">' . $brief . '</p>' : '')
            . '</div>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;">' . \implode('', $groupHtml) . '</div>'
            . '<div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;">'
            . '<span>漏 2026 ' . $siteTitle . '</span>'
            . ($domain !== '' ? '<span>' . $domain . '</span>' : '<span>Always improving the visitor experience</span>')
            . '</div></footer>';
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{label:string,href:string,active:bool}>
     */
    private function buildHeaderNavItems(array $scope): array
    {
        return $this->buildScopedNavItems($scope, true);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{label:string,href:string,active:bool}>
     */
    private function buildNavItems(array $scope): array
    {
        return $this->buildScopedNavItems($scope, false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{label:string,href:string,active:bool}>
     */
    private function buildFooterNavItems(array $scope): array
    {
        return $this->buildScopedNavItems($scope, false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{column1_title:string,column1_items:list<array{label:string,href:string,active:bool}>,column2_title:string,column2_items:list<array{label:string,href:string,active:bool}>,column3_title:string,column3_items:list<array{label:string,href:string,active:bool}>}
     */
    private function buildFooterLinkGroups(array $scope): array
    {
        $allItems = $this->buildScopedNavItems($scope, false);
        $legalTypes = [
            Page::TYPE_PRIVACY_POLICY,
            Page::TYPE_TERMS_OF_SERVICE,
            Page::TYPE_REFUND_POLICY,
            Page::TYPE_SHIPPING_POLICY,
            Page::TYPE_COOKIE_POLICY,
        ];
        $legalTypeMap = \array_flip($legalTypes);
        $featuredTypeMap = \array_flip([
            Page::TYPE_HOME,
            Page::TYPE_ABOUT,
            Page::TYPE_CONTACT,
            Page::TYPE_BLOG_LIST,
            Page::TYPE_CUSTOM,
        ]);

        $featured = [];
        $legal = [];
        foreach ($allItems as $item) {
            $type = (string)($item['type'] ?? '');
            if (isset($featuredTypeMap[$type])) {
                $featured[] = $item;
            }
            if (isset($legalTypeMap[$type])) {
                $legal[] = $item;
            }
        }

        if ($featured === []) {
            $featured = \array_slice($allItems, 0, 4);
        }
        if ($legal === []) {
            $legal = \array_slice($allItems, \min(1, \count($allItems)), 3);
        }

        return [
            'column1_title' => 'Featured Pages',
            'column1_items' => $featured,
            'column2_title' => 'Policy Info',
            'column2_items' => $legal,
            'column3_title' => 'All Pages',
            'column3_items' => $allItems,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{label:string,href:string,active:bool,type:string}>
     */
    private function buildScopedNavItems(array $scope, bool $headerOnly): array
    {
        $pageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT];
        $labels = Page::getPageTypes();
        $items = [];

        foreach ($pageTypes as $pageType) {
            if (!\is_string($pageType) || $pageType === '') {
                continue;
            }
            if (\in_array($pageType, [Page::TYPE_BLOG, Page::TYPE_BLOG_CATEGORY], true)) {
                continue;
            }

            $href = $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType);
            $items[] = [
                'label' => (string)($labels[$pageType] ?? $pageType),
                'href' => $href,
                'active' => $pageType === Page::TYPE_HOME,
                'type' => $pageType,
            ];
        }

        if ($items === []) {
            $items[] = ['label' => '首页', 'href' => '/', 'active' => true, 'type' => Page::TYPE_HOME];
        }

        if (!$headerOnly) {
            return $items;
        }

        $byType = [];
        foreach ($items as $item) {
            $type = (string)($item['type'] ?? '');
            if ($type !== '') {
                $byType[$type] = $item;
            }
        }

        $headerItems = [];
        foreach ([Page::TYPE_HOME, Page::TYPE_ABOUT] as $type) {
            if (isset($byType[$type])) {
                $headerItems[] = $byType[$type];
            }
        }

        $policyTarget = null;
        foreach ([Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY, Page::TYPE_COOKIE_POLICY] as $type) {
            if (!isset($byType[$type])) {
                continue;
            }
            $policyTarget = $byType[$type];
            break;
        }
        if ($policyTarget !== null) {
            $headerItems[] = [
                'label' => 'Policy Info',
                'href' => (string)($policyTarget['href'] ?? '#'),
                'active' => false,
                'type' => 'policy_info',
            ];
        }

        foreach ([Page::TYPE_BLOG_LIST, Page::TYPE_CONTACT] as $type) {
            if (isset($byType[$type])) {
                $headerItems[] = $byType[$type];
            }
        }

        $existingHeaderTypes = \array_flip(\array_map(
            static fn(array $entry): string => (string)($entry['type'] ?? ''),
            $headerItems
        ));

        foreach ($items as $item) {
            $type = (string)($item['type'] ?? '');
            if ($type === '' || isset($existingHeaderTypes[$type])) {
                continue;
            }
            $headerItems[] = $item;
            $existingHeaderTypes[$type] = true;
            if (\count($headerItems) >= 5) {
                break;
            }
        }

        return \array_slice($headerItems !== [] ? $headerItems : $items, 0, 5);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function normalizeBlockConfig(string $template, array $config, array $websiteProfile = [], array $scope = []): array
    {
        $pageBlueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);

        return match ($template) {
            'site_header' => [
                'site_title' => (string)($config['site_title'] ?? $websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
                'site_tagline' => (string)($config['site_tagline'] ?? $websiteProfile['site_tagline'] ?? $scope['site_tagline'] ?? ''),
                'current_page_label' => (string)($config['current_page_label'] ?? ''),
                'nav_items' => $this->normalizeNavItems($config['nav_items'] ?? $this->buildHeaderNavItems($scope)),
            ],
            'site_footer' => [
                'site_title' => (string)($config['site_title'] ?? $websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
                'brief_description' => (string)($config['brief_description'] ?? $pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $scope)),
                'domain' => (string)($config['domain'] ?? $websiteProfile['target_domain'] ?? $scope['target_domain'] ?? ''),
                'links.column1_title' => (string)($config['links.column1_title'] ?? 'Featured Pages'),
                'links.column1_items' => $this->normalizeNavItems($config['links.column1_items'] ?? []),
                'links.column2_title' => (string)($config['links.column2_title'] ?? 'Policy Info'),
                'links.column2_items' => $this->normalizeNavItems($config['links.column2_items'] ?? []),
                'links.column3_title' => (string)($config['links.column3_title'] ?? 'All Pages'),
                'links.column3_items' => $this->normalizeNavItems($config['links.column3_items'] ?? $this->buildFooterNavItems($scope)),
                'nav_items' => $this->normalizeNavItems($config['nav_items'] ?? $this->buildFooterNavItems($scope)),
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
            $label = \trim((string)($item['label'] ?? $item['text'] ?? $item['title'] ?? ''));
            if ($label === '') {
                continue;
            }
            $normalized[] = [
                'label' => $label,
                'href' => \trim((string)($item['href'] ?? $item['url'] ?? '#')),
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

