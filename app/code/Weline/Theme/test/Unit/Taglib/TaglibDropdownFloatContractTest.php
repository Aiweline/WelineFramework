<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * 基础下拉 Taglib 须经 Weline.UI.floating.attach portal，逃出父级 overflow 裁切。
 */
final class TaglibDropdownFloatContractTest extends TestCase
{
    /**
     * @return list<array{0:string,1:string}>
     */
    public static function dropdownTaglibProvider(): array
    {
        $base = \dirname(__DIR__, 3) . '/Taglib/';

        return [
            'cascader' => [$base . 'Cascader.php', 'w-cascader-dropdown'],
            'tree-select' => [$base . 'TreeSelect.php', 'w-tree-select-dropdown'],
            'tag-input' => [$base . 'TagInput.php', 'w-tag-input-suggestions'],
            'color-picker' => [$base . 'ColorPicker.php', 'w-color-picker-dropdown'],
            'search-select' => [$base . 'SearchSelect.php', 'w-search-select-dropdown'],
        ];
    }

    /**
     * @dataProvider dropdownTaglibProvider
     */
    public function testDropdownUsesFloatingAttachPortal(string $path, string $surfaceClass): void
    {
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('data-w-float-surface', $src);
        self::assertStringContainsString('floating.attach(', $src);
        self::assertStringContainsString($surfaceClass . '[data-w-floating-positioned]', $src);
        self::assertStringContainsString('position:fixed', $src);
        self::assertStringContainsString('uiFloating', $src);
        self::assertStringContainsString('ensureFloat', $src);
        self::assertStringContainsString('placeFloat', $src);
    }

    public function testAddressLoaderBustForcesSingleFloatReload(): void
    {
        $loader = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/address-loader.js');
        $tag = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Taglib/Address.php');
        $js = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/address.js');

        self::assertStringContainsString('20260921-keep-postal2', $loader);
        self::assertStringContainsString('ensureSingleFloat', $js);
        self::assertStringContainsString('floating.attach', $js);
        self::assertStringContainsString('data-w-float-surface', $js);
    }
}
