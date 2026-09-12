<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Taglib\WarehouseSelect;

final class WarehouseSelectContractTest extends TestCase
{
    public function testTagNameAndAttrs(): void
    {
        self::assertSame('inventory:warehouse:select', WarehouseSelect::name());
        self::assertTrue(WarehouseSelect::attr()['id']);
        self::assertFalse(WarehouseSelect::attr()['name']);
        self::assertArrayHasKey('options-json', WarehouseSelect::attr());
        self::assertTrue(WarehouseSelect::tag_self_close());
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Taglib/WarehouseSelect.php');
        self::assertStringContainsString("'options-json'", $src);
        self::assertStringContainsString('options-json', $src);
    }
}
