<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class StorefrontVariantAxisResolverTest extends TestCase
{
    public function testStorefrontDoesNotMergeGlobalEavSwatchImage(): void
    {
        $resolver = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontVariantAxisResolver.php',
        );
        $bootstrap = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/ProductCatalogEavBootstrap.php',
        );
        $previewPatch = (string)file_get_contents(
            BP . 'app/code/Weline/Product/scripts/patch-hanfu-variant-previews.php',
        );

        self::assertStringContainsString('全局 EAV 选项图板仅表示', $resolver);
        self::assertStringNotContainsString('$eavOption[\'swatch_image\']', $resolver);
        self::assertStringContainsString('clearVariantOptionSwatchImages', $bootstrap);
        self::assertStringNotContainsString('syncGlobalColorOptions', $previewPatch);
        self::assertStringContainsString('禁止把商品图同步到全局 EAV 选项', $previewPatch);
    }

    public function testProductTypeConfigurationKeepsVariantPreviewImages(): void
    {
        $resolver = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontVariantAxisResolver.php',
        );

        self::assertStringContainsString('gallery_by_color', $resolver);
        self::assertStringContainsString('resolveOptionGallery', $resolver);
        self::assertStringContainsString('$option[\'swatch_image\']', $resolver);
    }

    public function testVariantAxisLabelsPassThroughI18nHelper(): void
    {
        $resolver = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontVariantAxisResolver.php',
        );
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml',
        );
        $enUs = (string)file_get_contents(
            BP . 'app/code/Weline/Product/i18n/en_US.csv',
        );

        self::assertStringContainsString("\$label = (string)__(\$label);", $resolver);
        self::assertStringContainsString('array_key_exists($axisCode, $specificationLabels)', $template);
        self::assertStringContainsString('(string)__($rawAxisLabel)', $template);
        self::assertStringContainsString('类型,Type', $enUs);
        self::assertStringContainsString('颜色,Color', $enUs);
        self::assertStringContainsString('尺码,Size', $enUs);
    }
}
