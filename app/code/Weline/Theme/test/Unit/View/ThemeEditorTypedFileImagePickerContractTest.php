<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** Source contract: client typed image controls prefer shared WelineMedia/file-picker HTML. */
final class ThemeEditorTypedFileImagePickerContractTest extends TestCase
{
    public function testThemeEditorPrefersSharedMediaLibraryPickerBuilder(): void
    {
        $file = dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('renderMediaLibraryPickerHtml', $src);
        self::assertStringContainsString("openLabel: window.__('从图库选择')", $src);
        self::assertStringNotContainsString('translateUiText', $src);
    }

    public function testThemeEditorHydratesSavedFileImagePreviewsOnFormBind(): void
    {
        // Production template loads ui/pages/weline-theme-editor.js (not js/theme-editor.js alone).
        $file = dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js';
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('async function hydrateFileImagePickerPreviews', $src);
        self::assertMatchesRegularExpression(
            '/function bindAccordionFormEvents\(container\)\s*\{[\s\S]{0,600}hydrateFileImagePickerPreviews\(container\)/',
            $src
        );
        $hydrate = self::functionBody($src, 'async function hydrateFileImagePickerPreviews');
        self::assertStringContainsString('resolveI18nMediaPreviewUrls', $hydrate);
        self::assertStringContainsString('updateMediaPreview', $hydrate);
        self::assertStringContainsString('filePickerPreviewHasUsableThumb', $src);
        self::assertStringContainsString('[data-w-file-item] img', $src);
        self::assertStringContainsString('primeMediaPreviewUrlCache', $src);
        self::assertStringContainsString('previewUrlForMediaValue', $src);
        self::assertStringContainsString('media_preview_urls', $src);
        // Empty resolve must not poison the cache forever.
        self::assertStringContainsString('i18nMediaPreviewUrlCache.delete', $src);
        self::assertStringContainsString('data-pending-preview', (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor-widget-param.js'
        ));

        $editorPhp = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/ThemeEditor.php');
        self::assertStringContainsString('resolveTransientMediaPreviewUrls', $editorPhp);
        self::assertStringContainsString("'media_preview_urls'", $editorPhp);

        $css = (string)file_get_contents(dirname(__DIR__, 4) . '/Widget/view/statics/css/widget-param-types.css');
        self::assertStringContainsString('[data-kind="image"] .w-file-preview__media', $css);
        self::assertStringContainsString('aspect-ratio: 16 / 5', $css);
    }

    public function testThemeEditorActionUrlsAreBinQueryPathSafe(): void
    {
        $file = dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js';
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('function buildThemeEditorActionUrl', $src);
        self::assertStringContainsString('function themeEditorActionUrl', $src);
        self::assertStringContainsString("buildThemeEditorActionUrl('widget-config')", $src);
        self::assertStringContainsString("themeEditorActionUrl('save-widget-config')", $src);
        // Forbidden: concatenating action onto apiBase (parks "/action" in query string).
        self::assertStringNotContainsString('${config.apiBase}/widget-config', $src);
        self::assertStringNotContainsString('`${config.apiBase}/save-widget-config`', $src);
        self::assertStringNotContainsString("config.apiBase + '/update-sort'", $src);
        self::assertStringContainsString('resource.editorRequest(params)', $src);
    }

    private static function functionBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        self::assertNotFalse($start);
        $brace = strpos($source, '{', $start);
        self::assertNotFalse($brace);
        $depth = 0;
        $length = strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }
        self::fail('Unbalanced braces for ' . $signature);
    }
}
