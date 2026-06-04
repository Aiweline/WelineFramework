<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AiSiteAgentSharedComponentResolutionTest extends TestCase
{
    private ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = new ReflectionMethod(AiSiteAgent::class, 'resolveSharedComponentRegionForComponentCode');
        $this->method->setAccessible(true);
    }

    public function testHeaderComponentCodesResolveToSharedHeaderRegion(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();

        self::assertSame('header', $this->method->invoke($controller, 'home_page', 'header/ai-site-header'));
        self::assertSame('header', $this->method->invoke($controller, 'home_page', 'home-page-site-header'));
        self::assertSame('header', $this->method->invoke($controller, 'home_page', 'content/home-page-site-header'));
    }

    public function testFooterComponentCodesResolveToSharedFooterRegion(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();

        self::assertSame('footer', $this->method->invoke($controller, 'home_page', 'footer/ai-site-footer'));
        self::assertSame('footer', $this->method->invoke($controller, 'home_page', 'home-page-site-footer'));
        self::assertSame('footer', $this->method->invoke($controller, 'home_page', 'content/home-page-site-footer'));
    }

    public function testRegularPageSectionDoesNotResolveAsSharedComponent(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();

        self::assertSame('', $this->method->invoke($controller, 'home_page', 'content/home-page-hero'));
        self::assertSame('', $this->method->invoke($controller, 'contact_page', 'content/contact-page-form'));
    }

    public function testSharedComponentRegenerationPersistsGeneratedLayoutsForAllAffectedPages(): void
    {
        $controllerSource = \file_get_contents($this->controllerSourcePath());
        self::assertIsString($controllerSource);

        self::assertStringContainsString('$affectedPageTypes = $pageTypesAll;', $controllerSource);
        self::assertStringContainsString('$this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypesAll, $pageTypeLayouts);', $controllerSource);
        self::assertStringContainsString('$this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);', $controllerSource);
        self::assertStringContainsString('$this->virtualThemeService->saveGeneratedPageLayout($virtualThemeId, $pageType, $pageTypeLayouts[$pageType]);', $controllerSource);
    }

    public function testBlockConfigSaveUsesExistingVirtualLayoutAsBase(): void
    {
        $controllerSource = \file_get_contents($this->controllerSourcePath());
        self::assertIsString($controllerSource);

        self::assertStringContainsString(
            '$pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope[\'page_type_layouts\'] ?? [], $layoutPageTypes);',
            $controllerSource
        );
        self::assertStringContainsString('$persistedLayout = $this->virtualThemeService->loadGeneratedPageLayout($virtualThemeId, $targetPageType);', $controllerSource);
        self::assertStringContainsString('$scope[\'page_type_layouts\'] = $pageTypeLayouts;', $controllerSource);
        self::assertStringNotContainsString(
            '$pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts([], $layoutPageTypes);',
            $controllerSource
        );
    }

    private function controllerSourcePath(): string
    {
        return \dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php';
    }
}
