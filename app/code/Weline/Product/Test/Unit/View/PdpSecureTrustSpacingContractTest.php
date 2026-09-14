<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class PdpSecureTrustSpacingContractTest extends TestCase
{
    public function testBuyboxSecureCopyKeepsThemeGapBelowSharePanel(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('class="product-native-detail__secure"', $source);
        self::assertStringContainsString('.product-native-detail__buybox > * { margin: 0; }', $source);
        self::assertMatchesRegularExpression(
            '/\.product-native-detail__buybox\s*>\s*\.product-native-detail__secure\s*\{[^}]*margin-top:\s*var\(--spacing-md,\s*1rem\)/s',
            $source
        );
    }
}
