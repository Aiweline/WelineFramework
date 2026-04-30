<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

class ThemeContextServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        $basePath = dirname(__DIR__, 6) . DIRECTORY_SEPARATOR;
        if (!defined('BP')) {
            define('BP', $basePath);
        }
        if (!defined('APP_PATH')) {
            define('APP_PATH', BP . 'app' . DIRECTORY_SEPARATOR);
        }
        if (!defined('APP_CODE_PATH')) {
            define('APP_CODE_PATH', APP_PATH . 'code' . DIRECTORY_SEPARATOR);
        }
        if (!defined('APP_ETC_PATH')) {
            define('APP_ETC_PATH', APP_PATH . 'etc' . DIRECTORY_SEPARATOR);
        }
        if (!defined('PUB')) {
            define('PUB', BP . 'pub' . DIRECTORY_SEPARATOR);
        }

        require_once BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Framework' . DIRECTORY_SEPARATOR . 'Common' . DIRECTORY_SEPARATOR . 'functions.php';
    }

    public function testGetActivationFieldSupportsBackendFallbackFieldName(): void
    {
        $svc = new ThemeContextService($this->createMock(WelineTheme::class));
        $this->assertSame('is_active_backend', $svc->getActivationField('backend'));
    }

    public function testResolveThemeFallsBackToModuleDefaultThemeWhenDatabaseHasNoActiveTheme(): void
    {
        $themeModel = new class extends WelineTheme {
            public function clearData(bool $with_query = true): static
            {
                return $this;
            }

            public function clearQuery(): static
            {
                return $this;
            }

            public function getActiveTheme(): static
            {
                $this->setData('id', 0);
                return $this;
            }
        };

        $svc = new ThemeContextService($themeModel);
        $theme = $svc->resolveTheme('backend', null, false);

        $this->assertNotNull($theme);
        $this->assertSame('Weline_Theme', $theme->getModuleName());
        $this->assertTrue($svc->themeSupportsArea($theme, 'backend'));
    }

    public function testResolveThemePrefersModuleDefaultThemeOverLegacyGlobalFallbackForBackend(): void
    {
        $themeModel = new class extends WelineTheme {
            public function clearData(bool $with_query = true): static
            {
                return $this;
            }

            public function clearQuery(): static
            {
                return $this;
            }

            public function load(string|int $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
            {
                if ($field_or_pk_value === 'is_active_backend') {
                    $this->setData('id', 0);
                    return $this;
                }
                if ($field_or_pk_value === 'is_active') {
                    $this->setData('id', 88);
                    $this->setData('module_name', 'Custom_Global_Theme');
                    return $this;
                }
                return $this;
            }
        };

        $svc = new ThemeContextService($themeModel);
        $theme = $svc->resolveTheme('backend', null, false);

        $this->assertNotNull($theme);
        $this->assertSame('Weline_Theme', $theme->getModuleName());
    }

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
