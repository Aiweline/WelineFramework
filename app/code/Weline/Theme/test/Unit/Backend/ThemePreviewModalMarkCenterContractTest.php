<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Backend;

use PHPUnit\Framework\TestCase;

/**
 * 单主题预览弹窗的相机图标必须跟状态文案同一中轴。
 * SVG 在基础样式里是 display:block，text-align 居中盖不住；转圈也不能再被写成 display:block。
 */
final class ThemePreviewModalMarkCenterContractTest extends TestCase
{
    public function testSinglePreviewMarkCentersSpinnerAndCamera(): void
    {
        $path = BP . '/app/code/Weline/Theme/view/templates/backend/index.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('id="singlePreviewMark"', $source);
        self::assertStringContainsString('id="singleSpinner"', $source);
        self::assertMatchesRegularExpression(
            '/id="singleSpinner"[^>]*data-self="center"/',
            $source
        );
        self::assertMatchesRegularExpression(
            '/<span data-self="center">\s*<w:icon name="camera"/',
            $source
        );
        self::assertStringNotContainsString("spinner.style.display", $source);
        self::assertStringContainsString('spinner.hidden = type !== \'working\'', $source);
    }
}
