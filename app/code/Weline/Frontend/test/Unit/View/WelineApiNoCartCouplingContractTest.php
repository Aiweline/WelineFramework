<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * weline-api 传输核不得内嵌购物车状态键 / markCart*。
 */
final class WelineApiNoCartCouplingContractTest extends TestCase
{
    public function testWelineApiJsHasNoCartStatusKeysOrMarkCart(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api.js'
        );

        self::assertStringNotContainsString('cartFlagStorageKey', $js);
        self::assertStringNotContainsString('cartProbeSessionKey', $js);
        self::assertStringNotContainsString('cartCountCookieKey', $js);
        self::assertStringNotContainsString('autoEnableOnCartClickSelector', $js);
        self::assertStringNotContainsString('weline_cart_has_items', $js);
        self::assertStringNotContainsString('weline_cart_item_count', $js);
        self::assertStringNotContainsString('restoreCartState', $js);
        self::assertStringNotContainsString('markCartActive', $js);
        self::assertStringNotContainsString('markCartEmpty', $js);
        self::assertStringNotContainsString('listenCartTriggers', $js);
        self::assertStringContainsString('enableAutoRequests', $js);
        self::assertStringContainsString('weline:api:auto-enabled', $js);
    }

    public function testFrontendRuntimeApiConfigOmitsCartCookieKey(): void
    {
        $phtml = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/blocks/header/base.phtml'
        );
        self::assertStringNotContainsString('cartCountCookieKey', $phtml);
    }
}
