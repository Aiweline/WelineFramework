<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Interface\SeoProfileProviderInterface;
use Weline\Seo\Interface\SeoSlotProviderInterface;
use Weline\Seo\Service\Head\HeadRenderer;
use Weline\Seo\Service\Head\HeadProviderRegistry;
use Weline\Seo\Service\Head\PageSeoContextResolver;
use Weline\Seo\Service\Head\SeoSlotProviderRegistry;
use Weline\Seo\Structure\SeoStructureRegistry;

class HeadRendererSeoProfileTest extends TestCase
{
    public function testRendersNewsArticleProfileGraph(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'news_article',
            'site_name' => 'News Shop',
            'title' => 'Launch News',
            'description' => 'A new launch.',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/blog/launch-news',
            'url' => 'https://shop.test/blog/launch-news',
            'image' => 'https://shop.test/media/news.jpg',
            'image_alt' => 'Launch News share preview',
            'locale' => 'zh_Hans_CN',
            'sitemap_url' => '/sitemap.xml',
            'alternates' => ['x-default' => 'https://shop.test/', 'zh-CN' => 'https://shop.test/blog/launch-news'],
            'feeds' => [
                [
                    'type' => 'application/rss+xml',
                    'title' => 'Blog RSS',
                    'href' => 'https://shop.test/blog/rss.xml',
                ],
            ],
            'organization' => ['name' => 'News Shop', 'url' => 'https://shop.test/', 'logo' => 'https://shop.test/logo.png'],
            'article' => [
                'headline' => 'Launch News',
                'description' => 'A new launch.',
                'datePublished' => '2026-05-25 10:00:00',
                'dateModified' => '2026-05-26 10:00:00',
                'author_name' => 'Editor',
                'articleSection' => 'Company News',
                'keywords' => ['launch', 'company'],
                'is_news' => true,
            ],
        ]);

        $html = (new HeadRenderer($resolver, new EmptySeoStructureRegistry()))->render(new SeoProfileHeadTemplateStub());

        self::assertStringContainsString('<meta name="robots" content="index,follow">', $html);
        self::assertStringContainsString('<meta name="page-type" content="news-article">', $html);
        self::assertStringContainsString('<meta name="content-category" content="article">', $html);
        self::assertStringContainsString('<link rel="sitemap" type="application/xml" href="/sitemap.xml">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://shop.test/">', $html);
        self::assertStringContainsString(
            '<link rel="alternate" type="application/rss+xml" title="Blog RSS" href="https://shop.test/blog/rss.xml">',
            $html
        );
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<meta property="og:site_name" content="News Shop">', $html);
        self::assertStringContainsString('<meta property="og:image:alt" content="Launch News share preview">', $html);
        self::assertStringContainsString('<meta name="twitter:image:alt" content="Launch News share preview">', $html);
        self::assertStringContainsString('"@type": "NewsArticle"', $html);
        self::assertStringContainsString('"mainEntityOfPage": {', $html);
        self::assertStringContainsString('"articleSection": "Company News"', $html);
        self::assertStringContainsString('"inLanguage": "zh-Hans-CN"', $html);
    }

    public function testRendersBlogListCollectionPageItemListGraph(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'blog_list',
            'site_name' => 'Shop',
            'title' => 'Hanfu Blog',
            'description' => 'Blog listing.',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/blog',
            'url' => 'https://shop.test/blog',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/'],
            'item_list' => [
                [
                    'name' => 'Summer Hanfu Guide',
                    'url' => 'https://shop.test/blog/summer-hanfu',
                    'description' => 'Seasonal tips.',
                    'published_at' => '2026-09-01',
                    'image' => 'https://shop.test/media/blog/summer.webp',
                ],
            ],
        ]);

        $html = (new HeadRenderer($resolver, new EmptySeoStructureRegistry()))->render(new SeoProfileHeadTemplateStub());

        self::assertStringContainsString('"@type": "CollectionPage"', $html);
        self::assertStringContainsString('"@type": "ItemList"', $html);
        self::assertStringContainsString('"@type": "BlogPosting"', $html);
        self::assertStringContainsString('"name": "Summer Hanfu Guide"', $html);
        self::assertStringContainsString('"mainEntity": {', $html);
        self::assertStringContainsString('#itemlist', $html);
    }

    public function testOrganizationAlternateNameIsRendered(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'home',
            'site_name' => '长安汉服',
            'title' => '长安汉服',
            'description' => 'Ink-wash Hanfu boutique.',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/',
            'url' => 'https://shop.test/',
            'organization' => [
                'name' => '长安汉服',
                'url' => 'https://shop.test/',
                'alternateName' => "Chang'an Hanfu",
            ],
        ]);

        $html = (new HeadRenderer($resolver, new EmptySeoStructureRegistry()))->render(new SeoProfileHeadTemplateStub());

        self::assertStringContainsString('"name": "长安汉服"', $html);
        self::assertStringContainsString('"alternateName": "Chang\'an Hanfu"', $html);
    }

    public function testEcommerceListingDoesNotEmitProductItemList(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'tag_collection',
            'site_name' => 'Shop',
            'title' => 'Dresses',
            'description' => 'Dress collection.',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/category/dresses',
            'url' => 'https://shop.test/category/dresses',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/'],
            'item_list' => [
                ['name' => 'Summer Dress', 'url' => 'https://shop.test/product/summer-dress', 'image' => 'https://shop.test/media/dress.jpg'],
            ],
        ]);

        $html = (new HeadRenderer($resolver, new EmptySeoStructureRegistry()))->render(new SeoProfileHeadTemplateStub());

        self::assertStringContainsString('"@type": "WebPage"', $html);
        self::assertStringNotContainsString('"@type": "ItemList"', $html);
        self::assertStringNotContainsString('"@type": "CollectionPage"', $html);
    }

    public function testPolicyPageTypeMapsContentCategoryToLegalAndKeepsWebPageShell(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'policy',
            'site_name' => 'Shop',
            'title' => 'Accessibility Statement',
            'description' => 'Accessibility policy.',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/policy/accessibility',
            'url' => 'https://shop.test/policy/accessibility',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/'],
            'breadcrumbs' => [
                ['name' => 'Home', 'url' => 'https://shop.test/'],
                ['name' => 'Accessibility Statement', 'url' => ''],
            ],
        ]);

        $html = (new HeadRenderer($resolver, new EmptySeoStructureRegistry()))->render(new SeoProfileHeadTemplateStub());

        // 事实层 page-type 保持 policy；归一层 content-category=legal；壳仍为 WebPage
        self::assertStringContainsString('<meta name="page-type" content="policy">', $html);
        self::assertStringContainsString('<meta name="content-category" content="legal">', $html);
        self::assertStringContainsString('"@type": "WebPage"', $html);
        self::assertStringNotContainsString('AccessibilityPage', $html);
        self::assertStringNotContainsString('<meta name="page-type" content="legal">', $html);
    }

    public function testSeoProfileProviderReceivesSlotAndOptionsInContext(): void
    {
        $template = new SeoProfileHeadTemplateStub();
        $template->setData('lang_local', 'en_US');
        $_SERVER['HTTP_HOST'] = 'blog.test';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $provider = new SeoProfileContextProbeProvider();
        $resolver = new PageSeoContextResolver(new SeoProfileProviderRegistryStub([$provider]));

        $context = $resolver->resolve($template, [
            'slot' => 'blog-footer',
            'source' => 'unit',
        ]);

        self::assertSame('blog-footer', $provider->seenContext['_slot'] ?? null);
        self::assertSame('unit', $provider->seenContext['_options']['source'] ?? null);
        self::assertSame('blog_post', $context['page_type']);
        self::assertSame('Probe Article', $context['article']['headline'] ?? null);
    }

    public function testRegistryFiltersBeforeInstantiationAndIsolatesProviderFailures(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/Service/Head/HeadProviderRegistry.php',
        );

        self::assertIsString($source);
        $filterPosition = strpos(
            $source,
            "if (!is_array(\$extension)\n                    || \$this->extensionName(\$extension) !== 'SeoProfileProvider'",
        );
        $classResolutionPosition = strpos(
            $source,
            '$class = $this->extensionClass($extension);',
        );

        self::assertIsInt($filterPosition);
        self::assertIsInt($classResolutionPosition);
        self::assertLessThan($classResolutionPosition, $filterPosition);
        self::assertGreaterThanOrEqual(2, substr_count($source, 'catch (\\Throwable)'));
        self::assertStringContainsString(
            'One broken optional provider must not hide healthy peers.',
            $source,
        );
    }

    public function testCustomSlotProviderReturnsStructuredPayloadRenderedBySeo(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'blog_post',
            'site_name' => 'Blog',
            'title' => 'Current Post',
            'description' => 'Current post summary.',
            'canonical_url' => 'https://blog.test/current-post',
            'url' => 'https://blog.test/current-post',
            'organization' => ['name' => 'Blog', 'url' => 'https://blog.test/'],
        ]);

        $html = (new HeadRenderer(
            $resolver,
            new EmptySeoStructureRegistry(),
            new SeoSlotProviderRegistryStub([new RelatedPostsSlotProvider()])
        ))->render(new SeoProfileHeadTemplateStub(), ['slot' => 'blog-footer']);

        self::assertStringContainsString('data-seo-slot="blog-footer"', $html);
        self::assertStringContainsString('data-seo-block="related_posts"', $html);
        self::assertStringContainsString('<a href="https://blog.test/related">Related Post</a>', $html);
        self::assertStringContainsString('"@type": "WebPage"', $html);
        self::assertStringContainsString('"@id": "https://blog.test/related#webpage"', $html);
    }

    public function testMultipleBreadcrumbTrailsEmitMultipleBreadcrumbListNodes(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'web_page',
            'site_name' => 'Shop',
            'title' => 'Coat',
            'description' => 'Wool coat.',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/product/coat',
            'url' => 'https://shop.test/product/coat',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/', 'logo' => 'https://shop.test/logo.png'],
            'breadcrumb_trails' => [
                [
                    ['name' => '首页', 'url' => '/'],
                    ['name' => '女装', 'url' => 'https://shop.test/category/women'],
                    ['name' => 'Coat', 'url' => 'https://shop.test/product/coat'],
                ],
                [
                    ['name' => '首页', 'url' => '/'],
                    ['name' => '唐制', 'url' => 'https://shop.test/category/tang'],
                    ['name' => 'Coat', 'url' => 'https://shop.test/product/coat'],
                ],
            ],
        ]);

        $html = (new HeadRenderer($resolver, new EmptySeoStructureRegistry()))->render(new SeoProfileHeadTemplateStub());
        self::assertSame(2, substr_count($html, '"@type": "BreadcrumbList"'));
        self::assertStringContainsString('"name": "女装"', $html);
        self::assertStringContainsString('"name": "唐制"', $html);
        self::assertStringContainsString('"item": "https://shop.test/category/women"', $html);
        self::assertStringContainsString('"item": "https://shop.test/category/tang"', $html);
    }

    public function testBreadcrumbListMatchesGoogleAbsoluteUrlExample(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'web_page',
            'site_name' => 'Shop',
            'title' => 'Coat | Shop Brand',
            'description' => 'Wool coat for winter.',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/product/coat',
            'url' => 'https://shop.test/product/coat',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/', 'logo' => 'https://shop.test/logo.png'],
            'breadcrumbs' => [
                ['name' => '首页', 'url' => '/'],
                ['name' => 'Coat | Shop Brand', 'url' => 'https://shop.test/product/coat'],
            ],
        ]);

        $html = (new HeadRenderer($resolver, new EmptySeoStructureRegistry()))->render(new SeoProfileHeadTemplateStub());

        self::assertStringContainsString('"@type": "BreadcrumbList"', $html);
        self::assertStringContainsString('"item": "https://shop.test/"', $html);
        self::assertStringContainsString('"name": "Coat"', $html);
        self::assertStringNotContainsString('"item": "/"', $html);
        self::assertStringNotContainsString('"@id": "https://shop.test/product/coat#breadcrumb"', $html);
        // Last ListItem omits item per Google examples.
        self::assertMatchesRegularExpression(
            '/"position":\s*2,\s*"name":\s*"Coat"\s*\}/s',
            $html
        );
        self::assertStringContainsString('rel="sitemap"', $html);
    }

    public function testFooterSlotIncludesDefaultInspectorBootstrapWithoutProviderPayload(): void
    {
        if (!\defined('DEBUG')) {
            \define('DEBUG', true);
        }
        if (!\defined('DEV')) {
            \define('DEV', true);
        }

        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'home',
            'title' => 'Home',
            'canonical_url' => 'https://shop.test/',
            'url' => 'https://shop.test/',
        ]);
        $template = new SeoProfileHeadTemplateStub();

        $html = (new HeadRenderer(
            $resolver,
            new EmptySeoStructureRegistry(),
            new SeoSlotProviderRegistryStub([])
        ))->render($template, ['slot' => 'footer']);

        self::assertStringContainsString('data-weline-panel-seo-bootstrap="true"', $html);
        self::assertStringContainsString('data-weline-panel-seo-source="footer-slot"', $html);
        self::assertStringContainsString('window.__WELINE_PANEL_REPORT_PROVIDERS__.seo', $html);
        self::assertStringContainsString('/assets/seo-inspector/inspector.css', $html);
        self::assertStringContainsString('/assets/seo-inspector/inspector.js', $html);
        self::assertStringNotContainsString('@static(', $html);
    }

    public function testCustomSlotWithoutProviderPayloadRendersEmptyString(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'blog_post',
            'title' => 'Current Post',
            'canonical_url' => 'https://blog.test/current-post',
            'url' => 'https://blog.test/current-post',
        ]);

        $html = (new HeadRenderer(
            $resolver,
            new EmptySeoStructureRegistry(),
            new SeoSlotProviderRegistryStub([])
        ))->render(new SeoProfileHeadTemplateStub(), ['slot' => 'unknown-slot']);

        self::assertSame('', $html);
    }
}

final class SeoProfileHeadTemplateStub
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function fetchTagSourceFile(string $type, string $source): string
    {
        if ($type !== 'statics') {
            return '';
        }

        return '/assets/' . ltrim(str_replace('Weline_Seo::', '', $source), '/');
    }
}

final class EmptySeoStructureRegistry extends SeoStructureRegistry
{
    public function buildNodes(array $context, string $url): array
    {
        return [];
    }
}

final class SeoProfileProviderRegistryStub extends HeadProviderRegistry
{
    /**
     * @param SeoProfileProviderInterface[] $providers
     */
    public function __construct(
        private readonly array $providers
    ) {
    }

    public function getSeoProfileProviders(bool $forceReload = false): array
    {
        return $this->providers;
    }
}

final class SeoProfileContextProbeProvider implements SeoProfileProviderInterface
{
    /** @var array<string, mixed> */
    public array $seenContext = [];

    public function provideSeoProfile($template, array $context): array
    {
        $this->seenContext = $context;
        return [
            'page_type' => 'blog_post',
            'article' => [
                'headline' => 'Probe Article',
            ],
        ];
    }
}

final class SeoSlotProviderRegistryStub extends SeoSlotProviderRegistry
{
    /**
     * @param SeoSlotProviderInterface[] $providers
     */
    public function __construct(
        private readonly array $providers
    ) {
    }

    public function getProviders(bool $forceReload = false): array
    {
        return $this->providers;
    }
}

final class RelatedPostsSlotProvider implements SeoSlotProviderInterface
{
    public function supports(string $slot, $template, array $context, array $options = []): bool
    {
        return $slot === 'blog-footer';
    }

    public function provide(string $slot, $template, array $context, array $options = []): array
    {
        return [
            'blocks' => [
                [
                    'type' => 'related_posts',
                    'title' => 'Related',
                    'items' => [
                        [
                            'name' => 'Related Post',
                            'url' => 'https://blog.test/related',
                        ],
                    ],
                ],
            ],
            'schema_nodes' => [
                [
                    '@type' => 'WebPage',
                    '@id' => 'https://blog.test/related#webpage',
                    'name' => 'Related Post',
                ],
            ],
        ];
    }
}
