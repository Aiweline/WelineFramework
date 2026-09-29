<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * product-info / nested slot layout-embed recover (storefront acceptance fail28).
 */
final class LayoutEmbedRecoverProductInfoContractTest extends TestCase
{


    public function testProductPageNormalizerKeepsLayoutEmbedRecoverProductInfo(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ProductPageLayoutNormalizer.php'
        );
        self::assertStringContainsString('layout_embed_recover', $src);
        self::assertStringContainsString('WIDGET_CODE_PRODUCT_INFO', $src);
        self::assertStringContainsString("\$source === 'layout_embed_recover'", $src);
    }

    public function testPolicySubLayoutsDeclarePolicyContentSlot(): void
    {
        $dir = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/policy';
        foreach (['privacy', 'cookie', 'shipping', 'refund', 'disclaimer', 'accessibility', 'term-condition'] as $opt) {
            $file = $dir . '/' . $opt . '.phtml';
            self::assertFileExists($file, $opt);
            $html = (string)\file_get_contents($file);
            self::assertStringContainsString('id="policy-content"', $html, $opt);
        }
    }
}
