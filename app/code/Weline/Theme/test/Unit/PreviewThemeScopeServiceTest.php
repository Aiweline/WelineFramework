<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Session;
use Weline\I18n\Model\Locale\Dictionary;
use Weline\Meta\Model\MetaConfig;
use Weline\Theme\Service\PreviewRequestInspector;
use Weline\Theme\Service\PreviewThemeScopeService;
use Weline\Theme\Service\PreviewTokenService;

class PreviewThemeScopeServiceTest extends TestCase
{
    public function testDifferentPreviewTokensProduceDifferentScopes(): void
    {
        $serviceA = $this->createService(
            '/catalog/product/view',
            ['weline_preview_token' => 'pv_a'],
            'session-fixed',
            'pv_a'
        );
        $serviceB = $this->createService(
            '/catalog/product/view',
            ['weline_preview_token' => 'pv_b'],
            'session-fixed',
            'pv_b'
        );

        $scopeA = $serviceA->buildPreviewScope(12, 'default');
        $scopeB = $serviceB->buildPreviewScope(12, 'default');

        $this->assertNotSame($scopeA, $scopeB);
        $this->assertStringStartsWith(PreviewThemeScopeService::PREFIX, $scopeA);
        $this->assertStringStartsWith(PreviewThemeScopeService::PREFIX, $scopeB);
    }

    public function testDifferentVersionIdsProduceDifferentScopesWhenTokenMissing(): void
    {
        $serviceA = $this->createService(
            '/catalog/product/view',
            ['version_id' => 101, 'status' => 'draft'],
            'session-fixed',
            ''
        );
        $serviceB = $this->createService(
            '/catalog/product/view',
            ['version_id' => 202, 'status' => 'draft'],
            'session-fixed',
            ''
        );

        $scopeA = $serviceA->buildPreviewScope(12, 'default');
        $scopeB = $serviceB->buildPreviewScope(12, 'default');

        $this->assertNotSame($scopeA, $scopeB);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function createService(string $path, array $params, string $sessionId, string $previewToken): PreviewThemeScopeService
    {
        $request = $this->createMock(Request::class);
        $request->method('getUrlPath')->willReturn($path);
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($params) {
                return $params[$key] ?? $default;
            });

        $session = $this->createMock(Session::class);
        $session->method('getId')->willReturn($sessionId);

        $previewTokenService = $this->createMock(PreviewTokenService::class);
        $previewTokenService->method('getCurrentToken')->willReturn($previewToken !== '' ? $previewToken : null);

        return new PreviewThemeScopeService(
            $request,
            $session,
            new PreviewRequestInspector($request),
            $this->createMock(MetaConfig::class),
            $this->createMock(Dictionary::class),
            $previewTokenService,
        );
    }
}
