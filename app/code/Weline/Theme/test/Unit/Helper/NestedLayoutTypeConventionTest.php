<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemeResourceCatalog;

/**
 * 嵌套 layoutType 约定：layouts/account/login/default.phtml
 * → layoutType=account/login，option=default。
 */
final class NestedLayoutTypeConventionTest extends TestCase
{
    public function testBuildLayoutPathKeepsNestedLayoutTypeDirectories(): void
    {
        $path = LayoutPathResolver::buildLayoutPath('', 'frontend', 'account/login', 'default');
        $normalized = str_replace('\\', '/', $path);

        self::assertSame('theme/frontend/layouts/account/login/default.phtml', $normalized);
    }

    public function testParseLayoutPathTreatsPrefixAsLayoutType(): void
    {
        $method = (new ReflectionClass(LayoutPathResolver::class))->getMethod('parseLayoutPath');
        $method->setAccessible(true);

        $parsed = $method->invoke(null, 'theme/frontend/layouts/account/login/default.phtml', 'frontend');

        self::assertIsArray($parsed);
        self::assertSame('frontend', $parsed['area']);
        self::assertSame('account/login', $parsed['type']);
        self::assertSame('default', $parsed['option']);
    }

    public function testCatalogResolveTypeAndOptionUsesTrailingFileAsOption(): void
    {
        $method = (new ReflectionClass(ThemeResourceCatalog::class))->getMethod('resolveTypeAndOption');
        $method->setAccessible(true);
        $catalog = (new ReflectionClass(ThemeResourceCatalog::class))->newInstanceWithoutConstructor();

        self::assertSame(
            ['account/login', 'default'],
            $method->invoke($catalog, 'account/login/default.phtml')
        );
        self::assertSame(
            ['account', 'auth'],
            $method->invoke($catalog, 'account/auth.phtml')
        );
        self::assertSame(
            ['homepage', 'default'],
            $method->invoke($catalog, 'homepage.phtml')
        );
    }

    public function testNestedAccountLayoutTypeMapsToAccountPageType(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame('account/login', $resolver->mapLayoutTypeToPageType('account/login'));
        self::assertSame('account/register', $resolver->mapLayoutTypeToPageType('account/register'));
        self::assertSame('account/login', $resolver->resolveLayoutTypeFromUri('/customer/account/login'));
    }

    public function testCustomerLoginLayoutFileExistsAtNestedPath(): void
    {
        $path = dirname(__DIR__, 3) . '/../Customer/view/theme/frontend/layouts/account/login/default.phtml';
        $path = realpath($path) ?: $path;

        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("layoutType=account/login", $source);
        self::assertStringContainsString("option=default", $source);
    }
}
