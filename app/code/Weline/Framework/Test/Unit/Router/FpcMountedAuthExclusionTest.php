<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Router\FullPageCacheCoordinator;

final class FpcMountedAuthExclusionTest extends TestCase
{
    public function testExistingPrivateRouteExclusionsApplyWithinWebsiteMount(): void
    {
        $previousWebsite = WelineEnv::get('website_url', '');
        $reflection = new \ReflectionClass(FullPageCacheCoordinator::class);
        $coordinator = $reflection->newInstanceWithoutConstructor();
        $excluded = $reflection->getMethod('isExcludedFrontendPath');
        WelineEnv::set('website_url', 'https://example.test/store/', 'isolated mounted route test');
        try {
            foreach ([
                '/customer/account/register',
                '/customer/account/forgot-password',
                '/store/customer/account/register',
                '/store/customer/account/forgot-password',
                '/store/zh_Hans_CN/customer/account/register',
                '/store/en_US/USD/customer/account/forgot-password',
                '/store/checkout',
                '/store/api/framework/query-bin',
                '/compare',
                '/store/compare',
                '/payment/success',
                '/store/payment/handoff',
                '/h/token',
                '/newsletter/subscribe',
                '/customerservice/frontend/bind/verify',
                '/multipass/frontend/identity/authorize',
                '/shipping/frontend/shipping-address',
                '/theme/frontend/theme-preview/gateway',
            ] as $path) {
                self::assertTrue($excluded->invoke($coordinator, 'https://example.test' . $path), $path);
            }
            foreach ([
                '/store/',
                '/store/products',
                '/store/en_US/USD/products',
                '/storefront/customer/account/register',
                // Search moved to short-TTL Extra; must not hard-exclude.
                '/search',
                '/store/search',
                '/store/en_US/search?q=hanfu',
            ] as $path) {
                self::assertFalse($excluded->invoke($coordinator, 'https://example.test' . $path), $path);
            }
        } finally {
            WelineEnv::set('website_url', $previousWebsite, 'restore mounted route test');
        }
    }
}
