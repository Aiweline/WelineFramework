<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Theme\Service\LayoutStorefrontRouteFromModuleRouter;

/**
 * Contract: layout → catalog module_name → getModuleInfo router → storefront path.
 */
final class LayoutStorefrontRouteFromModuleRouterTest extends TestCase
{
    public function testCustomerAccountLoginJoinsModuleRouter(): void
    {
        $info = Env::getInstance()->getModuleInfo('Weline_Customer');
        self::assertIsArray($info);
        self::assertSame('customer', strtolower(trim((string)($info['router'] ?? ''))));

        $resolver = new LayoutStorefrontRouteFromModuleRouter();
        self::assertSame('customer/account/login', $resolver->resolve('account/login', 'default'));
        self::assertSame('customer/account', $resolver->resolve('account', 'dashboard'));
        self::assertSame('customer/account', $resolver->resolve('account.dashboard', 'default'));
        self::assertSame('customer/account/challenge', $resolver->resolve('account.challenge', 'default'));
    }

    public function testThemeOwnedProductsStaysOneToOne(): void
    {
        $resolver = new LayoutStorefrontRouteFromModuleRouter();
        self::assertSame('products', $resolver->resolve('products', 'default'));
        self::assertSame('', $resolver->resolve('homepage', 'default'));
    }

    public function testCheckoutSuccessLayoutMapsToSlashRoute(): void
    {
        $resolver = new LayoutStorefrontRouteFromModuleRouter();
        self::assertSame('checkout/success', $resolver->resolve('checkout/success', 'default'));
        self::assertSame('checkout/success', $resolver->resolve('checkout_success', 'default'));
        self::assertSame('checkout', $resolver->resolve('checkout', 'default'));
    }

    public function testEditorJsDoesNotBlindReturnLayoutTypeAsPath(): void
    {
        $ui = dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js';
        $js = dirname(__DIR__, 3) . '/view/statics/js/theme-editor.js';
        self::assertFileExists($ui);
        self::assertFileExists($js);

        foreach ([$ui, $js] as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringContainsString('isHomepageLayoutType', $source);
            self::assertStringContainsString('Wait for preview-sample', $source);
            self::assertStringContainsString('Always reverse-resolve storefront path', $source);
            self::assertStringNotContainsString(
                '// Layout-dropdown fallback only: path equals layout type (no alias table).',
                $source
            );
        }
    }

    public function testLayoutResolveServiceFallsBackToModuleRouterJoin(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutResolveService.php'
        );
        self::assertStringContainsString('claimFromModuleRouter', $source);
        self::assertStringContainsString('LayoutStorefrontRouteFromModuleRouter', $source);
        self::assertStringContainsString("sample_source', 'module_router'", $source);
    }
}
