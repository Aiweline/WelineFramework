<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Product\Model\ProductShardKey;

final class ProductShardKeyTest extends TestCase
{
    public function testWebsiteZeroIsValid(): void
    {
        self::assertSame('0', ProductShardKey::fromWebsiteId(0));
        self::assertSame(0, ProductShardKey::parse('0'));
        self::assertSame('product_ws_0_product', ProductShardKey::tableName('0', 'product'));
    }

    public function testRejectsNegativeWebsiteId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductShardKey::fromWebsiteId(-1);
    }

    public function testRejectsLeadingZeros(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductShardKey::parse('01');
    }

    public function testRejectsShardKeyAbovePhpIntegerRange(): void
    {
        $overflow = $this->decimalIncrement((string)PHP_INT_MAX);

        $this->expectException(\InvalidArgumentException::class);
        ProductShardKey::parse($overflow);
    }

    public function testEveryDeclaredEntityBuildsAWhitelistedTable(): void
    {
        $tables = array_map(
            static fn(string $entity): string => ProductShardKey::tableName('7', $entity),
            ProductShardKey::ENTITY_CODES,
        );

        self::assertCount(9, $tables);
        self::assertSame('product_ws_7_product', $tables[0]);
        self::assertSame('product_ws_7_store_offer', $tables[array_key_last($tables)]);
    }

    public function testRejectsUndeclaredEntitySuffix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductShardKey::tableName('0', 'audit_log');
    }

    public function testFamilyCode(): void
    {
        self::assertSame('product.website', ProductShardKey::FAMILY_CODE);
    }

    private function decimalIncrement(string $value): string
    {
        $digits = str_split($value);
        for ($index = count($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string)((int)$digits[$index] + 1);
                return implode('', $digits);
            }
            $digits[$index] = '0';
        }

        return '1' . implode('', $digits);
    }
}
