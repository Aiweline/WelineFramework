<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** Source contract: client typed image controls prefer shared WelineMedia/file-picker HTML. */
final class ThemeEditorTypedFileImagePickerContractTest extends TestCase
{
    public function testThemeEditorPrefersSharedMediaLibraryPickerBuilder(): void
    {
        $file = dirname(__DIR__, 3) . '/view/statics/js/theme-editor.js';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('renderMediaLibraryPickerHtml', $src);
        self::assertStringContainsString("openLabel: translateUiText('从图库选择')", $src);
    }

    public function testConfigFormPrefersWelineMediaFilePicker(): void
    {
        $file = dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/config-form.phtml';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('Weline\\MediaManager\\Block\\WelineMedia::class', $src);
        self::assertStringContainsString("'value_mode' => 'file-image'", $src);
        self::assertStringContainsString('data-w-param-media="file-picker"', $src);
    }
}
