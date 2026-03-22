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
