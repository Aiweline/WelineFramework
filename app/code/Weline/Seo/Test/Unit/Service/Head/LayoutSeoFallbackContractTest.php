<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head {
    if (!\function_exists(__NAMESPACE__ . '\\w_env')) {
        function w_env(string $key, mixed $default = null): mixed
        {
            return $default;
        }
    }
    if (!\function_exists(__NAMESPACE__ . '\\__')) {
        function __(mixed $text, array $args = []): string
        {
            return (string) $text;
        }
    }
}

namespace Weline\Seo\Test\Unit\Service\Head {

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Seo\Service\Head\PageSeoContextResolver;
use Weline\Seo\Service\Head\SeoPageProfileBag;

final class LayoutSeoFallbackContractTest extends TestCase
{
    protected function tearDown(): void
    {
        SeoPageProfileBag::reset();
        RequestContext::remove(SeoPageProfileBag::REQUEST_KEY);
        RequestContext::remove(SeoPageProfileBag::LAYOUT_FALLBACK_KEY);
        parent::tearDown();
    }

    public function testExtractIgnoresDesignerLayoutNameAndDescription(): void
    {
        $fallback = SeoPageProfileBag::extractLayoutFallbackFromMeta([
            'name' => '搜索页默认布局',
            'layout_name' => '搜索页默认布局',
            'layout_description' => '搜索结果页面布局',
            'description' => '搜索结果页面布局',
            'title' => '搜索结果',
            'meta_title' => '',
            'meta_description' => '',
        ]);

        self::assertSame([], $fallback);
    }

    public function testExtractKeepsExplicitSeoFieldsOnly(): void
    {
        $fallback = SeoPageProfileBag::extractLayoutFallbackFromMeta([
            'name' => '水墨汉服商城首页',
            'title' => '长安汉服 · Hanfu Atelier',
            'meta_title' => '运营自定义首页标题',
            'meta_description' => '运营自定义首页描述',
            'robots' => 'index,follow',
        ]);

        self::assertSame('运营自定义首页标题', $fallback['meta_title']);
        self::assertSame('运营自定义首页描述', $fallback['meta_description']);
        self::assertSame('index,follow', $fallback['robots']);
        self::assertArrayNotHasKey('title', $fallback);
        self::assertArrayNotHasKey('name', $fallback);
    }

    public function testLayoutFallbackDoesNotReplaceEntityBag(): void
    {
        SeoPageProfileBag::replace([
            'page_type' => 'product',
            'title' => '商品实体 SEO 标题',
            'description' => '商品实体描述',
        ]);
        SeoPageProfileBag::setLayoutFallback([
            'meta_title' => '布局 SEO 标题',
            'meta_description' => '布局 SEO 描述',
        ]);

        self::assertSame('商品实体 SEO 标题', SeoPageProfileBag::pull()['title']);
        self::assertSame('布局 SEO 标题', SeoPageProfileBag::pullLayoutFallback()['meta_title']);
    }

    public function testResolverPrefersEntityBagOverLayoutFallback(): void
    {
        SeoPageProfileBag::replace([
            'page_type' => 'product',
            'title' => '实体标题',
            'description' => '实体描述',
        ]);
        SeoPageProfileBag::setLayoutFallback([
            'meta_title' => '布局标题',
            'meta_description' => '布局描述',
        ]);

        $context = (new PageSeoContextResolver())->resolve(new LayoutSeoFallbackTemplateStub([
            'product' => ['meta_name' => '商品名 SEO', 'meta_description' => '商品描述'],
        ]));

        self::assertSame('实体标题', $context['title']);
        self::assertSame('实体描述', $context['description']);
    }

    public function testResolverUsesLayoutFallbackWhenBagEmpty(): void
    {
        SeoPageProfileBag::setLayoutFallback([
            'meta_title' => '纯 Theme 布局标题',
            'meta_description' => '纯 Theme 布局描述',
        ]);

        $context = (new PageSeoContextResolver())->resolve(new LayoutSeoFallbackTemplateStub([]));

        self::assertSame('纯 Theme 布局标题', $context['title']);
        self::assertSame('纯 Theme 布局描述', $context['description']);
    }

    public function testResolverDoesNotUseLayoutNameAsPublicTitle(): void
    {
        $context = (new PageSeoContextResolver())->resolve(new LayoutSeoFallbackTemplateStub([
            'meta' => [
                'name' => '搜索页默认布局',
                'layout_name' => '搜索页默认布局',
                'layout_description' => '搜索结果页面布局',
            ],
            'layout' => [
                'name' => '搜索页默认布局',
                'layout_name' => '搜索页默认布局',
            ],
        ]));

        self::assertStringNotContainsString('搜索页默认布局', (string) $context['title']);
    }

    public function testFingerprintIncludesLayoutFallback(): void
    {
        SeoPageProfileBag::replace(['page_type' => 'home', 'title' => 'A']);
        $before = SeoPageProfileBag::fingerprint();
        SeoPageProfileBag::setLayoutFallback(['meta_title' => 'B']);
        $after = SeoPageProfileBag::fingerprint();
        self::assertNotSame($before, $after);
    }
}

final class LayoutSeoFallbackTemplateStub
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
    }

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}

}
