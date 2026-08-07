<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Http\Request;
use Weline\I18n\Taglib\LanguageSwitcher;

final class LanguageSwitcherPublicRouteTest extends TestCase
{
    public function testPublicFrontendRouteMapsPageBuilderViewToHome(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getGet')->willReturnCallback(static function (string $key, mixed $default = null) {
            return $default;
        });
        $request->method('getServer')->willReturnCallback(static function (string $key, mixed $default = null) {
            return match ($key) {
                'WELINE_ORIGIN_REQUEST_URI' => '/about',
                'REQUEST_URI' => '/pagebuilder/frontend/page/view?page_id=646',
                default => is_string($default) || is_array($default) ? $default : '',
            };
        });
        $request->method('getUrlPath')->willReturn('/pagebuilder/frontend/page/view');

        self::assertSame('/about', $this->resolvePublicFrontendPath($request));
    }

    public function testPublicFrontendRouteFallsBackToHomeWhenOnlyInternalPathRemains(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getGet')->willReturnCallback(static function (string $key, mixed $default = null) {
            return $default;
        });
        $request->method('getServer')->willReturnCallback(static function (string $key, mixed $default = null) {
            return match ($key) {
                'WELINE_ORIGIN_REQUEST_URI' => '/zh_Hans_CN/pagebuilder/frontend/page/view',
                'REQUEST_URI' => '/pagebuilder/frontend/page/view',
                default => is_string($default) || is_array($default) ? $default : '',
            };
        });
        $request->method('getUrlPath')->willReturn('/pagebuilder/frontend/page/view');

        self::assertSame('/', $this->resolvePublicFrontendPath($request));
    }

    public function testPublicFrontendRoutePrefersPageHandleOverInternalPath(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getGet')->willReturnCallback(static function (string $key, mixed $default = null) {
            return match ($key) {
                'handle' => 'contact',
                default => $default,
            };
        });
        $request->method('getServer')->willReturn('');
        $request->method('getUrlPath')->willReturn('/pagebuilder/frontend/page/view');

        self::assertSame('/contact', $this->resolvePublicFrontendPath($request));
    }

    public function testPublicFrontendRouteStripsWebsiteMountSubPath(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getGet')->willReturnCallback(static function (string $key, mixed $default = null) {
            return $default;
        });
        $request->method('getServer')->willReturnCallback(static function (string $key, mixed $default = null) {
            return match ($key) {
                'WELINE_ORIGIN_REQUEST_URI' => '/aisite_accept_ok/',
                'REQUEST_URI' => '/pagebuilder/frontend/page/view?page_id=1',
                'WELINE_WEBSITE_URL' => 'https://pre.example.test/aisite_accept_ok',
                default => is_string($default) || is_array($default) ? $default : '',
            };
        });
        $request->method('getUrlPath')->willReturn('/pagebuilder/frontend/page/view');

        self::assertSame('/', $this->resolvePublicFrontendPath($request));
    }

    public function testPublicFrontendRouteStripsMountBeforePageHandle(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getGet')->willReturnCallback(static function (string $key, mixed $default = null) {
            return $default;
        });
        $request->method('getServer')->willReturnCallback(static function (string $key, mixed $default = null) {
            return match ($key) {
                'WELINE_ORIGIN_REQUEST_URI' => '/aisite_accept_ok/about',
                'REQUEST_URI' => '/pagebuilder/frontend/page/view',
                'WELINE_WEBSITE_URL' => 'https://pre.example.test/aisite_accept_ok',
                default => is_string($default) || is_array($default) ? $default : '',
            };
        });
        $request->method('getUrlPath')->willReturn('/pagebuilder/frontend/page/view');

        self::assertSame('/about', $this->resolvePublicFrontendPath($request));
    }

    private function resolvePublicFrontendPath(Request $request): string
    {
        $method = new ReflectionMethod(LanguageSwitcher::class, 'resolvePublicFrontendPath');
        $method->setAccessible(true);

        return (string)$method->invoke(null, $request);
    }
}
