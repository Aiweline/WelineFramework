<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use ReflectionMethod as ReflectionMethod;
use Weline\Framework\Runtime\RequestContext;
use Weline\Seo\Service\Head\PageSeoContextResolver;
use Weline\Seo\Service\Head\SeoPageProfileBag;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoHeadContractsPass().
 */
final class SeoHeadContractsTest extends TestCase
{
    public function testAllSeoHeadContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'EntityTitleOverLayoutDefault::testThemeObserverSkipsLayoutTitleWhenEntityTitleExists' => function () {
            $this->assert_EntityTitleOverLayoutDefault_testThemeObserverSkipsLayoutTitleWhenEntityTitleExists();
        },
            'EntityTitleOverLayoutDefault::testNonHanTitleIsReturnedUntouched' => function () {
            $this->assert_EntityTitleOverLayoutDefault_testNonHanTitleIsReturnedUntouched();
        },
            'EntityTitleOverLayoutDefault::testResolverTranslatesTitleBeforeContext' => function () {
            $this->assert_EntityTitleOverLayoutDefault_testResolverTranslatesTitleBeforeContext();
        },
            'LayoutSeoFallback::testExtractIgnoresDesignerLayoutNameAndDescription' => function () {
            try {
                $this->assert_LayoutSeoFallback_testExtractIgnoresDesignerLayoutNameAndDescription();
            } finally {
                $this->tearDown_LayoutSeoFallback();
            }
        },
            'LayoutSeoFallback::testExtractKeepsExplicitSeoFieldsOnly' => function () {
            try {
                $this->assert_LayoutSeoFallback_testExtractKeepsExplicitSeoFieldsOnly();
            } finally {
                $this->tearDown_LayoutSeoFallback();
            }
        },
            'LayoutSeoFallback::testLayoutFallbackDoesNotReplaceEntityBag' => function () {
            try {
                $this->assert_LayoutSeoFallback_testLayoutFallbackDoesNotReplaceEntityBag();
            } finally {
                $this->tearDown_LayoutSeoFallback();
            }
        },
            'LayoutSeoFallback::testResolverPrefersEntityBagOverLayoutFallback' => function () {
            try {
                $this->assert_LayoutSeoFallback_testResolverPrefersEntityBagOverLayoutFallback();
            } finally {
                $this->tearDown_LayoutSeoFallback();
            }
        },
            'LayoutSeoFallback::testResolverUsesLayoutFallbackWhenBagEmpty' => function () {
            try {
                $this->assert_LayoutSeoFallback_testResolverUsesLayoutFallbackWhenBagEmpty();
            } finally {
                $this->tearDown_LayoutSeoFallback();
            }
        },
            'LayoutSeoFallback::testResolverDoesNotUseLayoutNameAsPublicTitle' => function () {
            try {
                $this->assert_LayoutSeoFallback_testResolverDoesNotUseLayoutNameAsPublicTitle();
            } finally {
                $this->tearDown_LayoutSeoFallback();
            }
        },
            'LayoutSeoFallback::testFingerprintIncludesLayoutFallback' => function () {
            try {
                $this->assert_LayoutSeoFallback_testFingerprintIncludesLayoutFallback();
            } finally {
                $this->tearDown_LayoutSeoFallback();
            }
        },
            'PageSeoRenderContextReader::testResolveWebsiteSiteNamePrefersRenderContext' => function () {
            $this->assert_PageSeoRenderContextReader_testResolveWebsiteSiteNamePrefersRenderContext();
        },
            'RouteTitleNotHumanizedPath::testRouteTitleNeverReturnsHumanizedUrlSegments' => function () {
            $this->assert_RouteTitleNotHumanizedPath_testRouteTitleNeverReturnsHumanizedUrlSegments();
        },
        ];
    }

private function assert_EntityTitleOverLayoutDefault_testThemeObserverSkipsLayoutTitleWhenEntityTitleExists(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Observer/ControllerFetchFileBefore.php');
    $start = strpos($source, 'private function publishPageTitleToSeoBag(');
    self::assertNotFalse($start);
    $body = substr($source, $start, 1600);
    self::assertStringContainsString("SeoPageProfileBag::pull()['title']", $body);
    self::assertStringContainsString("\$template->getData('seo')", $body);
    self::assertLessThan(strpos($body, "\$template->getData('title')"), strpos($body, 'return;'), 'Entity title guard must run before the layout title is read.');
}

private function assert_EntityTitleOverLayoutDefault_testNonHanTitleIsReturnedUntouched(): void
{
    $method = new ReflectionMethod(PageSeoContextResolver::class, 'translateHanTitle');
    $method->setAccessible(true);
    $resolver = new PageSeoContextResolver();
    self::assertSame('Obsidian Ruyi Pendant', $method->invoke($resolver, ' Obsidian Ruyi Pendant '));
    self::assertSame('', $method->invoke($resolver, ''));
}

private function assert_EntityTitleOverLayoutDefault_testResolverTranslatesTitleBeforeContext(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/Head/PageSeoContextResolver.php');
    $translate = strpos($source, '$title = $this->translateHanTitle((string) $title);');
    $context = strpos($source, "'title' => (string) \$title,");
    self::assertNotFalse($translate);
    self::assertNotFalse($context);
    self::assertLessThan($context, $translate);
}

private function tearDown_LayoutSeoFallback(): void
{
    SeoPageProfileBag::reset();
    RequestContext::remove(SeoPageProfileBag::REQUEST_KEY);
    RequestContext::remove(SeoPageProfileBag::LAYOUT_FALLBACK_KEY);
    parent::tearDown();
}

private function assert_LayoutSeoFallback_testExtractIgnoresDesignerLayoutNameAndDescription(): void
{
    $fallback = SeoPageProfileBag::extractLayoutFallbackFromMeta(['name' => '搜索页默认布局', 'layout_name' => '搜索页默认布局', 'layout_description' => '搜索结果页面布局', 'description' => '搜索结果页面布局', 'title' => '搜索结果', 'meta_title' => '', 'meta_description' => '']);
    self::assertSame([], $fallback);
}

private function assert_LayoutSeoFallback_testExtractKeepsExplicitSeoFieldsOnly(): void
{
    $fallback = SeoPageProfileBag::extractLayoutFallbackFromMeta(['name' => '水墨汉服商城首页', 'title' => '长安汉服', 'meta_title' => '运营自定义首页标题', 'meta_description' => '运营自定义首页描述', 'robots' => 'index,follow']);
    self::assertSame('运营自定义首页标题', $fallback['meta_title']);
    self::assertSame('运营自定义首页描述', $fallback['meta_description']);
    self::assertSame('index,follow', $fallback['robots']);
    self::assertArrayNotHasKey('title', $fallback);
    self::assertArrayNotHasKey('name', $fallback);
}

private function assert_LayoutSeoFallback_testLayoutFallbackDoesNotReplaceEntityBag(): void
{
    SeoPageProfileBag::replace(['page_type' => 'product', 'title' => '商品实体 SEO 标题', 'description' => '商品实体描述']);
    SeoPageProfileBag::setLayoutFallback(['meta_title' => '布局 SEO 标题', 'meta_description' => '布局 SEO 描述']);
    self::assertSame('商品实体 SEO 标题', SeoPageProfileBag::pull()['title']);
    self::assertSame('布局 SEO 标题', SeoPageProfileBag::pullLayoutFallback()['meta_title']);
}

private function assert_LayoutSeoFallback_testResolverPrefersEntityBagOverLayoutFallback(): void
{
    SeoPageProfileBag::replace(['page_type' => 'product', 'title' => '实体标题', 'description' => '实体描述']);
    SeoPageProfileBag::setLayoutFallback(['meta_title' => '布局标题', 'meta_description' => '布局描述']);
    $context = (new PageSeoContextResolver())->resolve(new LayoutSeoFallbackTemplateStub(['product' => ['meta_name' => '商品名 SEO', 'meta_description' => '商品描述']]));
    self::assertSame('实体标题', $context['title']);
    self::assertSame('实体描述', $context['description']);
}

private function assert_LayoutSeoFallback_testResolverUsesLayoutFallbackWhenBagEmpty(): void
{
    SeoPageProfileBag::setLayoutFallback(['meta_title' => '纯 Theme 布局标题', 'meta_description' => '纯 Theme 布局描述']);
    $context = (new PageSeoContextResolver())->resolve(new LayoutSeoFallbackTemplateStub([]));
    self::assertSame('纯 Theme 布局标题', $context['title']);
    self::assertSame('纯 Theme 布局描述', $context['description']);
}

private function assert_LayoutSeoFallback_testResolverDoesNotUseLayoutNameAsPublicTitle(): void
{
    $context = (new PageSeoContextResolver())->resolve(new LayoutSeoFallbackTemplateStub(['meta' => ['name' => '搜索页默认布局', 'layout_name' => '搜索页默认布局', 'layout_description' => '搜索结果页面布局'], 'layout' => ['name' => '搜索页默认布局', 'layout_name' => '搜索页默认布局']]));
    self::assertStringNotContainsString('搜索页默认布局', (string) $context['title']);
}

private function assert_LayoutSeoFallback_testFingerprintIncludesLayoutFallback(): void
{
    SeoPageProfileBag::replace(['page_type' => 'home', 'title' => 'A']);
    $before = SeoPageProfileBag::fingerprint();
    SeoPageProfileBag::setLayoutFallback(['meta_title' => 'B']);
    $after = SeoPageProfileBag::fingerprint();
    self::assertNotSame($before, $after);
}

private function assert_PageSeoRenderContextReader_testResolveWebsiteSiteNamePrefersRenderContext(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/Head/PageSeoContextResolver.php');
    $fn = \strpos($src, 'function resolveWebsiteSiteName');
    self::assertNotFalse($fn);
    $slice = \substr($src, $fn, 2800);
    self::assertStringContainsString('StorefrontRenderContextBag::websiteLocalName', $slice);
    self::assertStringContainsString('StorefrontRenderContextReader', $slice);
    $bagPos = \strpos($slice, 'StorefrontRenderContextBag::websiteLocalName');
    $dataPos = \strpos($slice, 'WebsiteData::getName');
    self::assertNotFalse($bagPos);
    self::assertNotFalse($dataPos);
    self::assertLessThan($dataPos, $bagPos);
    self::assertTrue(class_exists(PageSeoContextResolver::class));
}

private function assert_RouteTitleNotHumanizedPath_testRouteTitleNeverReturnsHumanizedUrlSegments(): void
{
    $resolver = new PageSeoContextResolver();
    $method = new ReflectionMethod(PageSeoContextResolver::class, 'routeTitle');
    $method->setAccessible(true);
    $template = new class
    {
        /** @var array<string, mixed> */
        private array $data = [];
        public function getData(string $key): mixed
        {
            return $this->data[$key] ?? null;
        }
    };
    self::assertSame('', $method->invoke($resolver, $template));
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/Head/PageSeoContextResolver.php');
    self::assertStringContainsString('title×locale', $source);
    self::assertStringContainsString("return '';", $source);
    // Must not feed humanizeRouteSegment into the public <title> chain.
    $start = strpos($source, 'private function routeTitle(');
    $end = strpos($source, 'private function requestPath(', $start === false ? 0 : $start);
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $methodSrc = substr($source, (int) $start, (int) $end - (int) $start);
    self::assertStringNotContainsString('humanizeRouteSegment', $methodSrc);
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
