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

    public function testFrontendPreviewShellNormalizesEditorAreaToFrontend(): void
    {
        $_SERVER['REQUEST_URI'] = '/theme/frontend/theme-preview/content?frontend_theme_id=6&backend_theme_id=9&editor_area=backend&shell=preview';

        $service = $this->createService(
            [
                'frontend_theme_id' => 6,
                'backend_theme_id' => 9,
                'editor_area' => 'backend',
                'shell' => PreviewContextService::SHELL_PREVIEW,
            ],
            null
        );

        $context = $service->getCurrentContext();

        $this->assertSame(6, $context['frontend_theme_id']);
        $this->assertSame(9, $context['backend_theme_id']);
        $this->assertSame(PreviewContextService::AREA_FRONTEND, $context['editor_area']);
        $this->assertSame(PreviewContextService::SHELL_PREVIEW, $context['shell']);
    }

    public function testDirectPreviewUrlResetsStoredScopeAndTargetContext(): void
    {
        $_SERVER['REQUEST_URI'] = '/theme/frontend/theme-preview/content?theme_id=1&page_type=category&layout_type=category&layout_option=default&editor_area=frontend&preview_mode=live&status=draft';

        $service = $this->createService(
            [
                'theme_id' => 1,
                'page_type' => 'category',
                'layout_type' => 'category',
                'layout_option' => 'default',
                'editor_area' => 'frontend',
                'preview_mode' => 'live',
                'status' => 'draft',
            ],
            null,
            [
                'frontend_theme_id' => 1,
                'scope' => 'preview-stale-scope',
                'target_type' => PreviewContextService::TARGET_TYPE_PAGE,
                'target_value' => '77',
                'shell' => PreviewContextService::SHELL_THEME_EDITOR,
            ]
        );

        $context = $service->getCurrentContext();

        $this->assertSame(1, $context['frontend_theme_id']);
        $this->assertSame(PreviewContextService::DEFAULT_SCOPE, $context['scope']);
        $this->assertSame(PreviewContextService::TARGET_TYPE_LAYOUT, $context['target_type']);
        $this->assertSame('category.default', $context['target_value']);
        $this->assertSame(PreviewContextService::SHELL_PREVIEW, $context['shell']);
    }

    public function testVirtualThemeIdIsIgnoredByThemePreviewContext(): void
    {
        $service = $this->createService(
            [
                'virtual_theme_id' => 321,
                'editor_area' => 'frontend',
            ],
            null
        );

        $context = $service->getCurrentContext();

        $this->assertSame(0, $context['frontend_theme_id']);
        $this->assertSame(0, $context['backend_theme_id']);
        $this->assertSame(PreviewContextService::SHELL_THEME_EDITOR, $context['shell']);
    }

    /**
     * @param array<string, mixed> $requestParams
     * @param array<string, mixed>|null $tokenData
     * @param array<string, mixed>|null $storedContext
     */
    private function createService(
        array $requestParams,
        ?array $tokenData = null,
        ?array $storedContext = null
    ): PreviewContextService
    {
        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($requestParams) {
                return $requestParams[$key] ?? $default;
            });
        $request->method('getServer')
            ->willReturnCallback(static function (string $key, mixed $default = null) {
                return $_SERVER[$key] ?? $default;
            });
        $request->method('getUrlPath')
            ->willReturnCallback(static function () {
                $path = \parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);
                return \is_string($path) && $path !== '' ? $path : '/';
            });
        $request->method('getHeader')->willReturn(null);

        $session = $this->createMock(Session::class);
        $session->method('getData')
            ->willReturnCallback(static function (string $key = '') use ($storedContext) {
                return $key === PreviewContextService::SESSION_KEY ? $storedContext : null;
            });

        $previewTokenService = $this->createMock(PreviewTokenService::class);
        $previewTokenService->method('getCurrentPreviewData')->willReturn($tokenData);
        $previewTokenService->method('getTokenFromRequest')->willReturn(null);

        return new PreviewContextService(
            $request,
            $session,
            $previewTokenService,
            $this->createMock(WelineTheme::class),
            new PreviewRequestInspector($request),
        );
    }
}
