<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

class ThemeContextServiceTest extends TestCase
{
    public function testResolveAreaAndScopeParsesCompoundScope(): void
    {
        $svc = new ThemeContextService($this->createMock(WelineTheme::class));
        [$area, $scope] = $svc->resolveAreaAndScope('frontend', 'backend/shop');
        $this->assertSame('backend', $area);
        $this->assertSame('shop', $scope);
    }

    public function testResolveAreaAndScopeKeepsAreaWhenScopePlain(): void
    {
        $svc = new ThemeContextService($this->createMock(WelineTheme::class));
        [$area, $scope] = $svc->resolveAreaAndScope('frontend', 'default');
        $this->assertSame('frontend', $area);
        $this->assertSame('default', $scope);
    }

    public function testNormalizeActivationArea(): void
    {
        $svc = new ThemeContextService($this->createMock(WelineTheme::class));
        $this->assertSame('frontend', $svc->normalizeActivationArea('frontend'));
        $this->assertSame('backend', $svc->normalizeActivationArea('backend'));
        $this->assertNull($svc->normalizeActivationArea('global'));
        $this->assertNull($svc->normalizeActivationArea(''));
    }

    public function testExtractScopeForAreaRejectsWrongAreaPrefix(): void
    {
        $svc = new ThemeContextService($this->createMock(WelineTheme::class));
        $this->assertNull($svc->extractScopeForArea('frontend', 'backend/default'));
    }

    public function testExtractScopeForAreaAcceptsMatchingPrefix(): void
    {
        $svc = new ThemeContextService($this->createMock(WelineTheme::class));
        $this->assertSame('vip', $svc->extractScopeForArea('frontend', 'frontend/vip'));
    }
}
