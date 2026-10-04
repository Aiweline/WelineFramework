<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeResourceGateway;

final class ThemeResourceGatewayModuleNamespaceTest extends TestCase
{
    public function testModuleDefaultAssetsKeepTheirCompletePublicThemeIdentity(): void
    {
        $gateway = (new \ReflectionClass(ThemeResourceGateway::class))->newInstanceWithoutConstructor();
        $parse = new \ReflectionMethod($gateway, 'parseThemeRequestPath');
        foreach (['', '__preview/ctx_0123456789abcdef/', '__preview/token_pv_fixture/'] as $prefix) {
            foreach (['frontend', 'backend'] as $area) {
                $resource = $parse->invoke($gateway, '/static/' . $prefix . 'Weline/Theme/view/theme/' . $area . '/colors/_default.css');
                self::assertIsArray($resource);
                self::assertSame($prefix . 'Weline/Theme/view/theme', $resource['public_theme_path']);
                self::assertSame('Weline', $resource['vendor']);
                self::assertSame('Theme', $resource['module']);
                self::assertSame($area, $resource['area']);
                self::assertSame('colors' . DIRECTORY_SEPARATOR . '_default.css', $resource['relative_path']);
            }
        }
    }

    public function testDesignNamespaceIsStillDistinctFromModuleDefault(): void
    {
        $gateway = (new \ReflectionClass(ThemeResourceGateway::class))->newInstanceWithoutConstructor();
        $resource = (new \ReflectionMethod($gateway, 'parseThemeRequestPath'))->invoke($gateway,
            '/static/__preview/ctx_0123456789abcdef/Weline/hanfu/Weline/Theme/view/theme/frontend/colors/_default.css');
        self::assertSame('__preview/ctx_0123456789abcdef/Weline/hanfu', $resource['public_theme_path']);
        self::assertSame('Weline', $resource['vendor']);
        self::assertSame('Theme', $resource['module']);
    }
}
