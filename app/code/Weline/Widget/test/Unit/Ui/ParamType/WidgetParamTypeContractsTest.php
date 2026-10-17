<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Ui\ParamType;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\TestCore;
use Weline\Widget\Service\ParamTypeRenderer;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllWidgetParamTypeContractsPass().
 */
final class WidgetParamTypeContractsTest extends TestCase
{
    public function testAllWidgetParamTypeContractsPass(): void
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
            'ArrayTypeProductPicker::testRenderItemFieldContainsProductPickerBranch' => function () {
            $this->assert_ArrayTypeProductPicker_testRenderItemFieldContainsProductPickerBranch();
        },
            'ArrayTypeProductPicker::testThemeEditorArrayFallbackRecognizesProductPicker' => function () {
            $this->assert_ArrayTypeProductPicker_testThemeEditorArrayFallbackRecognizesProductPicker();
        },
            'I18nDialogPanel::testArrayTextIntroRendersDialogWithoutMediaFlag' => function () {
            $this->assert_I18nDialogPanel_testArrayTextIntroRendersDialogWithoutMediaFlag();
        },
            'MediaImageFilePicker::testAbstractParamTypePrefersWelineMediaFilePicker' => function () {
            $this->assert_MediaImageFilePicker_testAbstractParamTypePrefersWelineMediaFilePicker();
        },
            'MediaImageFilePicker::testMediaAndImageTypesDelegateToSharedPickerRenderer' => function () {
            $this->assert_MediaImageFilePicker_testMediaAndImageTypesDelegateToSharedPickerRenderer();
        },
            'MediaImageFilePicker::testArrayItemImageUsesArrayFieldOptions' => function () {
            $this->assert_MediaImageFilePicker_testArrayItemImageUsesArrayFieldOptions();
        },
        ];
    }

private function assert_ArrayTypeProductPicker_testRenderItemFieldContainsProductPickerBranch(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Ui/ParamType/ArrayType.php';
    self::assertFileExists($file);
    $src = (string) file_get_contents($file);
    self::assertStringContainsString("case 'product_picker':", $src);
    self::assertStringContainsString('renderItemProductPicker', $src);
    self::assertStringContainsString('Weline\Product\Ui\ParamType\ProductPickerType', $src);
    self::assertStringContainsString('ProductPickerType::getHtml', $src);
    self::assertStringContainsString('->getHtml(', $src);
    self::assertStringContainsString('class_exists', $src);
    self::assertStringContainsString('adaptProductPickerHtmlForArrayItem', $src);
    self::assertStringContainsString('data-product-picker-sync', $src);
    self::assertStringContainsString('data-field=', $src);
}

private function assert_ArrayTypeProductPicker_testThemeEditorArrayFallbackRecognizesProductPicker(): void
{
    $roots = ['/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/js/theme-editor.js', '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-theme-editor.js'];
    foreach ($roots as $file) {
        self::assertFileExists($file, $file);
        $src = (string) file_get_contents($file);
        self::assertStringContainsString("type === 'product_picker'", $src, $file);
        self::assertStringContainsString('renderFallbackProductPickerField', $src, $file);
        self::assertStringContainsString('data-product-picker-sync', $src, $file);
        self::assertStringContainsString('data-field=', $src, $file);
    }
}

private function assert_I18nDialogPanel_testArrayTextIntroRendersDialogWithoutMediaFlag(): void
{
    $renderer = new ParamTypeRenderer();
    $params = ['tracks' => ['type' => 'array', 'label' => '进店曲目', 'item_schema' => ['url' => ['type' => 'media_image', 'label' => '曲目', 'i18n' => false, 'media_options' => ['kind' => 'audio']], 'title' => ['type' => 'string', 'label' => '曲名', 'i18n' => false], 'intro' => ['type' => 'textarea', 'label' => '简介', 'i18n' => true]]]];
    $config = ['tracks' => [['url' => '/media/a.mp3', 'title' => '测试曲', 'intro' => '简介文本']]];
    $html = $renderer->renderForm(42, $params, $config);
    self::assertStringContainsString('id="i18n_panel_42_tracks_0_intro"', $html);
    self::assertMatchesRegularExpression('/<dialog class="w-dialog w-param-i18n-panel w-param-i18n-dialog"[^>]*data-field="tracks\.0\.intro"[^>]*data-ui-type="textarea"/', $html);
    self::assertDoesNotMatchRegularExpression('/<dialog[^>]*data-field="tracks\.0\.intro"[^>]*data-i18n-media="/', $html);
    self::assertStringContainsString('data-w-component="dialog"', $html);
    self::assertStringContainsString('data-w-closable="false"', $html);
    self::assertStringContainsString('data-w-backdrop="static"', $html);
    self::assertStringContainsString('w-dialog__surface', $html);
    self::assertStringContainsString('w-param-btn-i18n', $html);
    self::assertStringContainsString('data-close-i18n', $html);
    self::assertStringContainsString('data-i18n-locale-search', $html);
    self::assertStringContainsString('w-param-i18n-toolbar', $html);
    self::assertStringContainsString('搜索语言或代码', $html);
}

private function assert_MediaImageFilePicker_testAbstractParamTypePrefersWelineMediaFilePicker(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Ui/ParamType/AbstractParamType.php';
    self::assertFileExists($file);
    $src = (string) file_get_contents($file);
    self::assertStringContainsString('renderMediaLibraryPickerHtml', $src);
    self::assertStringContainsString('Weline\MediaManager\Block\WelineMedia::class', $src);
    self::assertStringContainsString("'value_mode' => 'file-image'", $src);
    self::assertStringContainsString("'usage' => '1'", $src);
    self::assertStringContainsString('framework_view_process_block', $src);
    self::assertStringContainsString('array_field', $src);
    self::assertStringContainsString('omit_name', $src);
}

private function assert_MediaImageFilePicker_testMediaAndImageTypesDelegateToSharedPickerRenderer(): void
{
    foreach (['MediaImageType.php', 'ImageType.php', 'ArrayType.php'] as $name) {
        $file = '/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Ui/ParamType/' . $name;
        self::assertFileExists($file);
        $src = (string) file_get_contents($file);
        self::assertStringContainsString('renderMediaLibraryPickerHtml', $src);
    }
}

private function assert_MediaImageFilePicker_testArrayItemImageUsesArrayFieldOptions(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Ui/ParamType/ArrayType.php';
    $src = (string) file_get_contents($file);
    self::assertStringContainsString("'array_field' => \$fieldKey", $src);
    self::assertStringNotContainsString('w-param-image-placeholder', $src);
}
}
