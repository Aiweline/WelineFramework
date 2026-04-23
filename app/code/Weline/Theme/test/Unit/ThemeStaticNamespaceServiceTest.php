<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Session;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewRequestInspector;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemeResourceGateway;
use Weline\Theme\Service\ThemeStaticNamespaceService;

class ThemeStaticNamespaceServiceTest extends TestCase
{
    public function testPreviewTokenNamespaceUsesDedicatedPreviewPrefix(): void
    {
        $service = $this->createService(true, [
            'frontend_theme_id' => 11,
            'preview_token' => 'pv_test_123',
            'editor_area' => 'frontend',
            'shell' => 'preview',
            'preview_mode' => 'live',
            'status' => 'draft',
            'scope' => 'default',
        ]);

        $theme = $this->createTheme('WeShop/motor');

        $this->assertSame(
            '__preview/token_pv_test_123/WeShop/motor',
            $service->resolvePublicThemePath($theme)
        );
    }

    public function testPreviewContextQueryIncludesCanonicalPreviewFields(): void
    {
        $service = $this->createService(true, [
            'frontend_theme_id' => 15,
            'backend_theme_id' => 0,
            'preview_token' => 'pv_query_456',
            'editor_area' => 'frontend',
            'shell' => 'preview',
            'preview_mode' => 'live',
            'status' => 'draft',
            'scope' => 'homepage/default',
            'version_id' => 91,
        ]);

        $url = $service->appendPreviewContextQuery('/static/__preview/token_pv_query_456/WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/theme.css');

        $this->assertStringContainsString('frontend_theme_id=15', $url);
        $this->assertStringContainsString('shell=', $url);
        $this->assertStringContainsString('version_id=91', $url);
        $this->assertStringContainsString('weline_preview_token=pv_query_456', $url);
    }

    public function testLiveRequestsKeepOriginalThemePathWithoutPreviewNamespace(): void
    {
        $service = $this->createService(false, [
            'frontend_theme_id' => 0,
            'preview_token' => '',
        ]);

        $this->assertSame(
            'WeShop/motor',
            $service->resolvePublicThemePath($this->createTheme('WeShop/motor'))
        );
        $this->assertSame(
            '/static/WeShop/motor/theme.css',
            $service->appendPreviewContextQuery('/static/WeShop/motor/theme.css')
        );
    }

    public function testDesignAbsoluteOriginPathResolvesToPublicThemePath(): void
    {
        $service = $this->createService(false, [
            'frontend_theme_id' => 0,
            'preview_token' => '',
        ]);

        $themePath = rtrim(str_replace('\\', '/', BP), '/') . '/app/design/WeShop/motor';

        $this->assertSame(
            'WeShop/motor',
            $service->resolvePublicThemePath($this->createTheme($themePath))
        );
    }

    public function testModuleThemeAbsoluteOriginPathStaysRelativeInPreviewStaticPath(): void
    {
        $service = $this->createService(true, [
            'backend_theme_id' => 10,
            'preview_token' => 'pv_absolute_module',
            'editor_area' => 'backend',
            'shell' => 'theme-editor',
            'preview_mode' => 'live',
            'status' => 'draft',
            'scope' => 'default',
        ]);
        $gateway = new ThemeResourceGateway(
            $this->createMock(ThemeDirectoryResolver::class),
            new ThemeContextService($this->createMock(WelineTheme::class)),
            $service,
            $this->createMock(Request::class),
        );
        $theme = $this->createTheme(
            rtrim(str_replace('\\', '/', BP), '/') . '/app/code/Weline/Theme/view/theme',
            10
        );

        $path = str_replace('\\', '/', $gateway->buildLayoutAssetDiskPath('backend', 'default', 'default', 'css', $theme));
        $staticRelative = substr($path, strpos($path, '/pub/static/') + strlen('/pub/static/'));

        $this->assertSame(
            '__preview/token_pv_absolute_module/Weline/Theme/view/theme/backend/layouts/default/default.css',
            $staticRelative
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createService(bool $shouldUseStoredContext, array $context): ThemeStaticNamespaceService
    {
        $request = $this->createMock(Request::class);
        $request->method('getUrlPath')->willReturn(
            $shouldUseStoredContext ? '/theme/frontend/theme-preview/content' : '/'
        );
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, mixed $default = null): mixed => $default
        );
        $request->method('getHeader')->willReturn(null);
        $request->method('setGet')->willReturnSelf();

        $session = $this->createMock(Session::class);
        $session->method('getData')->willReturnCallback(
            static function (string $key) use ($context): mixed {
                if ($key === PreviewContextService::SESSION_KEY) {
                    return $context;
                }

                return null;
            }
        );
        $session->method('getId')->willReturn('test-session-id');

        $previewTokenService = $this->createMock(PreviewTokenService::class);
        $previewTokenService->method('getCurrentPreviewData')->willReturn(null);
        $previewTokenService->method('getTokenFromRequest')->willReturn(null);

        $previewContextService = new PreviewContextService(
            $request,
            $session,
            $previewTokenService,
            $this->createMock(WelineTheme::class),
            new PreviewRequestInspector($request),
        );

        return new ThemeStaticNamespaceService($previewContextService, $session);
    }

    private function createTheme(string $originPath, int $id = 1): WelineTheme
    {
        $theme = $this->createMock(WelineTheme::class);
        $theme->method('getOriginPath')->willReturn($originPath);
        $theme->method('getId')->willReturn($id);

        return $theme;
    }
}
