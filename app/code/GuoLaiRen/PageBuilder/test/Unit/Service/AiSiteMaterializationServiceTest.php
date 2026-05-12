<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\PageLayout;
use GuoLaiRen\PageBuilder\Service\AiSiteMaterializationService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use PHPUnit\Framework\TestCase;

final class AiSiteMaterializationServiceTest extends TestCase
{
    public function testMaterializeRejectsNonPositiveWebsiteId(): void
    {
        $page = $this->createMock(Page::class);
        $page->expects(self::never())->method('save');
        $layout = $this->createMock(PageLayout::class);
        $scopeSvc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $service = new AiSiteMaterializationService($page, $layout, $scopeSvc);

        $this->expectException(\InvalidArgumentException::class);
        $service->materialize(0, [], [Page::TYPE_HOME], []);
    }

    public function testMaterializePreservesVirtualThemeComponentLayoutConfig(): void
    {
        $page = $this->createMock(Page::class);
        $layout = $this->createMock(PageLayout::class);
        $scopeSvc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $service = new AiSiteMaterializationService($page, $layout, $scopeSvc);
        $method = new \ReflectionMethod($service, 'resolveMaterializedLayoutConfig');
        $method->setAccessible(true);

        $sourceLayout = $scopeSvc->normalizeLayoutConfig([
            'header' => [
                'component' => 'header/ai-site-header',
                'config' => ['logo.text' => 'AI Brand'],
            ],
            'content' => [
                [
                    'code' => 'content/home-page-hero-banner',
                    'config' => ['content.title' => 'AI generated hero'],
                ],
            ],
            'footer' => [
                'component' => 'footer/ai-site-footer',
                'config' => [],
            ],
        ], Page::TYPE_HOME);

        $materialized = $method->invoke($service, $sourceLayout);

        self::assertSame('header/ai-site-header', $materialized['header']['component'] ?? '');
        self::assertSame('content/home-page-hero-banner', $materialized['content'][0]['code'] ?? '');
        self::assertSame('footer/ai-site-footer', $materialized['footer']['component'] ?? '');
    }

    public function testMaterializationRequiresGeneratedContentComponentWhenBlocksAreMissing(): void
    {
        $page = $this->createMock(Page::class);
        $layout = $this->createMock(PageLayout::class);
        $scopeSvc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $service = new AiSiteMaterializationService($page, $layout, $scopeSvc);
        $method = new \ReflectionMethod($service, 'layoutHasGeneratedContentComponents');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($service, $scopeSvc->normalizeLayoutConfig([
            'header' => ['component' => 'header/ai-site-header', 'config' => []],
            'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
        ], Page::TYPE_HOME)));

        self::assertTrue($method->invoke($service, $scopeSvc->normalizeLayoutConfig([
            'header' => ['component' => 'header/ai-site-header', 'config' => []],
            'content' => [
                ['code' => 'content/home-page-hero-banner', 'config' => ['content.title' => 'Hero']],
            ],
            'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
        ], Page::TYPE_HOME)));
    }

    public function testVirtualThemeMaterializationUsesLayoutConfigInsteadOfStaleAiHtmlBlocks(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteMaterializationService.php');
        self::assertIsString($source);

        self::assertStringContainsString('$hasGeneratedLayout = $this->layoutHasGeneratedContentComponents($materializedLayoutConfig);', $source);
        self::assertStringContainsString('if (!$hasGeneratedLayout) {', $source);
        self::assertStringContainsString('$renderMode = $hasGeneratedLayout ? Page::RENDER_MODE_THEME : Page::RENDER_MODE_AI_HTML;', $source);
        self::assertStringContainsString('$aiLayoutJson = $hasGeneratedLayout ? null : \json_encode($aiLayout, \JSON_UNESCAPED_UNICODE);', $source);
    }

    public function testSinglePageMaterializationDoesNotInjectHomePage(): void
    {
        $page = $this->createMock(Page::class);
        $layout = $this->createMock(PageLayout::class);
        $scopeSvc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $service = new AiSiteMaterializationService($page, $layout, $scopeSvc);
        $method = new \ReflectionMethod($service, 'normalizeMaterializationPageTypes');
        $method->setAccessible(true);

        self::assertSame([Page::TYPE_BLOG_LIST], $method->invoke($service, [Page::TYPE_BLOG_LIST]));
        self::assertSame([Page::TYPE_ABOUT], $method->invoke($service, Page::TYPE_ABOUT));
        self::assertSame(\array_keys(Page::getPageTypes()), $method->invoke($service, []));
    }
}
