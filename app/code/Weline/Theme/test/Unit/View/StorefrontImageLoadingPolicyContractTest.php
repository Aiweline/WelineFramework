<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class StorefrontImageLoadingPolicyContractTest extends TestCase
{
    private function script(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/storefront-image-fallback.js',
        );
    }

    public function testAutoLazyAndPredictPrefetchMarkersExist(): void
    {
        $script = $this->script();
        self::assertStringContainsString('storefrontAutoLazy', $script);
        self::assertStringContainsString('storefrontPredictPrefetch', $script);
        self::assertStringContainsString('storefrontPredictRootMargin', $script);
        self::assertStringContainsString("setAttribute('loading', 'lazy')", $script);
        self::assertStringContainsString('data-auto-lazy', $script);
        self::assertStringContainsString('IntersectionObserver', $script);
        self::assertStringContainsString('data-predict-prefetch', $script);
        self::assertStringContainsString('applyStorefrontImageLoadingPolicy', $script);
        self::assertStringContainsString('shouldSkipAutoLazy', $script);
        self::assertStringContainsString('data-no-auto-lazy', $script);
        self::assertStringContainsString('fetchpriority', $script);
    }

    public function testFallbackBindingRemains(): void
    {
        $script = $this->script();
        self::assertStringContainsString('data-storefront-img-ready', $script);
        self::assertStringContainsString('bindStorefrontImages', $script);
        self::assertStringContainsString('weline:widget-rendered', $script);
        self::assertStringContainsString('data:image/', $script);
    }

    public function testModuleStillRegistersFallbackScript(): void
    {
        $modules = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js',
        );
        self::assertStringContainsString('storefrontImageFallback', $modules);
        self::assertStringContainsString('Weline_Theme::js/storefront-image-fallback.js', $modules);
    }
}
