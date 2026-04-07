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
        $sharedComponents = \is_array($scope['_ai_generated_shared_components'] ?? null)
            ? $scope['_ai_generated_shared_components']
            : $generationService->generateSharedComponents($websiteProfile, $scope);
        $pageSections = $generationService->generatePageSections($pageType, $websiteProfile, $scope);

        $blocks = [
            $this->buildGeneratedBlockRecord(
                \str_replace('_', '-', $pageType) . '-site-header',
                'ai_generated_header',
                (string)($sharedComponents['header']['html'] ?? ''),
                \array_replace(
                    ['region' => 'header', 'html_content' => (string)($sharedComponents['header']['html'] ?? '')],
                    \is_array($sharedComponents['header']['default_config'] ?? null) ? $sharedComponents['header']['default_config'] : []
                )
            ),
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

        $blocks[] = $this->buildGeneratedBlockRecord(
            \str_replace('_', '-', $pageType) . '-site-footer',
            'ai_generated_footer',
            (string)($sharedComponents['footer']['html'] ?? ''),
            \array_replace(
                ['region' => 'footer', 'html_content' => (string)($sharedComponents['footer']['html'] ?? '')],
                \is_array($sharedComponents['footer']['default_config'] ?? null) ? $sharedComponents['footer']['default_config'] : []
            )
        );

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
        return $this->buildBlockRecord(
            \str_replace('_', '-', $pageType) . '-site-footer',
            'site_footer',
            [
                'site_title' => (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
                'brief_description' => $pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $scope),
                'domain' => (string)($websiteProfile['target_domain'] ?? $scope['target_domain'] ?? ''),
                'nav_items' => $this->buildFooterNavItems($scope),
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
                    'label' => 'AI 生成结果',
                    'fields' => [
                        'html_content' => ['type' => 'textarea', 'label' => 'HTML 内容'],
                    ],
                ],
            ],
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
            . ($domain !== '' ? '<span>' . $domain . '</span>' : '<span>Always improving the visitor experience</span>')
            . '</div></footer>';
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{label:string,href:string,active:bool}>
     */
    private function buildNavItems(array $scope): array
    {
        return $this->buildScopedNavItems($scope, true);
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
     * @return list<array{label:string,href:string,active:bool}>
     */
    private function buildScopedNavItems(array $scope, bool $headerOnly): array
    {
        $pageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT];
        $labels = Page::getPageTypes();
        $headerTypeMap = \array_flip(Page::getHeaderMenuTypes());
        $items = [];

        foreach ($pageTypes as $pageType) {
            if (!\is_string($pageType) || $pageType === '') {
                continue;
            }
            if ($headerOnly && !isset($headerTypeMap[$pageType])) {
                continue;
            }

            $href = $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType);
            $items[] = [
                'label' => (string)($labels[$pageType] ?? $pageType),
                'href' => $href,
                'active' => $pageType === Page::TYPE_HOME,
            ];
            if ($headerOnly && \count($items) >= 5) {
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
        $pageBlueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);

        return match ($template) {
            'site_header' => [
                'site_title' => (string)($config['site_title'] ?? $websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
                'site_tagline' => (string)($config['site_tagline'] ?? $websiteProfile['site_tagline'] ?? $scope['site_tagline'] ?? ''),
                'current_page_label' => (string)($config['current_page_label'] ?? ''),
                'nav_items' => $this->normalizeNavItems($config['nav_items'] ?? $this->buildNavItems($scope)),
            ],
            'site_footer' => [
                'site_title' => (string)($config['site_title'] ?? $websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
                'brief_description' => (string)($config['brief_description'] ?? $pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $scope)),
                'domain' => (string)($config['domain'] ?? $websiteProfile['target_domain'] ?? $scope['target_domain'] ?? ''),
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
