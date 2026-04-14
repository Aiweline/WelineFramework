<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Theme\Service\PreviewRequestInspector;

class PreviewRequestInspectorTest extends TestCase
{
    public function testLiveRouteDoesNotAllowStoredPreviewContext(): void
    {
        $inspector = new PreviewRequestInspector($this->createRequest('/', []));

        $this->assertFalse($inspector->shouldUseStoredPreviewContext());
        $this->assertFalse($inspector->shouldAllowPreviewTokenCookie());
    }

    public function testPreviewShellRouteAllowsStoredPreviewContextButBlocksCookieTokenOnThemeEditor(): void
    {
        $inspector = new PreviewRequestInspector(
            $this->createRequest('/theme/backend/theme-editor/layout-preview', [
                'editor_mode' => '1',
            ])
        );

        $this->assertTrue($inspector->shouldUseStoredPreviewContext());
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
        $request = $this->createMock(Request::class);
        $request->method('getUrlPath')->willReturn($path);
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($params) {
                return $params[$key] ?? $default;
            });
        $request->method('getHeader')->willReturn(null);

        return $request;
    }
}
