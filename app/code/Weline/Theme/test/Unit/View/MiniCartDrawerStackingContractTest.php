<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 迷你购物车抽屉层叠契约（与主题 header 是否定位无关）：
 *
 * 主题可把 `.weline-header` 渲染为 position:static（如非吸顶 header），此时 header 上的 z-index
 * 抬升不建立层叠上下文，`.header-cart.is-drawer-open` 自身直接参与根层叠——它也必须携带
 * `--weline-z-mini-cart-modal`，否则店面浮层（#w-storefront-float-layer）的分享/收起按钮压在抽屉上。
 */
final class MiniCartDrawerStackingContractTest extends TestCase
{
    public function testOpenCartWrapperCarriesModalLevelAboveStorefrontFloatLayer(): void
    {
        $css = (string) \file_get_contents(BP . 'app/code/Weline/Theme/view/statics/css/widgets/mini-cart-drawer.css');
        self::assertSame(
            1,
            \preg_match('/\.weline-header \.header-cart\.is-drawer-open\s*\{[^}]*?z-index:\s*var\(--weline-z-mini-cart-modal,\s*(\d+)\)/s', $css, $wrapper),
            'open .header-cart must carry the modal z-index token itself',
        );
        self::assertSame(
            1,
            \preg_match('/\.weline-header:has\(\.header-cart\.is-drawer-open\)\s*\{[^}]*?z-index:\s*var\(--weline-z-mini-cart-modal,\s*(\d+)\)/s', $css, $header),
        );
        self::assertSame($header[1], $wrapper[1]);

        $host = (string) \file_get_contents(BP . 'app/code/Weline/Theme/Service/LayoutEntity/StorefrontFloatLayerHost.php');
        self::assertSame(1, \preg_match('/#w-storefront-float-layer\{[^}]*z-index:(\d+)/', $host, $float));
        self::assertGreaterThan((int) $float[1], (int) $wrapper[1]);
        self::assertLessThan(2147483100, (int) $wrapper[1], 'must stay below theme editor / preview tooling');
    }
}
