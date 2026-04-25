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
}
