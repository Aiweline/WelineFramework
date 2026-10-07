<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * Guards the storefront discount-preview call graph against recursive cart reloads.
 */
final class CartQueryProviderDiscountPreviewRecursionContractTest extends TestCase
{
    public function testEnrichPassesExistingSummaryIntoDiscountPreview(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CartQueryProvider.php'
        );

        self::assertStringContainsString(
            'function buildDiscountPreview(array $params, ?array $existingSummary = null)',
            $source
        );
        self::assertStringContainsString(
            '$existingSummary ?? $this->resolveStorefrontSummary($params)',
            $source
        );
        self::assertStringContainsString(
            'buildDiscountPreview($params, $summary)',
            $source
        );
        self::assertStringContainsString('enrichSummaryWithDiscountPreview', $source);
        self::assertStringContainsString('try {', $source);
        // getCart must accept coupon_code so MiniCart can request discount_preview without session lag.
        self::assertStringContainsString("'name' => 'getCart'", $source);
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'getCart'[\\s\\S]*?'coupon_code'\\s*=>\\s*\\['type'\\s*=>\\s*'string'/",
            $source
        );
        // update/remove share mutationParams; MiniCart cartQueryParams may pass pending coupon.
        self::assertStringContainsString('function couponCodeParam()', $source);
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'summary'[\\s\\S]*?couponCodeParam\\(\\)/",
            $source
        );
        self::assertMatchesRegularExpression(
            "/function mutationParams\\(bool \\\$includeQty = false\\): array[\\s\\S]*?couponCodeParam\\(\\)/",
            $source
        );
    }
}
