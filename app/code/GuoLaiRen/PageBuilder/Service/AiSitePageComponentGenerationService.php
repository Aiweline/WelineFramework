<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use GuoLaiRen\PageBuilder\Service\AI\CodeFixer;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use GuoLaiRen\PageBuilder\Service\AI\PreviewRenderer;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

final class AiSitePageComponentGenerationService
{
    public function __construct(
        private readonly ?FrameworkBuilder $frameworkBuilder = null,
        private readonly ?AiResponseJsonParser $responseJsonParser = null,
        private readonly ?CodeFixer $codeFixer = null,
        private readonly ?CodeValidator $codeValidator = null,
        private readonly ?AiService $aiService = null,
        private readonly ?AiSitePageBlueprintService $pageBlueprintService = null,
        private readonly ?Page $pageModel = null,
        private readonly ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
    ) {
    }

    /**
     * @return array{
     *   header:array{
     *     code:string,
     *     name:string,
     *     region:string,
     *     phtml:string,
     *     html:string,
     *     default_config:array<string,mixed>,
     *     ai_data:array<string,mixed>
     *   },
     *   footer:array{
     *     code:string,
     *     name:string,
     *     region:string,
     *     phtml:string,
     *     html:string,
     *     default_config:array<string,mixed>,
     *     ai_data:array<string,mixed>
     *   }
     * }
     */
    public function generateSharedComponents(array $websiteProfile, array $scope): array
    {
        $siteDisplayName = $this->getPageBlueprintService()->resolveSiteDisplayName($websiteProfile, $scope);
        $cacheKey = \md5((string)\json_encode([
            'site' => $siteDisplayName,
            'brief' => $this->pickString($websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, $scope['user_description'] ?? null),
            'pages' => $this->resolveScopedPageTypes($scope),
            'style' => $this->resolvePromptStyleCode($scope, Page::TYPE_HOME),
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        static $sharedCache = [];
        if (isset($sharedCache[$cacheKey]) && \is_array($sharedCache[$cacheKey])) {
            return $sharedCache[$cacheKey];
        }

        $headerConfig = $this->buildHeaderDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        $footerConfig = $this->buildFooterDefaultConfig($websiteProfile, $scope, $siteDisplayName);

        $sharedCache[$cacheKey] = [
            'header' => $this->generateComponent(
                'header/ai-site-header',
                'AI Site Header',
                'header',
                $this->buildHeaderGenerationPrompt($websiteProfile, $scope, $siteDisplayName, $headerConfig),
                $headerConfig,
                $this->buildRenderContext(Page::TYPE_HOME, $websiteProfile, $scope, $headerConfig)
            ),
            'footer' => $this->generateComponent(
                'footer/ai-site-footer',
                'AI Site Footer',
                'footer',
                $this->buildFooterGenerationPrompt($websiteProfile, $scope, $siteDisplayName, $footerConfig),
                $footerConfig,
                $this->buildRenderContext(Page::TYPE_HOME, $websiteProfile, $scope, $footerConfig)
            ),
        ];

        return $sharedCache[$cacheKey];
    }

    /**
     * @return array{
     *   blueprint:array<string,mixed>,
     *   sections:list<array{
     *     key:string,
     *     code:string,
     *     name:string,
     *     region:string,
     *     sort_order:int,
     *     phtml:string,
     *     html:string,
     *     default_config:array<string,mixed>,
     *     ai_data:array<string,mixed>
     *   }>
     * }
     */
    public function generatePageSections(string $pageType, array $websiteProfile, array $scope): array
    {
        $blueprint = $this->getPageBlueprintService()->buildPageBlueprint($pageType, $scope, $websiteProfile);
        $sections = [];

        foreach (($blueprint['sections'] ?? []) as $section) {
            if (!\is_array($section)) {
                continue;
            }

            $sectionCode = \trim((string)($section['code'] ?? ''));
            if ($sectionCode === '') {
                continue;
            }

            $defaultConfig = $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
            $sections[] = \array_replace(
                [
                    'key' => (string)($section['key'] ?? ''),
                    'code' => $sectionCode,
                    'name' => (string)($section['name'] ?? $sectionCode),
                    'region' => 'content',
                    'sort_order' => (int)($section['sort_order'] ?? 0),
                ],
                $this->generateComponent(
                    $sectionCode,
                    (string)($section['name'] ?? $sectionCode),
                    'content',
                    $this->buildSectionGenerationPrompt($pageType, $section, $blueprint, $websiteProfile, $scope),
                    $defaultConfig,
                    $this->buildRenderContext($pageType, $websiteProfile, $scope, $defaultConfig)
                )
            );
        }

        return [
            'blueprint' => $blueprint,
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     * @return array{
     *   code:string,
     *   name:string,
     *   region:string,
     *   phtml:string,
     *   html:string,
     *   default_config:array<string,mixed>,
     *   ai_data:array<string,mixed>
     * }
     */
    private function generateComponent(
        string $componentCode,
        string $name,
        string $region,
        string $prompt,
        array $defaultConfig,
        array $renderContext
    ): array {
        $aiData = $this->runAiGeneration($region, $prompt);
        $this->ensureAiPayloadValid($aiData, $region);

        $phtml = $this->getFrameworkBuilder()->buildComponent($region, [
            'name' => $name,
            'name_en' => $name,
            'description' => $prompt,
        ], $aiData);

        $syntaxCheck = $this->getCodeValidator()->checkSyntax($phtml);
        if (empty($syntaxCheck['valid'])) {
            throw new \RuntimeException((string)__('AI 生成的组件未通过 PHP 语法校验：%{message}', [
                'message' => (string)($syntaxCheck['error'] ?? 'unknown'),
            ]));
        }

        $html = $this->renderTemplateToHtml($phtml, $defaultConfig, $renderContext);

        return [
            'code' => $componentCode,
            'name' => $name,
            'region' => $region,
            'phtml' => $phtml,
            'html' => $html,
            'default_config' => $defaultConfig,
            'ai_data' => $aiData,
        ];
    }

    /**
     * @param array<string,mixed> $aiData
     */
    private function ensureAiPayloadValid(array $aiData, string $region): void
    {
        if ($this->isTestEnvironment()) {
            return;
        }

        $validation = $this->getCodeValidator()->validateAiData($aiData, $region);
        if (!empty($validation['valid'])) {
            return;
        }

        $errors = \array_values(\array_filter(\array_map('strval', $validation['errors'] ?? [])));
        throw new \RuntimeException((string)__('AI 组件 JSON 校验失败：%{message}', [
            'message' => \implode('; ', \array_slice($errors, 0, 5)),
        ]));
    }

    /**
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function renderTemplateToHtml(string $phtml, array $defaultConfig, array $renderContext): string
    {
        $renderer = new PreviewRenderer();
        $renderer->setData('component_config', $defaultConfig);

        foreach ($renderContext as $key => $value) {
            $renderer->setData($key, $value);
        }

        $result = $renderer->render($phtml);
        if (!($result['success'] ?? false)) {
            throw new \RuntimeException((string)__('AI 组件预览渲染失败：%{message}', [
                'message' => (string)($result['error'] ?? 'unknown'),
            ]));
        }

        return (string)($result['html'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function runAiGeneration(string $region, string $prompt): array
    {
        if ($this->isTestEnvironment()) {
            return $this->buildStubAiPayload($region, $prompt);
        }

        $fullContent = '';
        $this->getAiService()->generateStream(
            $prompt,
            static function (string $chunk) use (&$fullContent): bool {
                $fullContent .= $chunk;
                return true;
            },
            null,
            'pagebuilder_component_generation',
            null
        );

        $payload = $this->getResponseJsonParser()->extractAndDecode($fullContent);
        if ($payload === null) {
            throw new \RuntimeException((string)__('AI 未返回有效的组件 JSON 结果'));
        }

        return $this->normalizeComponentPayload($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildStubAiPayload(string $region, string $prompt): array
    {
        $summary = $this->clipText(\preg_replace('/\s+/u', ' ', \trim($prompt)) ?: '', 220);

        return match ($region) {
            'header' => [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#<?= $componentId ?> { border-bottom: 1px solid rgba(15, 23, 42, 0.08); }'
                    . "\n" . '#<?= $componentId ?> .<?= $cls ?>-logo { letter-spacing: -0.02em; }'
                    . "\n" . '#<?= $componentId ?> .<?= $cls ?>-cta { box-shadow: 0 10px 24px rgba(37,99,235,0.22); }',
                'html_extra' => '',
                'js_content' => '',
            ],
            'footer' => [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#<?= $componentId ?> { border-top: 1px solid rgba(148,163,184,0.18); }'
                    . "\n" . '#<?= $componentId ?> .<?= $cls ?>-bottom { font-size: 12px; }',
                'html_extra_column' => '',
                'html_extra' => '',
                'footer_extra_text' => 'Generated in test mode',
                'js_content' => '',
            ],
            default => [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#<?= $componentId ?> .<?= $cls ?>-body { display:grid; gap:18px; }'
                    . "\n" . '#<?= $componentId ?> .ai-site-card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; }'
                    . "\n" . '#<?= $componentId ?> .ai-site-card { padding:20px; border-radius:20px; border:1px solid var(--section-border); background:rgba(255,255,255,0.72); text-align:left; }'
                    . "\n" . '#<?= $componentId ?> .ai-site-callout { padding:18px 20px; border-radius:18px; background:color-mix(in srgb, var(--section-primary) 10%, white); color:var(--section-heading); text-align:left; }',
                'css_responsive' => '#<?= $componentId ?> .ai-site-card-grid { grid-template-columns:1fr; }',
                'html_content' => '<div class="ai-site-card-grid">'
                    . '<article class="ai-site-card"><strong><?= htmlspecialchars($getConfig("content.title", "Section")) ?></strong><p>' . \htmlspecialchars($summary, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p></article>'
                    . '<article class="ai-site-card"><strong>AI</strong><p>Test environment uses deterministic section markup so build and publish flows stay stable.</p></article>'
                    . '<article class="ai-site-card"><strong>Prompt</strong><p>' . \htmlspecialchars($summary, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p></article>'
                    . '</div>'
                    . '<div class="ai-site-callout"><p><?= nl2br(htmlspecialchars($getConfig("content.description", ""))) ?></p></div>',
                'js_content' => '',
            ],
        };
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizeComponentPayload(array $data): array
    {
        $fieldMappings = [
            'extra_fields' => ['extra_fields', 'extraFields', 'fields'],
            'php_variables' => ['php_variables', 'phpVariables', 'php_vars', 'phpVars'],
            'css_extra' => ['css_extra', 'cssExtra', 'css', 'css_content', 'cssContent'],
            'css_responsive' => ['css_responsive', 'cssResponsive', 'responsive_css', 'responsiveCss'],
            'html_content' => ['html_content', 'htmlContent', 'html', 'content'],
            'html_extra' => ['html_extra', 'htmlExtra'],
            'html_extra_column' => ['html_extra_column', 'htmlExtraColumn'],
            'footer_extra_text' => ['footer_extra_text', 'footerExtraText'],
            'js_content' => ['js_content', 'jsContent', 'js', 'javascript'],
        ];

        $normalized = [];
        foreach ($fieldMappings as $normalizedKey => $possibleKeys) {
            foreach ($possibleKeys as $key) {
                if (!isset($data[$key]) || $data[$key] === null) {
                    continue;
                }
                $normalized[$normalizedKey] = $data[$key];
                break;
            }
        }

        return $normalized ?: $data;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function buildRenderContext(string $pageType, array $websiteProfile, array $scope, array $defaultConfig): array
    {
        $blogContext = $this->buildBlogRenderContext($scope, $pageType);
        $styleSettings = [];

        return \array_merge([
            'page' => $this->buildPreviewPageStub($pageType, $websiteProfile, $scope, $blogContext),
            'style_settings' => $styleSettings,
            'style' => $styleSettings,
            'component_config' => $defaultConfig,
            'is_preview' => true,
        ], $blogContext);
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $blogContext
     */
    private function buildPreviewPageStub(string $pageType, array $websiteProfile, array $scope, array $blogContext): PreviewPageStub
    {
        $navigationPages = $this->buildNavigationPages($scope);
        $headerNavigationPages = $this->buildHeaderNavigationPages($scope);
        $siteTitle = $this->getPageBlueprintService()->resolveSiteDisplayName($websiteProfile, $scope);

        return new PreviewPageStub([
            'website_id' => (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? 0),
            'type' => $pageType,
            'title' => (string)($websiteProfile['site_title'] ?? $siteTitle),
            'meta_title' => (string)($websiteProfile['site_title'] ?? $siteTitle),
            'logo' => (string)($websiteProfile['logo'] ?? ''),
            'icon' => (string)($websiteProfile['icon'] ?? $websiteProfile['favicon'] ?? ''),
            'header_navigation_pages' => $headerNavigationPages,
            'navigation_pages' => $navigationPages,
            'blog_posts' => $blogContext['blog_posts'] ?? [],
            'blog_categories' => $blogContext['blog_categories'] ?? [],
            'home_page_config' => [
                'style' => 'default',
                'style_setting' => [],
                'layout_config' => [],
                'logo' => (string)($websiteProfile['logo'] ?? ''),
                'icon' => (string)($websiteProfile['icon'] ?? $websiteProfile['favicon'] ?? ''),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function buildNavigationPages(array $scope): array
    {
        $pageTypes = $this->resolveScopedPageTypes($scope);
        $labels = Page::getPageTypes();
        $items = [];

        foreach ($pageTypes as $index => $pageType) {
            if ($pageType === Page::TYPE_BLOG || $pageType === Page::TYPE_BLOG_CATEGORY) {
                continue;
            }

            $handle = Page::getDefaultHandleForType($pageType);
            $items[] = [
                'title' => (string)($labels[$pageType] ?? $pageType),
                'handle' => $handle,
                'url' => $pageType === Page::TYPE_HOME ? '/' : '/' . $handle,
                'type' => $pageType,
                'page_id' => $index + 1,
            ];
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function buildHeaderNavigationPages(array $scope): array
    {
        $navigationPages = $this->buildNavigationPages($scope);
        $byType = [];

        foreach ($navigationPages as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            if ($type !== '') {
                $byType[$type] = $item;
            }
        }

        $items = [];
        foreach ([Page::TYPE_HOME, Page::TYPE_ABOUT] as $type) {
            if (isset($byType[$type])) {
                $items[] = $byType[$type];
            }
        }

        foreach ([Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY, Page::TYPE_COOKIE_POLICY] as $type) {
            if (!isset($byType[$type])) {
                continue;
            }
            $items[] = [
                'title' => 'Policy Info',
                'handle' => (string)($byType[$type]['handle'] ?? ''),
                'url' => (string)($byType[$type]['url'] ?? '#'),
                'type' => 'policy_info',
                'page_id' => (int)($byType[$type]['page_id'] ?? 0),
            ];
            break;
        }

        foreach ([Page::TYPE_BLOG_LIST, Page::TYPE_CONTACT] as $type) {
            if (isset($byType[$type])) {
                $items[] = $byType[$type];
            }
        }

        $existingTypes = \array_flip(\array_map(
            static fn(array $entry): string => (string)($entry['type'] ?? ''),
            $items
        ));
        foreach ($navigationPages as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            if ($type === '' || isset($existingTypes[$type])) {
                continue;
            }
            $items[] = $item;
            $existingTypes[$type] = true;
            if (\count($items) >= 5) {
                break;
            }
        }

        return $items !== [] ? $items : $navigationPages;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildBlogRenderContext(array $scope, string $pageType): array
    {
        $websiteId = (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? 0);
        if ($websiteId <= 0 && !\in_array($pageType, [Page::TYPE_BLOG, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG_LIST], true)) {
            return [];
        }

        $page = clone $this->getPageModel();
        $page->clearData()->clearQuery();
        $page->setData(Page::schema_fields_WEBSITE_ID, $websiteId);
        $page->setData(Page::schema_fields_TYPE, $pageType);

        $blogPosts = $page->getBlogPosts(20, 'published_at', 'DESC');
        $blogCategories = $page->getBlogCategories();
        $currentPost = \is_array($blogPosts[0] ?? null) ? $blogPosts[0] : [];
        $currentCategory = \is_array($blogCategories[0] ?? null) ? $blogCategories[0] : [];
        $relatedPosts = \array_values(\array_slice($blogPosts, 1, 6));
        $categoryPosts = $this->filterBlogPostsByCategory($blogPosts, (int)($currentCategory['category_id'] ?? $currentCategory['id'] ?? 0));

        return [
            'blog_posts' => $blogPosts,
            'blog_categories' => $blogCategories,
            'recent_posts' => \array_values(\array_slice($blogPosts, 0, 10)),
            'related_posts' => $relatedPosts,
            'current_post' => $currentPost,
            'current_category' => $currentCategory,
            'category_posts' => $categoryPosts,
        ];
    }

    /**
     * @param list<array<string,mixed>> $blogPosts
     * @return list<array<string,mixed>>
     */
    private function filterBlogPostsByCategory(array $blogPosts, int $categoryId): array
    {
        if ($categoryId <= 0) {
            return $blogPosts;
        }

        return \array_values(\array_filter(
            $blogPosts,
            static fn(array $post): bool => (int)($post['category_id'] ?? 0) === $categoryId
        ));
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<string>
     */
    private function resolveScopedPageTypes(array $scope): array
    {
        $scopeCompatibilityService = $this->scopeCompatibilityService;
        if ($scopeCompatibilityService === null) {
            $scopeCompatibilityService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        }

        return $scopeCompatibilityService->resolveScopedPageTypes($scope);
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $headerConfig
     */
    private function buildHeaderGenerationPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $headerConfig): string
    {
        $siteSummary = $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope);
        $pageTypes = $this->resolveScopedPageTypes($scope);
        $pageTypeLabels = Page::getPageTypes();
        $pageList = [];
        foreach ($pageTypes as $pageType) {
            $pageList[] = (string)($pageTypeLabels[$pageType] ?? $pageType);
        }

        $styleCode = $this->resolvePromptStyleCode($scope, Page::TYPE_HOME);
        $styleDirection = $this->describeStyleDirection($styleCode);

        return "You are generating a PageBuilder website header component.\n"
            . "Site name: {$siteDisplayName}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . "Selected pages: " . \implode(', ', $pageList) . "\n"
            . "Current navigation fallback: " . \json_encode($headerConfig['nav_items'] ?? [], \JSON_UNESCAPED_UNICODE) . "\n"
            . "Rules:\n"
            . "1. Output only one header component, never a full page.\n"
            . "2. The copy must read like finished website copy for visitors.\n"
            . "3. Never expose internal wording such as customer brief, prompt text, page focus, requirements, or 'I want to build'.\n"
            . "4. Navigation must be compatible with real page links; when real page nav exists it should win over fallback items.\n"
            . "5. Keep the structure practical: logo area, navigation, optional CTA, mobile-friendly behavior.\n"
            . "6. Style should be inspired by the reference theme, but do not mention the theme name in visible copy.\n"
            . "7. Return pure JSON only. No markdown. No explanation.\n"
            . "JSON fields: extra_fields, php_variables, css_extra, html_extra, js_content.";
    }

    private function buildFooterGenerationPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $footerConfig): string
    {
        $siteSummary = $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope);
        $styleCode = $this->resolvePromptStyleCode($scope, Page::TYPE_HOME);
        $styleDirection = $this->describeStyleDirection($styleCode);

        return "You are generating a PageBuilder website footer component.\n"
            . "Site name: {$siteDisplayName}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . "Footer link fallback: " . \json_encode([
                'column1' => $footerConfig['links.column1_items'] ?? '',
                'column2' => $footerConfig['links.column2_items'] ?? '',
                'column3' => $footerConfig['links.column3_items'] ?? '',
            ], \JSON_UNESCAPED_UNICODE) . "\n"
            . "Rules:\n"
            . "1. Output only one footer component, never a full page.\n"
            . "2. The copy must read like real customer-facing site copy, not internal notes.\n"
            . "3. Never print customer brief text, prompt instructions, or requirement wording on the page.\n"
            . "4. Keep footer structure practical: brand area, grouped links, support/legal text, optional extra column or subscription area.\n"
            . "5. Footer links should be compatible with real page nav logic and the fallback link groups.\n"
            . "6. Style should follow the reference theme direction without naming the theme in visible text.\n"
            . "7. Return pure JSON only. No markdown. No explanation.\n"
            . "JSON fields: extra_fields, php_variables, css_extra, html_extra_column, html_extra, footer_extra_text, js_content.";
    }

    private function buildSectionGenerationPrompt(string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): string
    {
        $siteSummary = $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope);
        $pageInstructionMap = Page::getPageTypePromptInstructionsMap();
        $pageInstruction = (string)($pageInstructionMap[$pageType] ?? '');
        $sectionName = (string)($section['name'] ?? $section['code'] ?? '');
        $sectionKey = (string)($section['key'] ?? '');
        $sectionTemplate = (string)($section['template'] ?? 'hero');
        $sectionConfig = \json_encode($section['config'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        $refinement = $this->resolveSectionRefinement($scope, $pageType, (string)($section['code'] ?? ''), $sectionKey);
        $blogPrompt = $this->buildBlogPromptAddon($pageType, $sectionKey, $scope);
        $styleCode = $this->resolvePromptStyleCode($scope, $pageType);
        $styleDirection = $this->describeStyleDirection($styleCode);

        return "You are generating a PageBuilder content component.\n"
            . "Page type: " . (string)($blueprint['page_label'] ?? $pageType) . " ({$pageType})\n"
            . "Section name: {$sectionName}\n"
            . "Section role: {$sectionKey}\n"
            . "Suggested structure: {$sectionTemplate}\n"
            . "Visitor-facing brand summary: {$siteSummary}\n"
            . "Page guidance: {$pageInstruction}\n"
            . "Suggested section config: {$sectionConfig}\n"
            . "Style reference: {$styleCode} ({$styleDirection})\n"
            . ($refinement !== '' ? "Latest refine instruction for this section: {$refinement}\n" : '')
            . ($blogPrompt !== '' ? $blogPrompt . "\n" : '')
            . "Rules:\n"
            . "1. Output only one content component, never a full page document.\n"
            . "2. Write finished visitor-facing copy. Do not expose internal prompts, briefs, requirement wording, or phrases such as 'page focus' and 'site summary'.\n"
            . "3. The section must be meaningfully different for its page type and role; home, about, contact, policy, and blog sections should not read the same.\n"
            . "4. Use the style reference as visual/tone inspiration, but do not mention the style name in visible text.\n"
            . "5. Return pure JSON only. No markdown. No explanation.\n"
            . "6. JSON fields: extra_fields, php_variables, css_extra, css_responsive, html_content, js_content.\n"
            . "7. If real blog data variables are provided, prefer them over invented articles or categories.";
    }

    private function resolvePromptStyleCode(array $scope, string $pageType): string
    {
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $styleCode = \trim((string)($virtualPage['style_code'] ?? $scope['style_code'] ?? 'default'));

        return $styleCode !== '' ? $styleCode : 'default';
    }

    private function describeStyleDirection(string $styleCode): string
    {
        return match ($styleCode) {
            'fintech-hub' => 'clean, data-driven, premium, trustworthy, high-contrast calls to action',
            'saas-starter' => 'modern product marketing, concise, structured, conversion-oriented',
            'fitness-pro' => 'energetic, bold, motivating, performance-focused',
            'sattaking', 'poker-arena', 'ludo-empire', 'rummy-royal' => 'high-energy gaming style, vivid contrast, strong CTA rhythm',
            'tpmst' => 'practical, service-focused, trustworthy, content-forward',
            default => 'clean editorial structure, clear hierarchy, practical CTA emphasis',
        };
    }

    private function buildHeaderPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $headerConfig): string
    {
        $brief = $this->pickString($websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, $scope['user_description'] ?? null);
        $pageTypes = $this->resolveScopedPageTypes($scope);
        $pageTypeLabels = Page::getPageTypes();
        $pageList = [];
        foreach ($pageTypes as $pageType) {
            $pageList[] = (string)($pageTypeLabels[$pageType] ?? $pageType);
        }

        return "你正在为 PageBuilder AI 建站工作台生成一个网站页头 header 组件。\n"
            . "站点名称：{$siteDisplayName}\n"
            . "客户一句话需求：{$brief}\n"
            . "站点需要承载的页面：" . \implode('、', $pageList) . "\n"
            . "要求：\n"
            . "1. 这是常规网站页头，不要输出整页，只生成 header 组件增强部分。\n"
            . "2. 导航必须服务于真实页面导航，不能写死伪造菜单；当前组件会优先读取真实页面导航，没有时回退到配置中的导航项。\n"
            . "3. 允许输出 css_extra / html_extra / js_content，重点体现品牌气质、吸顶、滚动、移动端菜单等体验。\n"
            . "4. 文案必须贴合客户一句话需求，避免空泛模板句。\n"
            . "5. 返回纯 JSON 对象，不要 markdown，不要解释。\n"
            . "JSON 字段：extra_fields, php_variables, css_extra, html_extra, js_content。\n"
            . "当前导航回退项：" . \json_encode($headerConfig['nav_items'] ?? [], \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $footerConfig
     */
    private function buildFooterPrompt(array $websiteProfile, array $scope, string $siteDisplayName, array $footerConfig): string
    {
        $brief = $this->pickString($websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, $scope['user_description'] ?? null);

        return "你正在为 PageBuilder AI 建站工作台生成一个网站页脚 footer 组件。\n"
            . "站点名称：{$siteDisplayName}\n"
            . "客户一句话需求：{$brief}\n"
            . "要求：\n"
            . "1. 这是常规网站页脚，不要输出整页，只生成 footer 组件增强部分。\n"
            . "2. 页脚链接需要兼容真实页面 link/nav 逻辑，没有真实页面时回退到配置中的链接列。\n"
            . "3. 可以生成品牌区、资源链接、补充列、订阅区、声明文案，但必须保持常规网站 footer 结构。\n"
            . "4. 文案与气质必须贴合客户一句话需求。\n"
            . "5. 返回纯 JSON 对象，不要 markdown，不要解释。\n"
            . "JSON 字段：extra_fields, php_variables, css_extra, html_extra_column, html_extra, footer_extra_text, js_content。\n"
            . "当前页脚回退配置：" . \json_encode([
                'column1' => $footerConfig['links.column1_items'] ?? '',
                'column2' => $footerConfig['links.column2_items'] ?? '',
            ], \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     */
    private function buildSectionPrompt(string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): string
    {
        $brief = $this->pickString($websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, $scope['user_description'] ?? null);
        $pageInstructionMap = Page::getPageTypePromptInstructionsMap();
        $pageInstruction = (string)($pageInstructionMap[$pageType] ?? '');
        $sectionName = (string)($section['name'] ?? $section['code'] ?? '');
        $sectionKey = (string)($section['key'] ?? '');
        $sectionTemplate = (string)($section['template'] ?? 'hero');
        $sectionConfig = \json_encode($section['config'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        $refinement = $this->resolveSectionRefinement($scope, $pageType, (string)($section['code'] ?? ''), $sectionKey);
        $blogPrompt = $this->buildBlogPromptAddon($pageType, $sectionKey, $scope);

        return "你正在为 PageBuilder AI 建站工作台生成一个内容区块 content 组件。\n"
            . "页面类型：" . (string)($blueprint['page_label'] ?? $pageType) . " ({$pageType})\n"
            . "区块名称：{$sectionName}\n"
            . "区块角色：{$sectionKey}\n"
            . "建议结构类型：{$sectionTemplate}\n"
            . "客户一句话需求：{$brief}\n"
            . "页面生成说明：{$pageInstruction}\n"
            . "当前区块建议配置：{$sectionConfig}\n"
            . ($refinement !== '' ? "用户对当前区块的额外微调要求：{$refinement}\n" : '')
            . ($blogPrompt !== '' ? $blogPrompt . "\n" : '')
            . "要求：\n"
            . "1. 只生成一个 content 组件，不要输出整页 document。\n"
            . "2. 组件必须围绕这个区块角色来写，文案要严格贴合客户一句话需求，而不是通用模板句。\n"
            . "3. 返回纯 JSON 对象，不要 markdown，不要解释。\n"
            . "4. JSON 字段：extra_fields, php_variables, css_extra, css_responsive, html_content, js_content。\n"
            . "5. 如果是博客页面并且提示里给出了真实数据变量，必须优先使用真实数据变量，不要伪造文章或分类。";
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function buildBlogPromptAddon(string $pageType, string $sectionKey, array $scope): string
    {
        if (!\in_array($pageType, [Page::TYPE_BLOG, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG_LIST], true)) {
            return '';
        }

        $blogContext = $this->buildBlogRenderContext($scope, $pageType);
        $postPreview = \array_map(static function (array $post): array {
            return [
                'title' => (string)($post['title'] ?? ''),
                'category_name' => (string)($post['category_name'] ?? ''),
                'url' => (string)($post['url'] ?? ''),
            ];
        }, \array_slice((array)($blogContext['blog_posts'] ?? []), 0, 5));
        $categoryPreview = \array_map(static function (array $category): array {
            return [
                'name' => (string)($category['name'] ?? ''),
                'url' => (string)($category['url'] ?? ''),
            ];
        }, \array_slice((array)($blogContext['blog_categories'] ?? []), 0, 5));

        $roleHint = match ($sectionKey) {
            'hero' => '这一屏更适合做博客页说明、栏目定位和阅读引导。',
            'highlights' => '这一屏更适合直接渲染真实文章列表或分类卡片。',
            'details' => '这一屏更适合做分类导航、阅读路径、近期文章或当前文章补充信息。',
            default => '这一屏请结合博客真实数据来组织内容。',
        };

        return "博客页面真实数据要求：\n"
            . "- 可用变量：\$blog_posts, \$blog_categories, \$recent_posts, \$related_posts, \$current_post, \$current_category, \$category_posts。\n"
            . "- {$roleHint}\n"
            . "- 示例文章数据：" . \json_encode($postPreview, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- 示例分类数据：" . \json_encode($categoryPreview, \JSON_UNESCAPED_UNICODE) . "\n"
            . "- 请使用 foreach ((\$blog_posts ?? []) as \$post) 或同类真实数据循环，不要手写假文章。";
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildHeaderDefaultConfig(array $websiteProfile, array $scope, string $siteDisplayName): array
    {
        $navItems = $this->buildHeaderNavigationPages($scope);
        $navTextLines = [];
        foreach ($navItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $navTextLines[] = \trim((string)($item['title'] ?? '')) . '=>' . \trim((string)($item['url'] ?? '#'));
        }

        return [
            'logo.display' => 'yes',
            'logo.text' => $siteDisplayName,
            'logo.image' => (string)($websiteProfile['logo'] ?? ''),
            'navigation.display' => 'yes',
            'navigation.items' => \implode("\n", $navTextLines),
            'nav_items' => \array_map(static fn(array $item): array => [
                'text' => (string)($item['title'] ?? ''),
                'href' => (string)($item['url'] ?? '#'),
            ], $navItems),
            'cta.show' => 'yes',
            'cta.text' => $this->resolvePrimaryCtaText($scope),
            'cta.url' => '#contact',
        ];
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildFooterDefaultConfig(array $websiteProfile, array $scope, string $siteDisplayName): array
    {
        $navigationPages = $this->buildNavigationPages($scope);
        $brandSummary = $this->getPageBlueprintService()->buildSiteMarketingSummary($websiteProfile, $scope);
        $legalLines = [];
        $featuredLines = [];
        $allLines = [];
        $featuredTypeMap = \array_flip([
            Page::TYPE_HOME,
            Page::TYPE_ABOUT,
            Page::TYPE_CONTACT,
            Page::TYPE_BLOG_LIST,
            Page::TYPE_CUSTOM,
        ]);

        foreach ($navigationPages as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            $line = \trim((string)($item['title'] ?? '')) . '=>' . \trim((string)($item['url'] ?? '#'));
            $allLines[] = $line;
            if (\in_array($type, [Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_PRIVACY_POLICY, Page::TYPE_COOKIE_POLICY, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY], true)) {
                $legalLines[] = $line;
            }
            if (isset($featuredTypeMap[$type])) {
                $featuredLines[] = $line;
            }
        }

        if ($featuredLines === []) {
            $featuredLines = \array_slice($allLines, 0, 4);
        }
        if ($legalLines === []) {
            $legalLines = \array_slice($allLines, 1, 3);
        }

        return [
            'brand.name' => $siteDisplayName,
            'brand.logo' => (string)($websiteProfile['logo'] ?? ''),
            'brand.description' => $brandSummary,
            'links.column1_title' => 'Featured Pages',
            'links.column1_items' => \implode("\n", $featuredLines),
            'links.column2_title' => 'Policy Info',
            'links.column2_items' => \implode("\n", $legalLines),
            'links.column3_title' => 'All Pages',
            'links.column3_items' => \implode("\n", $allLines),
            'copyright.text' => 'All rights reserved.',
            'copyright.year' => \date('Y'),
        ];
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildSectionDefaultConfig(string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array
    {
        $sectionConfig = \is_array($section['config'] ?? null) ? $section['config'] : [];

        $title = $this->pickString(
            $sectionConfig['section_title'] ?? null,
            $sectionConfig['headline'] ?? null,
            $blueprint['page_title'] ?? null,
            (string)($section['name'] ?? '')
        );
        $subtitle = $this->pickString(
            $sectionConfig['eyebrow'] ?? null,
            $blueprint['page_label'] ?? null
        );
        $description = $this->pickString(
            $sectionConfig['section_intro'] ?? null,
            $sectionConfig['description'] ?? null,
            $sectionConfig['section_text'] ?? null,
            $blueprint['ai_description'] ?? null
        );

        $bgType = 'color';
        $bgColor = '#ffffff';
        if ((string)($section['template'] ?? '') === 'hero') {
            $bgType = 'gradient';
        } elseif ((string)($section['template'] ?? '') === 'cta') {
            $bgColor = '#0f172a';
        }

        return [
            'content.title' => $title,
            'content.subtitle' => $subtitle,
            'content.description' => $description,
            'layout.container_width' => '1200',
            'layout.padding_top' => (string)(((string)($section['template'] ?? '') === 'hero') ? 96 : 72),
            'layout.padding_bottom' => (string)(((string)($section['template'] ?? '') === 'cta') ? 96 : 72),
            'layout.text_align' => ((string)($section['template'] ?? '') === 'checklist') ? 'left' : 'center',
            'style.bg_type' => $bgType,
            'style.bg_color' => $bgColor,
            'style.text_color' => ((string)($section['template'] ?? '') === 'cta') ? '#e2e8f0' : '#334155',
            'style.title_color' => ((string)($section['template'] ?? '') === 'cta') ? '#ffffff' : '#0f172a',
            'style.accent_color' => '#2563eb',
        ];
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveSectionRefinement(array $scope, string $pageType, string $sectionCode, string $fallbackKey): string
    {
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $refinements = \is_array($virtualPage['section_refinements'] ?? null) ? $virtualPage['section_refinements'] : [];

        if ($sectionCode !== '' && \is_scalar($refinements[$sectionCode] ?? null)) {
            return \trim((string)$refinements[$sectionCode]);
        }
        if ($fallbackKey !== '' && \is_scalar($refinements[$fallbackKey] ?? null)) {
            return \trim((string)$refinements[$fallbackKey]);
        }

        return '';
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolvePrimaryCtaText(array $scope): string
    {
        $pageTypes = $this->resolveScopedPageTypes($scope);
        if (\in_array(Page::TYPE_CONTACT, $pageTypes, true)) {
            return 'Contact Us';
        }
        if (\in_array(Page::TYPE_BLOG_LIST, $pageTypes, true)) {
            return 'Explore More';
        }

        return 'Get Started';
    }

    private function pickString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $candidate = \trim((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function clipText(string $value, int $limit): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\function_exists('mb_strlen') && \function_exists('mb_substr')) {
            if (\mb_strlen($value) <= $limit) {
                return $value;
            }

            return \rtrim(\mb_substr($value, 0, \max(1, $limit - 3))) . '...';
        }

        if (\strlen($value) <= $limit) {
            return $value;
        }

        return \rtrim(\substr($value, 0, \max(1, $limit - 3))) . '...';
    }

    private function isTestEnvironment(): bool
    {
        return (\defined('ENV_TEST') && ENV_TEST === true)
            || \defined('PHPUNIT_COMPOSER_INSTALL')
            || \defined('__PHPUNIT_PHAR__');
    }

    private function getFrameworkBuilder(): FrameworkBuilder
    {
        return $this->frameworkBuilder ?? ObjectManager::getInstance(FrameworkBuilder::class);
    }

    private function getResponseJsonParser(): AiResponseJsonParser
    {
        return $this->responseJsonParser ?? ObjectManager::getInstance(AiResponseJsonParser::class);
    }

    private function getCodeFixer(): CodeFixer
    {
        return $this->codeFixer ?? ObjectManager::getInstance(CodeFixer::class);
    }

    private function getCodeValidator(): CodeValidator
    {
        return $this->codeValidator ?? ObjectManager::getInstance(CodeValidator::class);
    }

    private function getAiService(): AiService
    {
        return $this->aiService ?? ObjectManager::getInstance(AiService::class);
    }

    private function getPageBlueprintService(): AiSitePageBlueprintService
    {
        return $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
    }

    private function getPageModel(): Page
    {
        return $this->pageModel ?? ObjectManager::getInstance(Page::class);
    }
}
