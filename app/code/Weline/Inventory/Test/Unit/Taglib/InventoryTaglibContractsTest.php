<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Taglib\CommandIdField;
use Weline\Inventory\Taglib\OnHandField;
use Weline\Inventory\Taglib\WarehouseSelect;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllInventoryTaglibContractsPass().
 */
final class InventoryTaglibContractsTest extends TestCase
{
    public function testAllInventoryTaglibContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CommandIdField::testCommandIdFieldTagContract' => function () {
            $this->assert_CommandIdField_testCommandIdFieldTagContract();
        },
            'OnHandField::testOnHandFieldTagContract' => function () {
            $this->assert_OnHandField_testOnHandFieldTagContract();
        },
            'WarehouseSelect::testTagNameAndAttrs' => function () {
            $this->assert_WarehouseSelect_testTagNameAndAttrs();
        },
        ];
    }

private function assert_CommandIdField_testCommandIdFieldTagContract(): void
{
    self::assertSame('inventory:command-id:field', CommandIdField::name());
    self::assertTrue(CommandIdField::attr()['id']);
    self::assertTrue(CommandIdField::tag_self_close());
    $html = CommandIdField::callback()('tag-self-close', [], [], ['id' => 'inv-cmd', 'name' => 'command_id', 'required' => 'true']);
    self::assertStringContainsString('name="command_id"', $html);
    self::assertStringContainsString('data-testid="inventory-command-id-field"', $html);
    self::assertStringContainsString('data-inventory-command-id-generate', $html);
    self::assertStringContainsString('幂等键', $html);
}

private function assert_OnHandField_testOnHandFieldTagContract(): void
{
    self::assertSame('inventory:on-hand:field', OnHandField::name());
    self::assertTrue(OnHandField::attr()['id']);
    self::assertTrue(OnHandField::tag_self_close());
    $html = OnHandField::callback()('tag-self-close', [], [], ['id' => 'inv-on-hand', 'name' => 'on_hand_minor', 'required' => 'true']);
    self::assertStringContainsString('name="on_hand_minor"', $html);
    self::assertStringContainsString('data-testid="inventory-on-hand-field"', $html);
    self::assertStringContainsString('在手库存（件）', $html);
}

private function assert_WarehouseSelect_testTagNameAndAttrs(): void
{
    self::assertSame('inventory:warehouse:select', WarehouseSelect::name());
    self::assertTrue(WarehouseSelect::attr()['id']);
    self::assertFalse(WarehouseSelect::attr()['name']);
    self::assertArrayHasKey('options-json', WarehouseSelect::attr());
    self::assertTrue(WarehouseSelect::tag_self_close());
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Inventory' . '/Taglib/WarehouseSelect.php');
    self::assertStringContainsString("'options-json'", $src);
    self::assertStringContainsString('options-json', $src);
}
}
