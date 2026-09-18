<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Theme\Service\PreviewRequestInspector;
use Weline\Theme\Service\PreviewTokenService;

class PreviewRequestInspectorTest extends TestCase
{
    private const SAMPLE_TOKEN = 'pv_' . 'abcdefghijklmnopqrstuvwxyz0123456789ABCDE';

    public function testLiveRouteDoesNotAllowStoredPreviewContextWithoutToken(): void
    {
        $inspector = new PreviewRequestInspector($this->createRequest('/', []));

        $this->assertFalse($inspector->shouldUseStoredPreviewContext());
        $this->assertFalse($inspector->shouldAllowPreviewTokenCookie());
        $this->assertFalse($inspector->isEditorMode());
    }

    public function testLiveRouteWithPreviewTokenAllowsStoredContextAndCookie(): void
    {
        $inspector = new PreviewRequestInspector($this->createRequest('/', [
            PreviewTokenService::TOKEN_KEY => self::SAMPLE_TOKEN,
        ]));

        $this->assertTrue($inspector->hasExplicitPreviewTokenCarrier());
        $this->assertTrue($inspector->shouldUseStoredPreviewContext());
        $this->assertTrue($inspector->hasExplicitPreviewCarrier());
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

    public function testPreviewShellRouteAllowsStoredPreviewContextButBlocksCookieTokenOnThemeEditor(): void
    {
        $inspector = new PreviewRequestInspector(
            $this->createRequest('/', [
                'editor_mode' => '1',
                'shell' => 'theme-editor',
            ])
        );

        $this->assertTrue($inspector->shouldUseStoredPreviewContext());
        $this->assertTrue($inspector->shouldKeepPreviewStateOnlyForCurrentRequest());
        $this->assertFalse($inspector->shouldAllowPreviewTokenCookie());
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
        $this->assertFalse($inspector->shouldAllowPreviewTokenCookie());
    }

    public function testExplicitPreviewCarrierEnablesStoredContextOnContentRequests(): void
    {
        $inspector = new PreviewRequestInspector($this->createRequest('/catalog/product/view', [
            'frontend_theme_id' => 9,
            'shell' => 'preview',
        ]));

        $this->assertTrue($inspector->hasExplicitPreviewCarrier());
        $this->assertTrue($inspector->shouldUseStoredPreviewContext());
        $this->assertFalse($inspector->shouldAllowPreviewTokenCookie());
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
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($requestUri, $path) {
                if ($key === 'REQUEST_URI') {
                    return $requestUri;
                }
                if ($key === 'WELINE_ORIGIN_REQUEST_URI') {
                    return $requestUri;
                }

                return $default;
            });

        return $request;
    }
}
