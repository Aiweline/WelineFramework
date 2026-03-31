<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Session;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewTokenService;

class PreviewContextServiceTest extends TestCase
{
    private ?string $originalRequestUri = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalRequestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->originalRequestUri;
        }
        parent::tearDown();
    }

    public function testLegacyPreviewThemeOverridesStaleTokenThemeAndClearsToken(): void
    {
        $service = $this->createService(
            [
                'preview_theme' => 10,
                'preview_area' => 'frontend',
            ],
            [
                'token' => 'pv_11_oldtoken',
                'theme_id' => 11,
                'version_id' => 88,
                'context' => [
                    'frontend_theme_id' => 11,
                    'preview_token' => 'pv_11_oldtoken',
                    'shell' => PreviewContextService::SHELL_PREVIEW,
                ],
            ]
        );

        $context = $service->getCurrentContext();

        $this->assertSame(10, $context['frontend_theme_id']);
        $this->assertSame('', $context['preview_token']);
        $this->assertNull($context['version_id']);
    }

    public function testExplicitFrontendThemeIdClearsStaleTokenContext(): void
    {
        $service = $this->createService(
            [
                'frontend_theme_id' => 10,
                'backend_theme_id' => 11,
                'editor_area' => 'frontend',
            ],
            [
                'token' => 'pv_11_oldtoken',
                'theme_id' => 11,
                'version_id' => 66,
                'context' => [
                    'frontend_theme_id' => 11,
                    'backend_theme_id' => 11,
                    'preview_token' => 'pv_11_oldtoken',
                ],
            ]
        );

        $context = $service->getCurrentContext();

        $this->assertSame(10, $context['frontend_theme_id']);
        $this->assertSame(11, $context['backend_theme_id']);
        $this->assertSame('', $context['preview_token']);
        $this->assertNull($context['version_id']);
    }

    public function testRawQueryFrontendThemeIdOverridesRequestParamBagValue(): void
    {
        $_SERVER['REQUEST_URI'] = '/theme/frontend/theme-preview/content?frontend_theme_id=6&preview_theme=6&editor_area=frontend';

        $service = $this->createService(
            [
                // 模拟 request bag 被旧上下文污染（与 URL 不一致）
                'frontend_theme_id' => 2,
                'preview_theme' => 2,
                'editor_area' => 'frontend',
            ],
            null
        );

        $context = $service->getCurrentContext();

        $this->assertSame(6, $context['frontend_theme_id']);
        $this->assertSame(0, $context['backend_theme_id']);
    }

    public function testRawQueryPreviewTokenOverridesRequestParamBagToken(): void
    {
        $_SERVER['REQUEST_URI'] = '/theme/frontend/theme-preview/content?frontend_theme_id=6&weline_preview_token=pv_6_raw_token';

        $service = $this->createService(
            [
                // 模拟 request bag 残留旧 token
                'frontend_theme_id' => 6,
                'weline_preview_token' => 'pv_2_stale_token',
            ],
            null
        );

        $context = $service->getCurrentContext();

        $this->assertSame('pv_6_raw_token', $context['preview_token']);
    }

    /**
     * @param array<string, mixed> $requestParams
     * @param array<string, mixed>|null $tokenData
     */
    private function createService(array $requestParams, ?array $tokenData = null): PreviewContextService
    {
        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($requestParams) {
                return $requestParams[$key] ?? $default;
            });

        $session = $this->createMock(Session::class);
        $session->method('getData')->willReturn(null);

        $previewTokenService = $this->createMock(PreviewTokenService::class);
        $previewTokenService->method('getCurrentPreviewData')->willReturn($tokenData);
        $previewTokenService->method('getTokenFromRequest')->willReturn(null);

        return new PreviewContextService(
            $request,
            $session,
            $previewTokenService,
            $this->createMock(WelineTheme::class),
        );
    }
}
