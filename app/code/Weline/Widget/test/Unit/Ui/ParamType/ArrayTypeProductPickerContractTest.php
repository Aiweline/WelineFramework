<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Ui\ParamType;

use PHPUnit\Framework\TestCase;

/**
 * Contract: ArrayType item schema type=product_picker delegates to ProductPickerType.
 */
final class ArrayTypeProductPickerContractTest extends TestCase
{
    public function testRenderItemFieldContainsProductPickerBranch(): void
    {
        $file = dirname(__DIR__, 4) . '/Ui/ParamType/ArrayType.php';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);

        self::assertStringContainsString("case 'product_picker':", $src);
        self::assertStringContainsString('renderItemProductPicker', $src);
        self::assertStringContainsString('Weline\\Product\\Ui\\ParamType\\ProductPickerType', $src);
        self::assertStringContainsString('ProductPickerType::getHtml', $src);
        self::assertStringContainsString('->getHtml(', $src);
        self::assertStringContainsString('class_exists', $src);
        self::assertStringContainsString('adaptProductPickerHtmlForArrayItem', $src);
        self::assertStringContainsString('data-product-picker-sync', $src);
        self::assertStringContainsString('data-field=', $src);
    }

    public function testThemeEditorArrayFallbackRecognizesProductPicker(): void
    {
        $roots = [
            dirname(__DIR__, 5) . '/Theme/view/statics/js/theme-editor.js',
            dirname(__DIR__, 5) . '/Theme/view/statics/ui/pages/weline-theme-editor.js',
        ];
        foreach ($roots as $file) {
            self::assertFileExists($file, $file);
            $src = (string)file_get_contents($file);
            self::assertStringContainsString("type === 'product_picker'", $src, $file);
            self::assertStringContainsString('renderFallbackProductPickerField', $src, $file);
            self::assertStringContainsString('data-product-picker-sync', $src, $file);
            self::assertStringContainsString('data-field=', $src, $file);
        }
    }
}
