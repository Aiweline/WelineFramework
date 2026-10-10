<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Guest-safe widget HTML output cache lives on ThemeComponentRenderer — not SlotRenderer.
 */
final class ThemeComponentWidgetOutputCacheContractTest extends TestCase
{
    public function testThemeComponentRendererUsesSharedTtlKnobWithoutRegistryFallback(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ThemeComponentRenderer.php',
        );

        self::assertStringContainsString('WidgetOutputCache', $src);
        self::assertStringContainsString('resolveWidgetOutputCacheTtl', $src);
        self::assertStringContainsString('buildWidgetOutputCacheKey', $src);
        self::assertStringContainsString('preview_mode', $src);
        self::assertStringNotContainsString('TemplateCachePolicyRegistry', $src);
        self::assertStringNotContainsString('cache_ttl', $src);
        self::assertStringContainsString('runtimeTemplateMaterializer->renderFile', $src);
        self::assertStringContainsString('fetchHtml($templatePath', $src);
        self::assertStringContainsString('node_uid', $src);
        self::assertStringContainsString('resolveLayoutName', $src);
        self::assertStringContainsString('layout_name', $src);
        self::assertStringContainsString('resolveShare', $src);
        self::assertStringContainsString("'share'", $src);
    }

    public function testSolidifiedResolverStampsRequestLayoutName(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/SolidifiedControllerTemplateResolver.php',
        );
        self::assertStringContainsString('REQUEST_LAYOUT_NAME_KEY', $src);
        self::assertStringContainsString('layoutType . \'.\' . $layoutOption', $src);
    }

    public function testMaterializerStampsLayoutNameOnNodes(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php',
        );
        self::assertStringContainsString("node['layout_name']", $src);
        self::assertStringContainsString('layout_type', $src);
    }

    public function testSlotRendererStillForbidsWidgetHtmlCache(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SlotRendererService.php',
        );

        self::assertStringNotContainsString('private array $widgetCache', $src);
        self::assertStringNotContainsString('rememberWidgetOutput', $src);
        self::assertStringContainsString('no widget HTML cache', $src);
        self::assertStringNotContainsString('function buildWidgetOutputCacheKey', $src);
    }

    public function testLayoutRelationCompilerCopiesCacheAttribute(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/LayoutRelationCompiler.php',
        );
        self::assertStringContainsString("'cache'", $src);
        self::assertStringContainsString('sourceAttributes[\'cache\']', $src);
        self::assertStringContainsString('WidgetOutputCache::normalizeTtl', $src);
        self::assertStringContainsString("'layout_name'", $src);
        self::assertStringContainsString("'layout_type'", $src);
        self::assertStringContainsString("'share'", $src);
        self::assertStringContainsString('normalizeShare', $src);
    }

    public function testProductInfoMustNotEnableOutputCache(): void
    {
        $path = dirname(__DIR__, 4) . '/Product/view/templates/frontend/widgets/product-info.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringNotContainsString('@widget.cache', $src);
    }

    public function testWidgetProvidersNoLongerDuplicateWidgetTemplatePolicies(): void
    {
        $shipping = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Shipping/Api/View/TemplateCachePolicyProvider.php',
        );
        self::assertStringNotContainsString('product-delivery-mode.phtml', $shipping);
        self::assertStringContainsString('hooks/header-account-links.phtml', $shipping);

        self::assertFileDoesNotExist(
            dirname(__DIR__, 4) . '/Payment/Api/View/TemplateCachePolicyProvider.php',
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4) . '/B2B/Api/View/TemplateCachePolicyProvider.php',
        );

        $paymentModule = (string)file_get_contents(dirname(__DIR__, 4) . '/Payment/etc/module.php');
        self::assertStringNotContainsString('template_cache_policy.Weline_Payment', $paymentModule);
        $b2bModule = (string)file_get_contents(dirname(__DIR__, 4) . '/B2B/etc/module.php');
        self::assertStringNotContainsString('template_cache_policy.Weline_B2B', $b2bModule);
    }
}
