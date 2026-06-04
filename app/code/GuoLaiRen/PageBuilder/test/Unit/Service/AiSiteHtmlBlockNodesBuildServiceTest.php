<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteHtmlBlockNodesBuildService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

class AiSiteHtmlBlockNodesBuildServiceTest extends TestCase
{
    public function testBuildSharedHeaderAndFooterUseStructuredNavAndGroupedFooterLinks(): void
    {
        $service = new AiSiteHtmlBlockNodesBuildService(new AiSitePageBlueprintService());

        $websiteProfile = [
            'site_title' => 'West Sydney Support Hub',
            'site_tagline' => 'Local support and clear policy information',
            'brief_description' => 'A West Sydney customer website with about, policy information, blog, and contact guidance.',
            'target_domain' => 'westsydney.example.test',
        ];
        $scope = [
            'site_title' => 'West Sydney Support Hub',
            'brief_description' => 'A West Sydney customer website with about, policy information, blog, and contact guidance.',
            'page_types' => [
                Page::TYPE_HOME,
                Page::TYPE_ABOUT,
                Page::TYPE_PRIVACY_POLICY,
                Page::TYPE_TERMS_OF_SERVICE,
                Page::TYPE_BLOG_LIST,
                Page::TYPE_CONTACT,
            ],
        ];

        $headerBlock = $service->buildSharedHeaderBlock(Page::TYPE_HOME, $websiteProfile, $scope);
        $footerBlock = $service->buildSharedFooterBlock(Page::TYPE_HOME, $websiteProfile, $scope);

        self::assertSame('site_header', (string)($headerBlock['type'] ?? ''));
        self::assertSame('site_footer', (string)($footerBlock['type'] ?? ''));

        $headerNav = $headerBlock['config']['nav_items'] ?? [];
        self::assertIsArray($headerNav);
        self::assertLessThanOrEqual(6, \count($headerNav));
        $headerHrefs = \array_column($headerNav, 'href');
        self::assertTrue(
            \in_array('/privacy', $headerHrefs, true)
            || \in_array('/terms', $headerHrefs, true)
            || \in_array('/refund', $headerHrefs, true)
            || \in_array('/shipping', $headerHrefs, true)
            || \in_array('/cookies', $headerHrefs, true),
            'Header nav should surface at least one policy-related page.'
        );

        $footerConfig = \is_array($footerBlock['config'] ?? null) ? $footerBlock['config'] : [];
        self::assertSame('Featured Pages', (string)($footerConfig['links.column1_title'] ?? ''));
        self::assertSame('Policy Info', (string)($footerConfig['links.column2_title'] ?? ''));
        self::assertSame('All Pages', (string)($footerConfig['links.column3_title'] ?? ''));
        self::assertNotEmpty($footerConfig['links.column1_items'] ?? []);
        self::assertNotEmpty($footerConfig['links.column2_items'] ?? []);
        self::assertGreaterThanOrEqual(5, \count($footerConfig['links.column3_items'] ?? []));
        self::assertStringContainsString('All Pages', (string)($footerBlock['html'] ?? ''));
    }

    public function testBuildSharedHeaderSurfacesCustomPageWhenPolicyAndUtilityPagesExist(): void
    {
        $service = new AiSiteHtmlBlockNodesBuildService(new AiSitePageBlueprintService());
        $websiteProfile = [
            'site_title' => 'OpsFlow AI',
            'default_locale' => 'en_US',
            'brief_description' => 'AI workflow operations website.',
        ];
        $scope = [
            'default_locale' => 'en_US',
            'website_profile' => $websiteProfile,
            'page_types' => [
                Page::TYPE_HOME,
                Page::TYPE_ABOUT,
                Page::TYPE_PRIVACY_POLICY,
                Page::TYPE_BLOG_LIST,
                Page::TYPE_CONTACT,
                Page::TYPE_CUSTOM,
            ],
            'virtual_pages_by_type' => [
                Page::TYPE_CUSTOM => ['title' => 'Workflow Audit'],
            ],
        ];

        $headerBlock = $service->buildSharedHeaderBlock(Page::TYPE_HOME, $websiteProfile, $scope);
        $headerNav = $headerBlock['config']['nav_items'] ?? [];

        self::assertIsArray($headerNav);
        self::assertLessThanOrEqual(6, \count($headerNav));
        self::assertContains('/page', \array_column($headerNav, 'href'));
        self::assertContains('Workflow Audit', \array_column($headerNav, 'label'));
    }

    public function testBuildGeneratedSharedBlockParsesSchemaFromGeneratedPhtml(): void
    {
        $service = new AiSiteHtmlBlockNodesBuildService(new AiSitePageBlueprintService());

        $block = $service->buildGeneratedSharedBlock('header', Page::TYPE_HOME, [
            'code' => 'header/ai-site-header',
            'name' => 'AI Site Header',
            'region' => 'header',
            'html' => '',
            'phtml' => $this->buildGeneratedHeaderPhtml(),
            'default_config' => [
                'logo.text' => 'Acme Studio',
                'navigation.items' => "Home=>/\nDocs=>/docs",
                'nav_items' => [
                    ['label' => 'Home', 'href' => '/', 'active' => true],
                    ['label' => 'Docs', 'href' => '/docs', 'active' => false],
                ],
            ],
        ]);

        $schema = \is_array($block['field_schema'] ?? null) ? $block['field_schema'] : [];
        self::assertArrayHasKey('logo', $schema);
        self::assertArrayHasKey('logo.text', $schema['logo']['fields'] ?? []);
        self::assertArrayHasKey('navigation', $schema);
        self::assertSame('nav-lines', $schema['navigation']['fields']['navigation.items']['format'] ?? null);
    }

    public function testPlaceholderBlocksContainOnlyPageContentBlocks(): void
    {
        $pageGenerator = new class extends AiSitePageComponentGenerationService {
            public function generatePageSections(string $pageType, array $websiteProfile, array $scope): array
            {
                return [
                    'sections' => [
                        [
                            'code' => 'content/blog-hero-header',
                            'name' => 'Blog Hero Header',
                            'html' => '<section><h1>Blog</h1></section>',
                            'phtml' => '',
                            'default_config' => ['region' => 'content'],
                        ],
                    ],
                ];
            }
        };
        $service = new AiSiteHtmlBlockNodesBuildService(new AiSitePageBlueprintService(), $pageGenerator);

        $blocks = $service->buildPlaceholderBlocksForPageType(Page::TYPE_BLOG_LIST, [
            'site_title' => 'Demo Site',
            'brief_description' => 'Demo summary',
        ]);

        self::assertCount(1, $blocks);
        self::assertSame('blog-hero-header', (string)($blocks[0]['block_id'] ?? ''));
        self::assertSame('ai_generated_section', (string)($blocks[0]['type'] ?? ''));
        self::assertFalse(AiSiteHtmlBlockNodesBuildService::isSharedLayoutBlock($blocks[0]));
        self::assertSame('', $service->resolveSharedBlockRegion($blocks[0]));
    }

    public function testSharedLayoutBlockDetectionDoesNotMatchContentHeaderNames(): void
    {
        $service = new AiSiteHtmlBlockNodesBuildService(new AiSitePageBlueprintService());

        self::assertFalse(AiSiteHtmlBlockNodesBuildService::isSharedLayoutBlock([
            'block_id' => 'blog-list-blog-hero-header',
            'type' => 'ai_generated_section',
            'html' => '<section>Blog</section>',
            'config' => ['region' => 'content'],
        ]));
        self::assertSame('', $service->resolveSharedBlockRegion([
            'block_id' => 'blog-list-blog-hero-header',
            'type' => 'ai_generated_section',
            'config' => ['region' => 'content'],
        ]));
        self::assertSame('', $service->resolveSharedBlockRegion([
            'block_id' => 'content-product-site-header',
            'type' => 'ai_generated_section',
            'config' => ['region' => 'content'],
        ]));

        self::assertTrue(AiSiteHtmlBlockNodesBuildService::isSharedLayoutBlock([
            'block_id' => 'header-ai-site-header',
            'type' => 'ai_generated_shared_header',
            'html' => '<header>Header</header>',
            'config' => ['region' => 'header'],
        ]));
        self::assertSame('footer', $service->resolveSharedBlockRegion([
            'block_id' => 'footer-ai-site-footer',
            'type' => 'ai_generated_shared_footer',
            'config' => ['region' => 'footer'],
        ]));
    }

    public function testRebuildGeneratedSharedHeaderUsesEditableConfigAndPreservesSharedNavigation(): void
    {
        $service = new AiSiteHtmlBlockNodesBuildService(new AiSitePageBlueprintService());

        $original = $service->buildGeneratedSharedBlock('header', Page::TYPE_HOME, [
            'code' => 'header/ai-site-header',
            'name' => 'AI Site Header',
            'region' => 'header',
            'html' => '',
            'phtml' => $this->buildGeneratedHeaderPhtml(),
            'default_config' => [
                'logo.text' => 'Acme Studio',
                'navigation.items' => "Home=>/\nDocs=>/docs",
                'nav_items' => [
                    ['label' => 'Home', 'href' => '/', 'active' => true],
                    ['label' => 'Docs', 'href' => '/docs', 'active' => false],
                ],
            ],
        ]);

        $edited = $service->rebuildBlock($original, [], [], [
            'logo.text' => 'Custom Brand',
            'navigation.items' => "Pricing=>/pricing\nSupport=>/support",
        ]);

        self::assertSame('header', $service->resolveSharedBlockRegion($edited));
        self::assertStringContainsString('Custom Brand', (string)($edited['html'] ?? ''));
        self::assertStringContainsString('/pricing', (string)($edited['html'] ?? ''));
        self::assertSame("Pricing=>/pricing\nSupport=>/support", (string)($edited['config']['navigation.items'] ?? ''));
        self::assertSame('Pricing', (string)($edited['config']['nav_items'][0]['label'] ?? ''));

        $regenerated = $service->buildGeneratedSharedBlock('header', Page::TYPE_HOME, [
            'code' => 'header/ai-site-header',
            'name' => 'AI Site Header',
            'region' => 'header',
            'html' => '',
            'phtml' => $this->buildGeneratedHeaderPhtml(),
            'default_config' => [
                'logo.text' => 'Fresh Default',
                'navigation.items' => "Home=>/\nAbout=>/about",
                'nav_items' => [
                    ['label' => 'Home', 'href' => '/', 'active' => true],
                    ['label' => 'About', 'href' => '/about', 'active' => false],
                ],
            ],
        ]);

        $merged = $service->mergeUserCustomizedSharedBlockConfig($regenerated, $edited);
        self::assertSame("Pricing=>/pricing\nSupport=>/support", (string)($merged['config']['navigation.items'] ?? ''));
        self::assertSame('Pricing', (string)($merged['config']['nav_items'][0]['label'] ?? ''));
        self::assertStringContainsString('/support', (string)($merged['html'] ?? ''));
    }

    public function testHydrateGeneratedBlockMetadataFallsBackToConfigKeys(): void
    {
        $service = new AiSiteHtmlBlockNodesBuildService(new AiSitePageBlueprintService());

        $hydrated = $service->hydrateGeneratedBlockMetadata([
            'block_id' => 'header-ai-site-header',
            'type' => 'ai_generated_shared_header',
            'html' => '<header></header>',
            'config' => [
                'logo.text' => 'Fallback Brand',
                'navigation.items' => "Home=>/\nContact=>/contact",
            ],
            'field_schema' => [],
        ]);

        $schema = \is_array($hydrated['field_schema'] ?? null) ? $hydrated['field_schema'] : [];
        self::assertArrayHasKey('logo', $schema);
        self::assertArrayHasKey('logo.text', $schema['logo']['fields'] ?? []);
        self::assertSame('nav-lines', $schema['navigation']['fields']['navigation.items']['format'] ?? null);
    }

    public function testBuildSharedHeaderAndFooterLocalizeFallbackNavigationForEnglishLocale(): void
    {
        $service = new AiSiteHtmlBlockNodesBuildService(new AiSitePageBlueprintService());

        $websiteProfile = [
            'site_title' => 'Teenipiya',
            'default_locale' => 'en_US',
            'brief_description' => 'Card game apk platform for Indian players.',
        ];
        $scope = [
            'default_locale' => 'en_US',
            'website_profile' => $websiteProfile,
            'page_types' => [
                Page::TYPE_HOME,
                Page::TYPE_ABOUT,
                Page::TYPE_TERMS_OF_SERVICE,
                Page::TYPE_CONTACT,
            ],
            'virtual_pages_by_type' => [
                Page::TYPE_ABOUT => ['title' => '鍏充簬鎴戜滑'],
                Page::TYPE_TERMS_OF_SERVICE => ['title' => '鏈嶅姟鏉℃'],
                Page::TYPE_CONTACT => ['title' => '鑱旂郴鎴戜滑'],
            ],
        ];

        $headerBlock = $service->buildSharedHeaderBlock(Page::TYPE_HOME, $websiteProfile, $scope);
        $footerBlock = $service->buildSharedFooterBlock(Page::TYPE_HOME, $websiteProfile, $scope);

        self::assertSame('Home', (string)($headerBlock['config']['nav_items'][0]['label'] ?? ''));
        self::assertContains('About', \array_column($headerBlock['config']['nav_items'] ?? [], 'label'));
        self::assertContains('Contact', \array_column($headerBlock['config']['nav_items'] ?? [], 'label'));
        self::assertSame('Policy Info', (string)($footerBlock['config']['links.column2_title'] ?? ''));
        self::assertStringContainsString('About', (string)($footerBlock['html'] ?? ''));
        self::assertStringNotContainsString('鍏充簬鎴戜滑', (string)($footerBlock['html'] ?? ''));
    }

    private function buildGeneratedHeaderPhtml(): string
    {
        return <<<'PHTML'
<?php
/**
 * @fields_start
 * group:logo => Logo
 * logo.text => Logo Text:text:Brand Name
 * group:navigation => Navigation
 * navigation.items => Navigation Items:textarea:Home=>/\nDocs=>/docs
 * @fields_end
 */
$componentConfig = $this->getData('component_config') ?: [];
$logoText = (string)($componentConfig['logo.text'] ?? '');
$navItems = is_array($componentConfig['nav_items'] ?? null) ? $componentConfig['nav_items'] : [];
?>
<header>
    <strong><?= htmlspecialchars($logoText, ENT_QUOTES, 'UTF-8') ?></strong>
    <?php foreach ($navItems as $item): ?>
        <a href="<?= htmlspecialchars((string)($item['href'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</header>
PHTML;
    }
}
