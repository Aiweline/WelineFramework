<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Router\FullPageCacheCoordinator;

/**
 * 硬门精确豁免表：只放行「公开只读」的整条路径，不做段级放宽。
 */
final class HardGateExemptPathTest extends TestCase
{
    public function testSwitcherCatalogIsExemptWhileItsFamilyStaysHardExcluded(): void
    {
        $previousWebsite = WelineEnv::get('website_url', '');
        $reflection = new \ReflectionClass(FullPageCacheCoordinator::class);
        $coordinator = $reflection->newInstanceWithoutConstructor();
        $excluded = $reflection->getMethod('isExcludedFrontendPath');
        WelineEnv::set('website_url', '', 'hard gate exempt path test');
        try {
            // 精确豁免：公开只读的 i18n 语言切换目录
            self::assertFalse(
                $excluded->invoke($coordinator, 'https://example.test/i18n/frontend/switcher/catalog'),
                'switcher catalog must be exempt'
            );
            self::assertFalse(
                $excluded->invoke($coordinator, 'https://example.test/zh_Hans_CN/i18n/frontend/switcher/catalog?display_locale_code=zh_Hans_CN'),
                'locale prefix and query string must not defeat the exemption'
            );

            // 同族其余 i18n 私有面仍硬排除
            self::assertTrue(
                $excluded->invoke($coordinator, 'https://example.test/i18n/frontend/inline-edit/collect'),
                'inline-edit stays hard excluded'
            );

            // 豁免是整路径精确匹配，不是前缀放宽
            self::assertTrue(
                $excluded->invoke($coordinator, 'https://example.test/i18n/frontend/switcher/catalog/extra'),
                'exemption must not widen to descendants'
            );

            // 段级黑名单本身未被削弱
            self::assertTrue(
                $excluded->invoke($coordinator, 'https://example.test/customer/order/list'),
                'customer segment stays excluded'
            );

            // 静态资源判定不受豁免影响
            self::assertTrue(
                $excluded->invoke($coordinator, 'https://example.test/static/app.js'),
                'static assets stay excluded'
            );
        } finally {
            WelineEnv::set('website_url', $previousWebsite, 'restore hard gate exempt path test');
        }
    }
}
