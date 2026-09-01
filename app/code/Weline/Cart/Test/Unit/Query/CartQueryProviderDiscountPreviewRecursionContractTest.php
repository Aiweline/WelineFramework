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
        self::assertStringContainsString(
            "private function enrichSummaryWithDiscountPreview(array \$summary, array \$params = []): array\n    {\n        \$preview = \$this->buildDiscountPreview(\$params, \$summary);",
            $source
        );
    }
}
