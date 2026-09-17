<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Ui\ParamType;

use PHPUnit\Framework\TestCase;
use Weline\Product\Ui\ParamType\ProductPickerType;
use Weline\Widget\Service\ParamTypeRenderer;

final class ProductPickerTypeContractTest extends TestCase
{
    public function testTypeCodeAndRendererRegistration(): void
    {
        $type = new ProductPickerType();
        self::assertSame('product_picker', $type->getTypeCode());

        $renderer = new ParamTypeRenderer();
        self::assertSame('product_picker', $renderer->normalizeType('product_picker'));
        self::assertContains('product_picker', $renderer->getRegisteredTypes());
        self::assertInstanceOf(ProductPickerType::class, $renderer->getRenderer('product_picker'));
    }

    public function testProcessValueNormalizesIds(): void
    {
        $type = new ProductPickerType();
        self::assertSame('12,34', $type->processValue(['12', '34', '12'], []));
        self::assertSame('12,34', $type->processValue('12, 34;12', []));
        self::assertSame('', $type->processValue('', []));
    }

    public function testSourceWiresAdminPickerSyncAndDisablesI18n(): void
    {
        $productRoot = dirname(__DIR__, 4);
        $pickerType = $productRoot . '/Ui/ParamType/ProductPickerType.php';
        $pickerJs = $productRoot . '/view/statics/js/backend/product-admin-picker.js';
        $widgetParamJs = dirname($productRoot) . '/Widget/view/statics/js/widget-param-types.js';
        $carousel = dirname($productRoot) . '/Theme/view/theme/frontend/widgets/carousel/product-carousel/default.phtml';

        self::assertFileExists($pickerType);
        self::assertFileExists($pickerJs);
        self::assertFileExists($widgetParamJs);
        self::assertFileExists($carousel);

        $typeSrc = (string)file_get_contents($pickerType);
        $jsSrc = (string)file_get_contents($pickerJs);
        $paramJs = (string)file_get_contents($widgetParamJs);
        $carouselSrc = (string)file_get_contents($carousel);

        self::assertStringContainsString('data-sync-input', $typeSrc);
        self::assertStringContainsString('data-product-admin-picker', $typeSrc);
        self::assertStringContainsString('data-product-admin-picker-open', $typeSrc);
        self::assertStringContainsString('w-dialog', $typeSrc);
        self::assertStringContainsString('data-product-admin-picker-dialog', $typeSrc);
        self::assertStringContainsString('ProductAdminReadService', $typeSrc);
        self::assertStringContainsString('product_ids', $typeSrc);
        self::assertStringContainsString('formatSelectedLabel', $jsSrc);
        self::assertStringContainsString('enrichSelectedLabels', $jsSrc);
        self::assertStringContainsString('extractPrice', $jsSrc);
        self::assertStringContainsString('price_label', $jsSrc);
        self::assertStringContainsString('formatPriceLabel', $typeSrc);
        self::assertStringContainsString("'price' =>", $typeSrc);
        self::assertStringContainsString("'i18n' => false", $typeSrc);
        self::assertStringContainsString('Weline.Product.AdminPicker', $jsSrc);
        self::assertStringContainsString('ui.dialog.open', $jsSrc);
        self::assertStringContainsString('data-sync-input', $jsSrc);
        self::assertStringContainsString('initProductAdminPickers', $paramJs);
        self::assertStringContainsString('type="product_picker"', $carouselSrc);
        self::assertStringContainsString('i18n=false', $carouselSrc);
    }
}
