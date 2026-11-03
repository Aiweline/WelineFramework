<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Service\Cdn;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Cdn\GlobalRegionImpactResolver;

/**
 * CDN 渐进失效的判定核心：变更路径 → 共享/精确/静态三类影响面。
 *
 * 这层不需要任何 CDN 凭据，先把它钉死，再谈真正的 purge 适配器。
 */
class GlobalRegionImpactResolverTest extends TestCase
{
    private GlobalRegionImpactResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new GlobalRegionImpactResolver();
    }

    public function testGlobalRegionFragmentInvalidatesEveryPage(): void
    {
        $r = $this->resolver->resolve(['app/code/Weline/Consent/view/theme/base/body_end/consent/01-banner.phtml']);

        self::assertTrue($r['shared'], '全局区片段必须共享失效');
        self::assertSame([], $r['exact'], '共享失效时不保留精确列表');
        self::assertSame('shared:global_region_fragment', $r['reasons']['app/code/Weline/Consent/view/theme/base/body_end/consent/01-banner.phtml']);
    }

    public function testThemeOverlayFragmentIsSharedToo(): void
    {
        $r = $this->resolver->resolve(['app/design/Weline/hanfu/frontend/widget/base/body_end/consent/01-banner.phtml']);

        self::assertTrue($r['shared']);
    }

    public function testChromePartialIsShared(): void
    {
        $r = $this->resolver->resolve(['app/design/Weline/hanfu/frontend/partials/footer/default.phtml']);

        self::assertTrue($r['shared']);
        self::assertSame('shared:chrome_partial', $r['reasons']['app/design/Weline/hanfu/frontend/partials/footer/default.phtml']);
    }

    public function testLayoutChangeIsExactToThatPageType(): void
    {
        $r = $this->resolver->resolve([
            'app/design/Weline/hanfu/frontend/layouts/products/default.phtml',
            'app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml',
        ]);

        self::assertFalse($r['shared']);
        self::assertSame(['products', 'homepage'], $r['exact']);
    }

    public function testStaticAssetDoesNotInvalidateHtml(): void
    {
        $r = $this->resolver->resolve(['app/code/Weline/Theme/view/statics/js/global-region-async.js']);

        self::assertTrue($r['static']);
        self::assertFalse($r['shared']);
        self::assertSame([], $r['exact']);
    }

    public function testUnknownPathFallsBackToShared(): void
    {
        $r = $this->resolver->resolve(['app/code/Weline/Theme/Service/Region/FragmentRegionProvider.php']);

        self::assertTrue($r['shared'], '认不出的改动按共享失效处理，宁可多清不可漏清');
        self::assertSame('shared:unknown_conservative', $r['reasons']['app/code/Weline/Theme/Service/Region/FragmentRegionProvider.php']);
    }

    public function testMixedChangesCollapseToShared(): void
    {
        $r = $this->resolver->resolve([
            'app/design/Weline/hanfu/frontend/layouts/about/default.phtml',
            'app/code/Weline/Visitor/view/theme/base/head/analytics/01-pixel.phtml',
        ]);

        self::assertTrue($r['shared']);
        self::assertSame([], $r['exact']);
    }
}
