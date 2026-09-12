<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;
use Weline\CjDropshipping\Service\CjWarehouseMapper;

final class CjWarehouseMapperTest extends TestCase
{
    public function testMapApiRowPrefersStorageIdThenAreaId(): void
    {
        $mapped = CjWarehouseMapper::mapApiRow([
            'areaId' => 1,
            'areaEn' => 'China Warehouse',
            'countryCode' => 'CN',
            'disabled' => false,
            'id' => '1',
        ]);
        self::assertNotNull($mapped);
        self::assertSame('1', $mapped['external_id']);
        self::assertSame('China Warehouse', $mapped['name']);
        self::assertSame('CN', $mapped['country_code']);
        self::assertSame(1, $mapped['enabled']);

        $withStorage = CjWarehouseMapper::mapApiRow([
            'areaId' => 2,
            'storageId' => '201e67f6ba4644c0a36d63bf4989dd70',
            'areaEn' => 'US Warehouse',
            'countryCode' => 'us',
        ]);
        self::assertNotNull($withStorage);
        self::assertSame('201e67f6ba4644c0a36d63bf4989dd70', $withStorage['external_id']);
        self::assertSame('US', $withStorage['country_code']);
    }

    public function testMapApiRowReturnsNullWhenNoId(): void
    {
        self::assertNull(CjWarehouseMapper::mapApiRow(['areaEn' => 'Nope']));
    }

    public function testMapApiRowMarksDisabled(): void
    {
        $mapped = CjWarehouseMapper::mapApiRow([
            'areaId' => 9,
            'disabled' => true,
            'zh' => '停用仓',
            'valueEn' => 'GB',
        ]);
        self::assertNotNull($mapped);
        self::assertSame(0, $mapped['enabled']);
        self::assertSame('GB', $mapped['country_code']);
        self::assertSame('停用仓', $mapped['name']);
    }
}

final class CjProviderWarehouseCapabilityContractTest extends TestCase
{
    public function testWarehouseCapabilityAndPullThrowsVisible(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Dropship/DropshipProvider/CjProvider.php'
        );
        $p = new CjProvider();
        self::assertTrue(!empty($p->getCapabilities()['warehouse']));
        self::assertStringContainsString('CjWarehouseMapper::mapApiRow', $src);
        self::assertStringContainsString('throw new \\RuntimeException', $src);
        self::assertStringContainsString('cj_warehouse_list_empty', $src);
        self::assertStringContainsString("'/product/globalWarehouseList'", $src);
        // pullWarehouses 本身不得空吞；listWarehouses 空表自动 pull 可降级为空列表
        self::assertMatchesRegularExpression(
            '/function pullWarehouses\(array \$context = \[\]\): array\s*\{(?!\s*try\s*\{)/s',
            $src
        );
    }
}
