<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderSummaryCompactContractTest extends TestCase
{
    public function testEditSummaryUsesCompactMultiColumnGridNotWideTables(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('data-testid="order-edit-summary-grid"', $src);
        self::assertStringContainsString('w-stat-tiles', $src);
        self::assertStringContainsString('--w-stat-tile-min:11rem', $src);
        self::assertStringContainsString('w-cluster', $src);
        self::assertStringContainsString('data-testid="order-edit-totals"', $src);
        self::assertStringContainsString('data-testid="order-edit-line-count"', $src);
        // Sparse two half-width tables should be gone from summary.
        self::assertDoesNotMatchRegularExpression(
            '/data-testid="order-edit-summary"[\s\S]{0,800}?--w-span-md:6[\s\S]{0,200}?<table class="w-table"/',
            $src
        );
    }
}
