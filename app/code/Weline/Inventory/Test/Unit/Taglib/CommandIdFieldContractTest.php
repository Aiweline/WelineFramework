<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Taglib\CommandIdField;

final class CommandIdFieldContractTest extends TestCase
{
    public function testCommandIdFieldTagContract(): void
    {
        self::assertSame('inventory:command-id:field', CommandIdField::name());
        self::assertTrue(CommandIdField::attr()['id']);
        self::assertTrue(CommandIdField::tag_self_close());
        $html = (CommandIdField::callback())('tag-self-close', [], [], [
            'id' => 'inv-cmd',
            'name' => 'command_id',
            'required' => 'true',
        ]);
        self::assertStringContainsString('name="command_id"', $html);
        self::assertStringContainsString('data-testid="inventory-command-id-field"', $html);
        self::assertStringContainsString('data-inventory-command-id-generate', $html);
        self::assertStringContainsString('幂等键', $html);
    }
}
