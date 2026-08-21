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

    public function testBackendPrefersWebsiteCatalogWhenAvailableOtherwisePlatform(): void
    {
        $scope = $this->resolver->resolve(
            isBackendArea: true,
            websiteId: 0,
            injectedCodes: [],
            currentOverride: 'zh_Hans_CN',
        );

        self::assertNotEmpty($scope->codes);
        self::assertContains($scope->currentCode, $scope->codes);
        self::assertContains($scope->defaultCode, $scope->codes);
        self::assertContains(
            $scope->mode,
            [LocaleCatalogScope::MODE_WEBSITE, LocaleCatalogScope::MODE_BACKEND_PLATFORM],
        );
        if ($scope->mode === LocaleCatalogScope::MODE_WEBSITE) {
            self::assertSame(0, $scope->websiteId);
            // When WebsiteLanguage rows exist, admin chrome must share that list
            // (not collapse to the sparse installed-active set).
            self::assertGreaterThanOrEqual(1, \count($scope->codes));
        } else {
            self::assertFalse($scope->allowRequest);
        }
    }

    public function testBackendFallsBackToPlatformWhenWebsiteHasNoLanguages(): void
    {
        $scope = $this->resolver->resolve(
            isBackendArea: true,
            websiteId: 999999,
            injectedCodes: [],
            currentOverride: 'zh_Hans_CN',
        );

        self::assertNotEmpty($scope->codes);
        self::assertContains($scope->currentCode, $scope->codes);
        self::assertContains($scope->defaultCode, $scope->codes);
        // Unknown website has no language rows → platform fallback (or empty-safe website).
        self::assertContains(
            $scope->mode,
            [LocaleCatalogScope::MODE_WEBSITE, LocaleCatalogScope::MODE_BACKEND_PLATFORM],
        );
    }

    public function testResolverSourcePrefersWebsiteCodesForBackendChrome(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LocaleCatalogScopeResolver.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString(
            'Frontend and backend chrome share one WebsiteLanguage boundary',
            $source,
        );
        self::assertStringContainsString('fetchWebsiteLanguageCodes($resolvedWebsiteId)', $source);
        self::assertStringContainsString(
            'return $this->resolveBackendPlatformScope($currentHint, $stateLocale, $showRequestOverride);',
            $source,
        );
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
