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
            'locale' => 'zh_Hans_CN',
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
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('"@type": "NewsArticle"', $html);
        self::assertStringContainsString('"mainEntityOfPage": {', $html);
        self::assertStringContainsString('"articleSection": "Company News"', $html);
        self::assertStringContainsString('"inLanguage": "zh-Hans-CN"', $html);
    }

    public function testRendersCollectionPageItemListProfileGraph(): void
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

        self::assertStringContainsString('"@type": "CollectionPage"', $html);
        self::assertStringContainsString('"@type": "ItemList"', $html);
        self::assertStringContainsString('"name": "Summer Dress"', $html);
        self::assertStringContainsString('"mainEntity": {', $html);
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

    public function testFooterSlotIncludesDefaultInspectorBootstrapWithoutProviderPayload(): void
    {
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

        self::assertStringContainsString('data-weline-seo-bootstrap="true"', $html);
        self::assertStringContainsString('data-weline-seo-source="footer-slot"', $html);
        self::assertStringContainsString('window.__WELINE_SEO__', $html);
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
