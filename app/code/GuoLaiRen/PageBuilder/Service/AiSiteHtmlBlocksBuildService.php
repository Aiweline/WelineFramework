<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\MockPage;
use GuoLaiRen\PageBuilder\Service\AI\PreviewRenderer;
use Weline\Framework\Manager\ObjectManager;

class AiSiteHtmlBlocksBuildService
{
    private const META_TEMPLATE_PHTML = '_pb_server_template_phtml';
    private const META_COMPONENT_CODE = '_pb_server_component_code';
    private const META_REGION = '_pb_server_region';
    private const META_USER_CUSTOM_NAV = '_pb_server_user_custom_navigation';
    private const META_USER_CUSTOM_LINKS = '_pb_server_user_custom_links';

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
        $sectionCount = 0;

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
                (string)($section['phtml'] ?? ''),
                \array_replace(
                    ['region' => 'content', 'html_content' => (string)($section['html'] ?? '')],
                    \is_array($section['default_config'] ?? null) ? $section['default_config'] : [],
                    ['component_label' => (string)($section['name'] ?? $blockId)]
                ),
                'content',
                (string)($section['code'] ?? '')
            );
            $sectionCount++;
        }
        if ($sectionCount === 0) {
            throw new \RuntimeException((string)__('AI 未生成任何可用页面区块：%{1}', [$pageType]));
        }

        $blocks[] = $this->buildFooterBlock($pageType, $websiteProfile, $scope);

        return $blocks;
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}
     */
    public function buildSharedHeaderBlock(string $pageType, array $websiteProfile, array $scope = []): array
    {
        return $this->buildHeaderBlock($pageType, $websiteProfile, $scope);
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}
     */
    public function buildSharedFooterBlock(string $pageType, array $websiteProfile, array $scope = []): array
    {
        return $this->buildFooterBlock($pageType, $websiteProfile, $scope);
    }

    /**
     * @param array<string, mixed> $section
     * @return array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}
     */
    public function buildGeneratedSectionBlock(array $section): array
    {
        $componentCode = \trim((string)($section['code'] ?? ''));
        $blockId = \str_replace(['content/', '/'], ['', '-'], $componentCode);
        $blockId = $blockId !== '' ? $blockId : ('block-' . \bin2hex(\random_bytes(4)));

        return $this->buildGeneratedBlockRecord(
            $blockId,
            'ai_generated_section',
            (string)($section['html'] ?? ''),
            (string)($section['phtml'] ?? ''),
            \array_replace(
                ['region' => 'content', 'html_content' => (string)($section['html'] ?? '')],
                \is_array($section['default_config'] ?? null) ? $section['default_config'] : [],
                ['component_label' => (string)($section['name'] ?? $blockId)]
            ),
            'content',
            $componentCode
        );
    }

    /**
     * @param array<string, mixed> $component
     * @return array{block_id:string,type:string,html:string,config:array<string,mixed>,field_schema:array<string,mixed>}
     */
    public function buildGeneratedSharedBlock(string $region, string $pageType, array $component): array
    {
        $region = \trim($region);
        $componentCode = \trim((string)($component['code'] ?? ''));
        $blockId = \str_replace(['/', '_'], '-', $componentCode !== '' ? $componentCode : ($region . '-' . $pageType));
        $blockId = $blockId !== '' ? $blockId : ($region . '-' . \str_replace('_', '-', $pageType));

        return $this->buildGeneratedBlockRecord(
            $blockId,
            'ai_generated_shared_' . ($region !== '' ? $region : 'component'),
            (string)($component['html'] ?? ''),
            (string)($component['phtml'] ?? ''),
            \array_replace(
                ['region' => $region, 'component_label' => (string)($component['name'] ?? $blockId)],
                \is_array($component['default_config'] ?? null) ? $component['default_config'] : []
            ),
            $region !== '' ? $region : 'content',
            $componentCode
        );
    }

    public function rebuildBlock(array $block, array $websiteProfile, array $scope = [], array $configPatch = []): array
    {
        $blockId = \trim((string)($block['block_id'] ?? ''));
        $type = \trim((string)($block['type'] ?? 'hero'));
        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        if (\str_starts_with($type, 'ai_generated_')) {
            $config = \array_replace_recursive($config, $configPatch);
            $syncResult = $this->syncGeneratedBlockConfig($block, $config, $configPatch);
            $config = $syncResult['config'];

            $rebuilt = [
                'block_id' => $blockId !== '' ? $blockId : ('block-' . \bin2hex(\random_bytes(4))),
                'type' => $type !== '' ? $type : 'ai_generated_section',
                'html' => $this->renderGeneratedBlockHtml($block, $config),
                'config' => $config,
                'field_schema' => $this->buildGeneratedFieldSchema(
                    (string)($block[self::META_TEMPLATE_PHTML] ?? ''),
                    $config,
                    $type !== '' ? $type : 'ai_generated_section',
                    $this->resolveSharedBlockRegion($block)
                ),
            ];

            foreach ([self::META_TEMPLATE_PHTML, self::META_COMPONENT_CODE, self::META_REGION, self::META_USER_CUSTOM_NAV, self::META_USER_CUSTOM_LINKS] as $metaKey) {
                if (\array_key_exists($metaKey, $block)) {
                    $rebuilt[$metaKey] = $block[$metaKey];
                }
            }
            foreach ($syncResult['meta'] as $metaKey => $metaValue) {
                if ($metaValue) {
                    $rebuilt[$metaKey] = $metaValue;
                } else {
                    unset($rebuilt[$metaKey]);
                }
            }

            return $rebuilt;
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
    private function buildGeneratedBlockRecord(
        string $blockId,
        string $type,
        string $html,
        string $phtml,
        array $config,
        string $region = 'content',
        string $componentCode = ''
    ): array
    {
        $block = [
            'block_id' => $blockId,
            'type' => $type,
            'html' => $html,
            'config' => $config,
            'field_schema' => $this->buildGeneratedFieldSchema($phtml, $config, $type, $region),
        ];

        if ($phtml !== '') {
            $block[self::META_TEMPLATE_PHTML] = $phtml;
        }
        if ($componentCode !== '') {
            $block[self::META_COMPONENT_CODE] = $componentCode;
        }
        if ($region !== '') {
            $block[self::META_REGION] = $region;
        }

        if ($phtml !== '') {
            $renderedHtml = $this->renderGeneratedBlockHtml($block, $config);
            if (\trim($renderedHtml) !== '') {
                $block['html'] = $renderedHtml;
            }
        }

        return $block;
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
                'current_page_label' => $this->resolveScopedPageTypeLabel($scope, $pageType),
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
            'ai_generated_footer',
            'ai_generated_shared_header',
            'ai_generated_shared_footer' => [
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
     * Keep older sessions editable even when their original metadata was missing.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public function hydrateGeneratedBlockMetadata(array $block): array
    {
        if (!\is_array($block['field_schema'] ?? null) || $block['field_schema'] === []) {
            $block['field_schema'] = $this->buildGeneratedFieldSchema(
                (string)($block[self::META_TEMPLATE_PHTML] ?? ''),
                \is_array($block['config'] ?? null) ? $block['config'] : [],
                (string)($block['type'] ?? ''),
                $this->resolveSharedBlockRegion($block)
            );
        }

        return $block;
    }

    /**
     * @param array<string, mixed> $newBlock
     * @param array<string, mixed> $existingBlock
     * @return array<string, mixed>
     */
    public function mergeUserCustomizedSharedBlockConfig(array $newBlock, array $existingBlock): array
    {
        $region = $this->resolveSharedBlockRegion($newBlock);
        if ($region === '' || $region !== $this->resolveSharedBlockRegion($existingBlock)) {
            return $newBlock;
        }

        $newConfig = \is_array($newBlock['config'] ?? null) ? $newBlock['config'] : [];
        $existingConfig = \is_array($existingBlock['config'] ?? null) ? $existingBlock['config'] : [];

        if ($region === 'header' && !empty($existingBlock[self::META_USER_CUSTOM_NAV])) {
            foreach (['navigation.display', 'navigation.items', 'nav_items'] as $key) {
                if (\array_key_exists($key, $existingConfig)) {
                    $newConfig[$key] = $existingConfig[$key];
                }
            }
            $newBlock[self::META_USER_CUSTOM_NAV] = 1;
        }

        if ($region === 'footer' && !empty($existingBlock[self::META_USER_CUSTOM_LINKS])) {
            foreach ([
                'links.column1_title',
                'links.column1_items',
                'links.column2_title',
                'links.column2_items',
                'links.column3_title',
                'links.column3_items',
                'nav_items',
            ] as $key) {
                if (\array_key_exists($key, $existingConfig)) {
                    $newConfig[$key] = $existingConfig[$key];
                }
            }
            $newBlock[self::META_USER_CUSTOM_LINKS] = 1;
        }

        $newBlock['config'] = $newConfig;
        if (isset($newBlock[self::META_TEMPLATE_PHTML])) {
            $newBlock['html'] = $this->renderGeneratedBlockHtml($newBlock, $newConfig);
        }
        $newBlock['field_schema'] = $this->buildGeneratedFieldSchema(
            (string)($newBlock[self::META_TEMPLATE_PHTML] ?? ''),
            $newConfig,
            (string)($newBlock['type'] ?? ''),
            $region
        );

        return $newBlock;
    }

    /**
     * @param array<string, mixed> $block
     */
    public function resolveSharedBlockRegion(array $block): string
    {
        $region = \trim((string)($block[self::META_REGION] ?? ''));
        if (\in_array($region, ['header', 'footer'], true)) {
            return $region;
        }

        $type = \trim((string)($block['type'] ?? ''));
        if (\str_contains($type, 'header')) {
            return 'header';
        }
        if (\str_contains($type, 'footer')) {
            return 'footer';
        }

        $blockId = \trim((string)($block['block_id'] ?? ''));
        if (\str_contains($blockId, 'header')) {
            return 'header';
        }
        if (\str_contains($blockId, 'footer')) {
            return 'footer';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public function stripServerOnlyBlock(array $block): array
    {
        foreach (\array_keys($block) as $key) {
            if (\is_string($key) && \str_starts_with($key, '_pb_server_')) {
                unset($block[$key]);
            }
        }

        return $block;
    }

    /**
     * @param array<string, array<string, mixed>> $virtualPages
     * @return array<string, array<string, mixed>>
     */
    public function stripServerOnlyVirtualPages(array $virtualPages): array
    {
        foreach ($virtualPages as $pageType => $page) {
            if (!\is_string($pageType) || !\is_array($page)) {
                continue;
            }
            $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            foreach ($blocks as $index => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blocks[$index] = $this->stripServerOnlyBlock($block);
            }
            $page['blocks'] = $blocks;
            $virtualPages[$pageType] = $page;
        }

        return $virtualPages;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $configPatch
     * @return array{config:array<string, mixed>,meta:array<string, int>}
     */
    private function syncGeneratedBlockConfig(array $block, array $config, array $configPatch): array
    {
        $region = $this->resolveSharedBlockRegion($block);
        $meta = [];

        if ($region === 'header' && (\array_key_exists('navigation.items', $configPatch) || \array_key_exists('nav_items', $configPatch))) {
            if (\array_key_exists('navigation.items', $configPatch)) {
                $config['nav_items'] = $this->parseNavigationLines((string)($config['navigation.items'] ?? ''));
            } elseif (\array_key_exists('nav_items', $configPatch)) {
                $config['navigation.items'] = $this->stringifyNavigationLines($config['nav_items'] ?? []);
            }

            if ($this->hasMeaningfulNavigationValue($config['navigation.items'] ?? null) || $this->hasMeaningfulNavigationValue($config['nav_items'] ?? null)) {
                $meta[self::META_USER_CUSTOM_NAV] = 1;
            } else {
                $meta[self::META_USER_CUSTOM_NAV] = 0;
            }
        }

        if (
            $region === 'footer'
            && (
                \array_key_exists('links.column1_items', $configPatch)
                || \array_key_exists('links.column2_items', $configPatch)
                || \array_key_exists('links.column3_items', $configPatch)
            )
        ) {
            if (
                $this->hasMeaningfulNavigationValue($config['links.column1_items'] ?? null)
                || $this->hasMeaningfulNavigationValue($config['links.column2_items'] ?? null)
                || $this->hasMeaningfulNavigationValue($config['links.column3_items'] ?? null)
            ) {
                $meta[self::META_USER_CUSTOM_LINKS] = 1;
            } else {
                $meta[self::META_USER_CUSTOM_LINKS] = 0;
            }
        }

        return ['config' => $config, 'meta' => $meta];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildGeneratedFieldSchema(string $phtml, array $config, string $type, string $region): array
    {
        $schema = $this->parseFieldsSchemaFromPhtml($phtml);
        if ($schema === []) {
            $schema = $this->buildSchemaFromConfig($config, $type, $region);
        } else {
            $schema = $this->mergeConfigDrivenFieldsIntoSchema($schema, $config, $region);
        }

        if ($schema === []) {
            $schema = $this->buildFieldSchema($type);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $config
     */
    private function renderGeneratedBlockHtml(array $block, array $config): string
    {
        $phtml = (string)($block[self::META_TEMPLATE_PHTML] ?? '');
        if (\trim($phtml) === '') {
            return \trim((string)($config['html_content'] ?? $block['html'] ?? ''));
        }

        $renderer = new PreviewRenderer();
        $renderer->setData('component_config', $config);
        $renderer->setData('page', $this->buildPreviewPageForGeneratedBlock($block));
        $result = $renderer->render($phtml);
        if (!($result['success'] ?? false)) {
            return \trim((string)($block['html'] ?? ''));
        }

        return \trim((string)($result['html'] ?? ''));
    }

    /**
     * @param array<string, mixed> $block
     */
    private function buildPreviewPageForGeneratedBlock(array $block): MockPage
    {
        if (!empty($block[self::META_USER_CUSTOM_NAV]) || !empty($block[self::META_USER_CUSTOM_LINKS])) {
            return new class extends MockPage {
                public function getNavigationPages(array $options = [], int $limit = 10): array
                {
                    return [];
                }
            };
        }

        return new MockPage();
    }

    /**
     * @return array<string, array{label:string,fields:array<string,array<string,mixed>>}>
     */
    private function parseFieldsSchemaFromPhtml(string $phtml): array
    {
        if ($phtml === '' || !\preg_match('/@fields_start(.*?)@fields_end/s', $phtml, $matches)) {
            return [];
        }

        $schema = [];
        $currentGroupKey = 'content';
        $currentGroupLabel = 'Content';

        foreach (\preg_split('/\r?\n/', (string)$matches[1]) ?: [] as $line) {
            $line = \trim((string)\preg_replace('/^\s*\*\s?/', '', (string)$line));
            if ($line === '') {
                continue;
            }

            if (\preg_match('/^group:([a-zA-Z0-9_-]+)\s*=>\s*(.+)$/', $line, $groupMatch)) {
                $currentGroupKey = \trim((string)$groupMatch[1]);
                $currentGroupLabel = \trim((string)$groupMatch[2]);
                $schema[$currentGroupKey] ??= [
                    'label' => $currentGroupLabel !== '' ? $currentGroupLabel : $currentGroupKey,
                    'fields' => [],
                ];
                continue;
            }

            if (!\preg_match('/^([a-zA-Z0-9._-]+)\s*=>\s*(.+)$/', $line, $fieldMatch)) {
                continue;
            }

            $fieldKey = \trim((string)$fieldMatch[1]);
            $fieldDef = \trim((string)$fieldMatch[2]);
            $schema[$currentGroupKey] ??= [
                'label' => $currentGroupLabel !== '' ? $currentGroupLabel : $currentGroupKey,
                'fields' => [],
            ];
            $schema[$currentGroupKey]['fields'][$fieldKey] = $this->parseFieldDefinition($fieldKey, $fieldDef);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFieldDefinition(string $fieldKey, string $fieldDef): array
    {
        $parts = \explode(':', $fieldDef);
        $label = \trim((string)($parts[0] ?? $fieldKey));
        $type = \strtolower(\trim((string)($parts[1] ?? 'text')));
        $tail = \implode(':', \array_slice($parts, 2));
        $default = $tail;
        $options = [];
        $format = '';

        if ($type === 'select') {
            [$default, $options] = $this->parseSelectFieldTail($tail);
        } elseif ($type === 'number') {
            [$default] = \explode('|', $tail, 2);
            $default = \trim((string)$default);
        } else {
            [$default] = \explode('|', $tail, 2);
            $default = \trim((string)$default);
        }

        if (
            $fieldKey === 'navigation.items'
            || $fieldKey === 'nav_items'
            || \preg_match('/^links\.column\d+_items$/', $fieldKey) === 1
        ) {
            $format = $fieldKey === 'navigation.items' ? 'nav-lines' : 'nav-lines';
            if ($type !== 'textarea') {
                $type = 'textarea';
            }
        } elseif ($fieldKey === 'items') {
            $format = 'card-items';
            $type = 'textarea';
        } elseif (\str_ends_with($fieldKey, '.items') || $fieldKey === 'chips' || $fieldKey === 'points') {
            $format = 'lines';
            $type = 'textarea';
        }

        $field = [
            'type' => $type !== '' ? $type : 'text',
            'label' => $label !== '' ? $label : $fieldKey,
            'default' => $default,
        ];
        if ($options !== []) {
            $field['options'] = $options;
        }
        if ($format !== '') {
            $field['format'] = $format;
        }

        return $field;
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    private function parseSelectFieldTail(string $tail): array
    {
        [$optionPart] = \explode('|', $tail, 2);
        $optionPart = \trim((string)$optionPart);
        if ($optionPart === '') {
            return ['', []];
        }

        $segments = \array_values(\array_filter(\array_map('trim', \explode(',', $optionPart)), static fn(string $value): bool => $value !== ''));
        if ($segments === []) {
            return [$optionPart, [$optionPart]];
        }

        $default = \trim((string)\explode('|', $segments[0])[0]);
        if (\str_contains($segments[0], '|')) {
            $firstParts = \array_values(\array_filter(\array_map('trim', \explode('|', $segments[0])), static fn(string $value): bool => $value !== ''));
            if ($firstParts !== []) {
                $default = $firstParts[0];
                $segments = \array_values(\array_unique(\array_merge($firstParts, \array_slice($segments, 1))));
            }
        }

        return [$default, $segments];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array{label:string,fields:array<string,array<string,mixed>>}>
     */
    private function buildSchemaFromConfig(array $config, string $type, string $region): array
    {
        $schema = [];
        foreach ($config as $fieldKey => $value) {
            if (!\is_string($fieldKey) || $fieldKey === '' || \str_starts_with($fieldKey, '_')) {
                continue;
            }
            if ($fieldKey === 'region' || $fieldKey === 'component_label') {
                continue;
            }

            $groupKey = $this->resolveSchemaGroupKey($fieldKey, $region);
            $schema[$groupKey] ??= [
                'label' => $this->humanizeGroupLabel($groupKey),
                'fields' => [],
            ];
            $schema[$groupKey]['fields'][$fieldKey] = $this->inferSchemaFieldFromConfig($fieldKey, $value, $type, $region);
        }

        return $schema;
    }

    /**
     * @param array<string, array{label:string,fields:array<string,array<string,mixed>>}> $schema
     * @param array<string, mixed> $config
     * @return array<string, array{label:string,fields:array<string,array<string,mixed>>}>
     */
    private function mergeConfigDrivenFieldsIntoSchema(array $schema, array $config, string $region): array
    {
        $fallback = $this->buildSchemaFromConfig($config, '', $region);
        foreach ($fallback as $groupKey => $group) {
            $schema[$groupKey] ??= $group;
            foreach ($group['fields'] as $fieldKey => $field) {
                $schema[$groupKey]['fields'][$fieldKey] ??= $field;
            }
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function inferSchemaFieldFromConfig(string $fieldKey, mixed $value, string $type, string $region): array
    {
        $field = [
            'type' => 'text',
            'label' => $this->humanizeFieldLabel($fieldKey),
        ];

        if (
            $fieldKey === 'navigation.items'
            || $fieldKey === 'nav_items'
            || \preg_match('/^links\.column\d+_items$/', $fieldKey) === 1
        ) {
            $field['type'] = 'textarea';
            $field['format'] = 'nav-lines';
            return $field;
        }

        if ($fieldKey === 'items') {
            $field['type'] = 'textarea';
            $field['format'] = 'card-items';
            return $field;
        }

        if ($fieldKey === 'points' || $fieldKey === 'chips') {
            $field['type'] = 'textarea';
            $field['format'] = 'lines';
            return $field;
        }

        if (\is_array($value)) {
            $field['type'] = 'textarea';
            $field['format'] = 'lines';
            return $field;
        }

        $textValue = \trim((string)$value);
        if ($textValue !== '' && \preg_match('/^#[0-9a-fA-F]{3,8}$/', $textValue) === 1) {
            $field['type'] = 'color';
            return $field;
        }
        if ($textValue !== '' && \is_numeric($textValue)) {
            $field['type'] = 'number';
            return $field;
        }
        if (\in_array(\strtolower($textValue), ['yes', 'no', 'true', 'false'], true)) {
            $field['type'] = 'select';
            $field['options'] = ['yes', 'no'];
            return $field;
        }
        if (\str_contains($fieldKey, 'description') || \str_contains($fieldKey, 'html') || \str_contains($fieldKey, 'image')) {
            $field['type'] = 'textarea';
        }

        return $field;
    }

    private function resolveSchemaGroupKey(string $fieldKey, string $region): string
    {
        if (\str_contains($fieldKey, '.')) {
            return (string)\explode('.', $fieldKey, 2)[0];
        }
        if ($region !== '') {
            return $region;
        }
        return 'content';
    }

    private function humanizeGroupLabel(string $groupKey): string
    {
        return \ucwords(\str_replace(['_', '-'], ' ', $groupKey !== '' ? $groupKey : 'content'));
    }

    private function humanizeFieldLabel(string $fieldKey): string
    {
        $tail = \str_contains($fieldKey, '.') ? (string)\substr($fieldKey, (int)\strrpos($fieldKey, '.') + 1) : $fieldKey;
        return \ucwords(\str_replace(['_', '-'], ' ', $tail));
    }

    private function hasMeaningfulNavigationValue(mixed $value): bool
    {
        if (\is_array($value)) {
            return $this->normalizeNavItems($value) !== [];
        }

        if (!\is_scalar($value)) {
            return false;
        }

        foreach (\preg_split('/\r?\n/', \trim((string)$value)) ?: [] as $line) {
            $line = \trim((string)$line);
            if ($line !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{label:string,href:string,active:bool}>
     */
    private function parseNavigationLines(string $raw): array
    {
        $items = [];
        foreach (\preg_split('/\r?\n/', \trim($raw)) ?: [] as $index => $line) {
            $line = \trim((string)$line);
            if ($line === '') {
                continue;
            }

            $parts = \str_contains($line, '=>')
                ? \explode('=>', $line, 2)
                : \explode('|', $line, 2);
            $label = \trim((string)($parts[0] ?? ''));
            if ($label === '') {
                continue;
            }
            $items[] = [
                'label' => $label,
                'href' => \trim((string)($parts[1] ?? '#')) ?: '#',
                'active' => $index === 0,
            ];
        }

        return $items;
    }

    private function stringifyNavigationLines(mixed $items): string
    {
        return \implode("\n", \array_map(
            static fn(array $item): string => \trim((string)($item['label'] ?? $item['text'] ?? '')) . '=>' . (\trim((string)($item['href'] ?? $item['url'] ?? '#')) ?: '#'),
            $this->normalizeNavItems($items)
        ));
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
        $locale = $this->resolveContentLocale($scope);
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
            'column1_title' => $this->localizeBuildText('featured_pages', $locale),
            'column1_items' => $featured,
            'column2_title' => $this->localizeBuildText('policy_info', $locale),
            'column2_items' => $legal,
            'column3_title' => $this->localizeBuildText('all_pages', $locale),
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
        $locale = $this->resolveContentLocale($scope);
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
                'label' => $this->resolveScopedPageTypeLabel($scope, $pageType),
                'href' => $href,
                'active' => $pageType === Page::TYPE_HOME,
                'type' => $pageType,
            ];
        }

        if ($items === []) {
            $items[] = ['label' => $this->localizePageTypeLabel(Page::TYPE_HOME, $locale) ?: 'Home', 'href' => '/', 'active' => true, 'type' => Page::TYPE_HOME];
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
                'label' => $this->localizeBuildText('policy_info', $locale),
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

    private function resolveScopedPageTypeLabel(array $scope, string $pageType): string
    {
        $locale = $this->resolveContentLocale($scope);
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $title = \trim((string)($virtualPages[$pageType]['title'] ?? ''));
        if ($title !== '' && !($this->isNonCjkLocale($locale) && $this->hasAnyCjkContent($title))) {
            return $title;
        }

        $localized = $this->localizePageTypeLabel($pageType, $locale);
        if ($localized !== '') {
            return $localized;
        }

        return (string)(Page::getPageTypes()[$pageType] ?? $pageType);
    }

    private function resolveContentLocale(array $scope): string
    {
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];

        return \trim((string)(
            $scope['content_locale']
                ?? $websiteProfile['content_locale']
                ?? $scope['default_locale']
                ?? $scope['default_language']
                ?? $websiteProfile['default_locale']
                ?? ''
        ));
    }

    private function localizePageTypeLabel(string $pageType, string $locale): string
    {
        $isZh = $this->isChineseLocale($locale);
        $isJa = $this->isJapaneseLocale($locale);
        $isKo = $this->isKoreanLocale($locale);

        return match ($pageType) {
            Page::TYPE_HOME => $isZh ? '首页' : ($isJa ? 'ホーム' : ($isKo ? '홈' : 'Home')),
            Page::TYPE_ABOUT => $isZh ? '关于我们' : ($isJa ? '私たちについて' : ($isKo ? '회사 소개' : 'About')),
            Page::TYPE_CONTACT => $isZh ? '联系我们' : ($isJa ? 'お問い合わせ' : ($isKo ? '문의하기' : 'Contact')),
            Page::TYPE_BLOG_LIST, Page::TYPE_BLOG => $isZh ? '博客' : ($isJa ? 'ブログ' : ($isKo ? '블로그' : 'Blog')),
            Page::TYPE_PRIVACY_POLICY => $isZh ? '隐私政策' : ($isJa ? 'プライバシーポリシー' : ($isKo ? '개인정보처리방침' : 'Privacy Policy')),
            Page::TYPE_TERMS_OF_SERVICE => $isZh ? '服务条款' : ($isJa ? '利用規約' : ($isKo ? '이용약관' : 'Terms of Service')),
            Page::TYPE_REFUND_POLICY => $isZh ? '退款政策' : ($isJa ? '返金ポリシー' : ($isKo ? '환불 정책' : 'Refund Policy')),
            Page::TYPE_SHIPPING_POLICY => $isZh ? '配送政策' : ($isJa ? '配送ポリシー' : ($isKo ? '배송 정책' : 'Shipping Policy')),
            Page::TYPE_COOKIE_POLICY => $isZh ? 'Cookie 政策' : ($isJa ? 'Cookie ポリシー' : ($isKo ? '쿠키 정책' : 'Cookie Policy')),
            default => '',
        };
    }

    private function localizeBuildText(string $key, string $locale): string
    {
        $isZh = $this->isChineseLocale($locale);
        $isJa = $this->isJapaneseLocale($locale);
        $isKo = $this->isKoreanLocale($locale);

        return match ($key) {
            'policy_info' => $isZh ? '政策信息' : ($isJa ? 'ポリシー' : ($isKo ? '정책 정보' : 'Policy Info')),
            'featured_pages' => $isZh ? '重点页面' : ($isJa ? '注目ページ' : ($isKo ? '주요 페이지' : 'Featured Pages')),
            'all_pages' => $isZh ? '全部页面' : ($isJa ? 'すべてのページ' : ($isKo ? '모든 페이지' : 'All Pages')),
            default => $key,
        };
    }

    private function isChineseLocale(string $locale): bool
    {
        return \preg_match('/^(zh|zh[_-]hans|zh[_-]cn|zh[_-]sg)/i', $locale) === 1;
    }

    private function isJapaneseLocale(string $locale): bool
    {
        return \preg_match('/^ja(?:[_-]|$)/i', $locale) === 1;
    }

    private function isKoreanLocale(string $locale): bool
    {
        return \preg_match('/^ko(?:[_-]|$)/i', $locale) === 1;
    }

    private function isNonCjkLocale(string $locale): bool
    {
        return $locale !== '' && !$this->isChineseLocale($locale) && !$this->isJapaneseLocale($locale) && !$this->isKoreanLocale($locale);
    }

    private function hasMeaningfulCjkContent(string $value): bool
    {
        return \preg_match('/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}\x{ac00}-\x{d7af}]/u', $value) === 1;
    }

    private function hasAnyCjkContent(string $value): bool
    {
        return \preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $value) === 1;
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
