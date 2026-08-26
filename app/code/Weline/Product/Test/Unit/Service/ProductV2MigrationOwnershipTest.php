<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductV2MigrationService;

final class ProductV2MigrationOwnershipTest extends TestCase
{
    public function testCopySourceWinsOwnership(): void
    {
        self::assertSame(7, ProductV2MigrationService::selectOwnerWebsiteId([
            ['website_id' => 2, 'created_at' => '2024-01-01 00:00:00'],
            ['website_id' => 7, 'copy_source' => true, 'created_at' => '2025-01-01 00:00:00'],
        ]));
    }

    public function testEarliestThenDefaultThenSmallestWebsiteWins(): void
    {
        self::assertSame(0, ProductV2MigrationService::selectOwnerWebsiteId([
            ['website_id' => 4, 'created_at' => '2024-01-01 00:00:00'],
            ['website_id' => 0, 'created_at' => '2024-01-01 00:00:00'],
            ['website_id' => 2, 'created_at' => '2024-01-01 00:00:00'],
        ]));
        self::assertSame(2, ProductV2MigrationService::selectOwnerWebsiteId([
            ['website_id' => 4, 'created_at' => '2024-01-01 00:00:00'],
            ['website_id' => 2, 'created_at' => '2024-01-01 00:00:00'],
        ]));
    }
}
