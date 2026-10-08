<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Theme\Service\PreviewRequestInspector;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeLivePreviewPathMount;

class PreviewRequestInspectorTest extends TestCase
{
    private const SAMPLE_TOKEN = 'pv_' . 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

    public function testLiveRouteDoesNotAllowStoredPreviewContextWithoutToken(): void
    {
        $inspector = new PreviewRequestInspector($this->createRequest('/', []));

        $this->assertFalse($inspector->shouldUseStoredPreviewContext());
        $this->assertFalse($inspector->isEditorMode());
        $this->assertFalse($inspector->hasLivePreviewPathMount());
    }

    public function testLiveRouteWithPreviewTokenQueryAllowsStoredContext(): void
    {
        $inspector = new PreviewRequestInspector($this->createRequest('/', [
            PreviewTokenService::TOKEN_KEY => self::SAMPLE_TOKEN,
        ]));

        $this->assertTrue($inspector->hasExplicitPreviewTokenCarrier());
        $this->assertTrue($inspector->shouldUseStoredPreviewContext());
        $this->assertTrue($inspector->hasExplicitPreviewCarrier());
        $this->assertFalse($inspector->hasLivePreviewPathMount());
    }

    public function testPathMountedPreviewTokenAllowsStoredContext(): void
    {
        $mounted = ThemeLivePreviewPathMount::PATH_PREFIX . '/' . self::SAMPLE_TOKEN . '/';
        $inspector = new PreviewRequestInspector($this->createRequest($mounted, []));

        $this->assertTrue($inspector->hasLivePreviewPathMount());
        $this->assertTrue($inspector->hasExplicitPreviewTokenCarrier());
        $this->assertTrue($inspector->shouldUseStoredPreviewContext());
        $parsed = ThemeLivePreviewPathMount::parseFromUri($mounted);
        $this->assertIsArray($parsed);
        $this->assertSame(self::SAMPLE_TOKEN, $parsed['token'] ?? null);
    }

    public function testIsEditorModeDetectsQueryFlag(): void
    {
        $on = new PreviewRequestInspector($this->createRequest('/', [
            'editor_mode' => '1',
        ]));
        $this->assertTrue($on->isEditorMode());

        $truthy = new PreviewRequestInspector($this->createRequest('/catalog/product/view', [
            'editor_mode' => 'true',
        ]));
        $this->assertTrue($truthy->isEditorMode());
    }

    public function testPreviewShellRouteAllowsStoredPreviewContextButKeepsRequestScoped(): void
    {
        $inspector = new PreviewRequestInspector(
            $this->createRequest('/', [
                'editor_mode' => '1',
                'shell' => 'theme-editor',
            ])
        );

        $this->assertTrue($inspector->shouldUseStoredPreviewContext());
        $this->assertTrue($inspector->shouldKeepPreviewStateOnlyForCurrentRequest());
    }

    public function testPageBuilderVisualPreviewKeepsPreviewStateRequestScoped(): void
    {
        $inspector = new PreviewRequestInspector(
            $this->createRequest('/pagebuilder/backend/preview/full', [
                'visual_editor' => '1',
                'frontend_theme_id' => 9,
            ])
        );

        $this->assertTrue($inspector->shouldUseStoredPreviewContext());
        $this->assertTrue($inspector->shouldKeepPreviewStateOnlyForCurrentRequest());
    }

    public function testExplicitPreviewCarrierEnablesStoredContextOnContentRequests(): void
    {
        $inspector = new PreviewRequestInspector($this->createRequest('/catalog/product/view', [
            'frontend_theme_id' => 9,
            'shell' => 'preview',
        ]));

        $this->assertTrue($inspector->hasExplicitPreviewCarrier());
        $this->assertTrue($inspector->shouldUseStoredPreviewContext());
    }

    public function testSyncInjectedThemeEditorShellDoesNotLookLikeCanvasUrl(): void
    {
        $_SERVER['REQUEST_URI'] = '/~site/grocery/about';
        $request = $this->createMock(Request::class);
        $request->method('getUrlPath')->willReturn('/~site/grocery/about');
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) {
                return match ($key) {
                    'shell' => 'theme-editor',
                    'frontend_theme_id' => 7,
                    default => $default,
                };
            });
        $request->method('getHeader')->willReturn(null);
        $request->method('getServer')
            ->willReturnCallback(static function (string $key, mixed $default = null) {
                if ($key === 'REQUEST_URI' || $key === 'WELINE_ORIGIN_REQUEST_URI') {
                    return '/~site/grocery/about';
                }

                return $default;
            });

        $inspector = new PreviewRequestInspector($request);
        $this->assertFalse(
            $inspector->shouldKeepPreviewStateOnlyForCurrentRequest(),
            'syncRequest-injected shell=theme-editor must not look like a canvas URL'
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function createRequest(string $path, array $params): Request
    {
        $query = \http_build_query($params);
        $requestUri = $path . ($query !== '' ? '?' . $query : '');
        $_SERVER['REQUEST_URI'] = $requestUri;

        $request = $this->createMock(Request::class);
        $request->method('getUrlPath')->willReturn($path);
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($params) {
                return $params[$key] ?? $default;
            });
        $request->method('getHeader')->willReturn(null);
        $request->method('getServer')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($requestUri) {
                if ($key === 'REQUEST_URI' || $key === 'WELINE_ORIGIN_REQUEST_URI') {
                    return $requestUri;
                }

                return $default;
            });

        return $request;
    }
}
