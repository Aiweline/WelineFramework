<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Mobile PDP gallery must stretch so .w-frame stage keeps full width after absolute img.
 */
final class ProductNativeDetailStageFrameMobileContractTest extends TestCase
{
    public function testMobileGalleryStretchesStageWrapForWFrame(): void
    {
        $cssPath = BP . 'app/code/Weline/Product/view/statics/css/widgets/product-native-detail.css';
        self::assertFileExists($cssPath);
        $css = (string)file_get_contents($cssPath);

        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*640px\s*\)\s*\{[\s\S]*?\.product-native-detail__gallery[\s\S]*?align-items:\s*stretch/i',
            $css,
            'Mobile gallery must align-items:stretch so w-frame stage gets width'
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*640px\s*\)\s*\{[\s\S]*?\.product-native-detail__stage-wrap\s*\{[\s\S]*?width:\s*100%/i',
            $css,
            'Mobile stage-wrap must be width:100%'
        );
    }
}
