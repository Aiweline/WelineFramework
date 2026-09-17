<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Ui\ParamType;

use PHPUnit\Framework\TestCase;

/** Source contract: media_image prefers WelineMedia/file-picker typed values. */
final class MediaImageFilePickerContractTest extends TestCase
{
    public function testAbstractParamTypePrefersWelineMediaFilePicker(): void
    {
        $file = dirname(__DIR__, 4) . '/Ui/ParamType/AbstractParamType.php';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('renderMediaLibraryPickerHtml', $src);
        self::assertStringContainsString('Weline\\MediaManager\\Block\\WelineMedia::class', $src);
        self::assertStringContainsString("'value_mode' => 'file-image'", $src);
        self::assertStringContainsString("'usage' => '1'", $src);
        self::assertStringContainsString('framework_view_process_block', $src);
        self::assertStringContainsString('array_field', $src);
        self::assertStringContainsString('omit_name', $src);
    }

    public function testMediaAndImageTypesDelegateToSharedPickerRenderer(): void
    {
        foreach (['MediaImageType.php', 'ImageType.php', 'ArrayType.php'] as $name) {
            $file = dirname(__DIR__, 4) . '/Ui/ParamType/' . $name;
            self::assertFileExists($file);
            $src = (string)file_get_contents($file);
            self::assertStringContainsString('renderMediaLibraryPickerHtml', $src);
        }
    }

    public function testArrayItemImageUsesArrayFieldOptions(): void
    {
        $file = dirname(__DIR__, 4) . '/Ui/ParamType/ArrayType.php';
        $src = (string)file_get_contents($file);
        self::assertStringContainsString("'array_field' => \$fieldKey", $src);
        self::assertStringNotContainsString('w-param-image-placeholder', $src);
    }
}
