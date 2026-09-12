<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Taglib\OnHandField;

final class OnHandFieldContractTest extends TestCase
{
    public function testOnHandFieldTagContract(): void
    {
        self::assertSame('inventory:on-hand:field', OnHandField::name());
        self::assertTrue(OnHandField::attr()['id']);
        self::assertTrue(OnHandField::tag_self_close());
        $html = (OnHandField::callback())('tag-self-close', [], [], [
            'id' => 'inv-on-hand',
            'name' => 'on_hand_minor',
            'required' => 'true',
        ]);
        self::assertStringContainsString('name="on_hand_minor"', $html);
        self::assertStringContainsString('data-testid="inventory-on-hand-field"', $html);
        self::assertStringContainsString('在手库存（件）', $html);
    }
}
