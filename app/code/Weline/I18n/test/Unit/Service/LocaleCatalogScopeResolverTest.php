<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\LocaleCatalogScope;
use Weline\I18n\Service\LocaleCatalogScopeResolver;

final class LocaleCatalogScopeResolverTest extends TestCase
{
    private LocaleCatalogScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new LocaleCatalogScopeResolver();
    }

    public function testInjectedModeUsesExplicitCodesAndDisablesRequest(): void
    {
        $scope = $this->resolver->resolve(
            isBackendArea: false,
            websiteId: 0,
            injectedCodes: ['en_US', 'zh_Hans_CN'],
            currentOverride: 'ja_JP',
        );

        self::assertSame(LocaleCatalogScope::MODE_INJECTED, $scope->mode);
        self::assertSame(['en_US', 'zh_Hans_CN'], $scope->codes);
        self::assertSame('en_US', $scope->defaultCode);
        self::assertSame('en_US', $scope->currentCode);
        self::assertFalse($scope->allowRequest);
    }

    public function testInjectedModeKeepsCurrentWhenInList(): void
    {
        $scope = $this->resolver->resolve(
            isBackendArea: true,
            websiteId: 0,
            injectedCodes: ['zh_Hans_CN', 'en_US'],
            currentOverride: 'en_US',
        );

        self::assertSame('en_US', $scope->currentCode);
        self::assertSame('en_US', $scope->displayLocale);
    }

    public function testBackendPlatformModeIgnoresWebsiteIdUnlessForced(): void
    {
        $scope = $this->resolver->resolve(
            isBackendArea: true,
            websiteId: 12,
            injectedCodes: [],
            currentOverride: 'zh_Hans_CN',
        );

        self::assertSame(LocaleCatalogScope::MODE_BACKEND_PLATFORM, $scope->mode);
        self::assertNotEmpty($scope->codes);
        self::assertContains($scope->currentCode, $scope->codes);
        self::assertContains($scope->defaultCode, $scope->codes);
        self::assertFalse($scope->allowRequest);
    }

    public function testForcedWebsiteIdUsesWebsiteModeEvenInBackend(): void
    {
        $scope = $this->resolver->resolve(
            isBackendArea: true,
            websiteId: 0,
            injectedCodes: [],
            currentOverride: 'en_US',
            showRequestOverride: false,
            websiteIdAttr: 0,
        );

        self::assertSame(LocaleCatalogScope::MODE_WEBSITE, $scope->mode);
        self::assertSame(0, $scope->websiteId);
        self::assertNotEmpty($scope->codes);
        self::assertContains($scope->defaultCode, $scope->codes);
        self::assertContains($scope->currentCode, $scope->codes);
        // Empty website languages must not explode into a giant catalog.
        self::assertLessThanOrEqual(64, \count($scope->codes));
    }

    public function testCurrentOutsideCodesFallsBackToDefault(): void
    {
        $scope = $this->resolver->resolve(
            isBackendArea: false,
            websiteId: 0,
            injectedCodes: ['zh_Hans_CN'],
            currentOverride: 'ar_SA',
        );

        self::assertSame('zh_Hans_CN', $scope->currentCode);
        self::assertSame('zh_Hans_CN', $scope->defaultCode);
    }
}
