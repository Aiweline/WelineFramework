<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseCodeLabel;
use Weline\Inventory\Service\WarehouseCodeAlias;
use Weline\Inventory\Service\WarehouseCodeLabelAdminService;

final class WarehouseCodeAliasTest extends TestCase
{
    public function testDefaultMapCoversNodeKindModeAndType(): void
    {
        $map = WarehouseCodeAlias::defaultMap();
        self::assertSame('国家', $map['node_kind'][Warehouse::NODE_COUNTRY]);
        self::assertSame('省份', $map['node_kind'][Warehouse::NODE_PROVINCE]);
        self::assertSame('仓库', $map['node_kind'][Warehouse::NODE_WAREHOUSE]);
        self::assertSame('正式', $map['mode'][Warehouse::MODE_NORMAL]);
        self::assertSame('测试', $map['mode'][Warehouse::MODE_TEST]);
        self::assertSame('逻辑仓', $map['warehouse_type'][Warehouse::TYPE_LOGICAL]);
        self::assertSame('物理仓', $map['warehouse_type'][Warehouse::TYPE_PHYSICAL]);
    }

    public function testAdminServiceSeedsMatchDefaultMap(): void
    {
        $codes = [];
        foreach (WarehouseCodeLabelAdminService::DEFAULT_SEEDS as $seed) {
            $codes[$seed['code_group']][$seed['code']] = $seed['label_name'];
        }
        self::assertSame(WarehouseCodeAlias::defaultMap(), $codes);
        self::assertContains(WarehouseCodeLabel::GROUP_NODE_KIND, WarehouseCodeLabel::GROUPS);
    }
}
