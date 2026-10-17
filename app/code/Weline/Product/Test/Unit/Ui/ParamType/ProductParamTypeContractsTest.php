<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Ui\ParamType;

use PHPUnit\Framework\TestCase;
use Weline\Product\Ui\ParamType\ProductPickerType;
use Weline\Widget\Service\ParamTypeRenderer;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductParamTypeContractsPass().
 */
final class ProductParamTypeContractsTest extends TestCase
{
    public function testAllProductParamTypeContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ProductPickerType::testTypeCodeAndRendererRegistration' => function () {
            $this->assert_ProductPickerType_testTypeCodeAndRendererRegistration();
        },
            'ProductPickerType::testProcessValueNormalizesIds' => function () {
            $this->assert_ProductPickerType_testProcessValueNormalizesIds();
        },
            'ProductPickerType::testSourceWiresAdminPickerSyncAndDisablesI18n' => function () {
            $this->assert_ProductPickerType_testSourceWiresAdminPickerSyncAndDisablesI18n();
        },
        ];
    }

private function assert_ProductPickerType_testTypeCodeAndRendererRegistration(): void
{
    $type = new ProductPickerType();
    self::assertSame('product_picker', $type->getTypeCode());
    $renderer = new ParamTypeRenderer();
    self::assertSame('product_picker', $renderer->normalizeType('product_picker'));
    self::assertContains('product_picker', $renderer->getRegisteredTypes());
    self::assertInstanceOf(ProductPickerType::class, $renderer->getRenderer('product_picker'));
}

private function assert_ProductPickerType_testProcessValueNormalizesIds(): void
{
    $type = new ProductPickerType();
    self::assertSame('12,34', $type->processValue(['12', '34', '12'], []));
    self::assertSame('12,34', $type->processValue('12, 34;12', []));
    self::assertSame('', $type->processValue('', []));
}

private function assert_ProductPickerType_testSourceWiresAdminPickerSyncAndDisablesI18n(): void
{
    $productRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    $pickerType = $productRoot . '/Ui/ParamType/ProductPickerType.php';
    $pickerJs = $productRoot . '/view/statics/js/backend/product-admin-picker.js';
    $widgetParamJs = dirname($productRoot) . '/Widget/view/statics/js/widget-param-types.js';
    $carousel = dirname($productRoot) . '/Theme/view/theme/frontend/widgets/carousel/product-carousel/default.phtml';
    self::assertFileExists($pickerType);
    self::assertFileExists($pickerJs);
    self::assertFileExists($widgetParamJs);
    self::assertFileExists($carousel);
    $typeSrc = (string) file_get_contents($pickerType);
    $jsSrc = (string) file_get_contents($pickerJs);
    $paramJs = (string) file_get_contents($widgetParamJs);
    $carouselSrc = (string) file_get_contents($carousel);
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
