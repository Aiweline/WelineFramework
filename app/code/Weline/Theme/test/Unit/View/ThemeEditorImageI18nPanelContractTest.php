<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ThemeEditorImageI18nPanelContractTest extends TestCase
{
    public function testEditorScriptsRenderMediaI18nRowsAndStampLocale(): void
    {
        $files = [
            dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js',
            dirname(__DIR__, 3) . '/view/statics/js/theme-editor.js',
            dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor-widget-param.js',
            dirname(__DIR__, 4) . '/Widget/view/statics/js/widget-param-types.js',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file);
            $src = (string)file_get_contents($file);
            if (str_contains($file, 'widget-param')) {
                self::assertStringContainsString('data-locale-code', $src);
                self::assertStringContainsString('resolvePickerLocale(themeEl, btn)', $src);
                self::assertStringContainsString('node.usage.locale_code = pickerLocale', $src);
                self::assertStringContainsString('updateMediaPreview', $src);
                self::assertStringContainsString('data-pending-preview', $src);
                self::assertStringContainsString('keep a pending shell', $src);
                self::assertStringNotContainsString("if (!url) return;", $src);
                $picker = self::functionBody($src, 'function resolvePickerLocale');
                $button = strpos($picker, 'data-locale-code');
                $config = strpos($picker, 'data-config-locale');
                $siteDefault = strpos($picker, 'data-default-locale');
                $urlLocale = strpos($picker, "get('locale')");
                self::assertNotFalse($button);
                self::assertNotFalse($config);
                self::assertNotFalse($siteDefault);
                self::assertNotFalse($urlLocale);
                self::assertLessThan($config, $button, 'i18n row locale must win over layout locale');
                self::assertLessThan($siteDefault, $config, 'concrete layout locale must win over site default');
                self::assertLessThan($urlLocale, $siteDefault, 'preview ?locale= must not override the site-default stamp');
                continue;
            }
            self::assertStringContainsString('IMAGE_UI_TYPES', $src);
            self::assertStringContainsString('data-i18n-media', $src);
            self::assertStringContainsString('isBlankI18nFieldValue', $src);
            self::assertStringContainsString('mountMedia', $src);
            self::assertStringContainsString('data-locale-code', $src);
            self::assertStringContainsString('isI18nMediaPanel', $src);
            self::assertStringContainsString('stampI18nMediaPanel', $src);
            self::assertStringContainsString('applyI18nMediaInputValue', $src);
            self::assertStringContainsString('resolveI18nMediaPreviewUrlAsync', $src);
            self::assertStringContainsString('resolve-file-image-previews', $src);
            self::assertStringContainsString('filePickerPreviewHasUsableThumb', $src);
            self::assertStringContainsString('hydrateFileImagePickerPreviews', $src);
            self::assertStringContainsString('void hydrateFileImagePickerPreviews(panel)', $src);
            self::assertStringContainsString('updateMediaPreview(input, previewUrl)', $src);
            self::assertStringContainsString('formatI18nTextInputValue', $src);
            self::assertStringContainsString('i18n-row--media', $src);
            self::assertStringContainsString('i18n-row--compact', $src);
            self::assertStringContainsString('i18n-media--compact', $src);
            self::assertStringContainsString('syncI18nMediaRowStatuses', $src);
            self::assertStringContainsString('isI18nPanelOpen', $src);
            self::assertStringContainsString('upgradeI18nPanelToDialog', $src);
            self::assertStringContainsString('w-param-i18n-dialog', $src);
            self::assertStringContainsString('Weline?.UI?.dialog', $src);
            self::assertMatchesRegularExpression('/if \(uiType !== \'\'\) \{\s*return false;\s*\}/', $src);
            self::assertStringContainsString('.w-param-array-field, .array-item-field', $src);
            self::assertStringNotContainsString("input.value = value == null ? '' : String(value);", $src);
        }

        $abstract = dirname(__DIR__, 4) . '/Widget/Ui/ParamType/AbstractParamType.php';
        $abs = (string)file_get_contents($abstract);
        self::assertStringContainsString('data-i18n-media', $abs);
        self::assertStringContainsString('isImageUiType', $abs);
        self::assertStringContainsString('<dialog class="w-dialog', $abs);
        self::assertStringContainsString("i18n-dialog", $abs);
        self::assertStringContainsString('data-w-component="dialog"', $abs);
        self::assertStringContainsString('w-dialog__surface', $abs);

        $css = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor-widget-param.css');
        self::assertStringContainsString('.w-param-i18n-media--compact .w-file-preview__item', $css);
        self::assertStringContainsString('max-inline-size: 3rem', $css);
        self::assertStringContainsString(':is(.w-param-form, .w-param-i18n-dialog, .w-param-i18n-panel)', $css);
    }

    private static function functionBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        self::assertNotFalse($start);
        $next = strpos($source, "\n    function ", $start + strlen($signature));
        self::assertNotFalse($next);

        return substr($source, $start, $next - $start);
    }
}
