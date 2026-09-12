<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Service\DefaultLogicalWarehouseResolver;
use Weline\Inventory\Service\InventoryConflictException;
use Weline\Inventory\Service\WarehouseAuthorizationService;

/** TEST-P3A-04 + default logical warehouse memory compatibility. */
final class WarehouseAuthorizationAndDefaultResolverTest extends TestCase
{
    public function testTestStoreCannotBindNormalPhysicalWarehouse(): void
    {
        $auth = WarehouseAuthorizationService::forTesting();
        $auth->registerWarehouse($this->warehouse(10, 0, Warehouse::MODE_NORMAL));

        $result = $auth->assertBindAllowed([
            'website_id' => 0,
            'store_id' => 2,
            'store_mode' => Warehouse::MODE_TEST,
            'warehouse_id' => 10,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(WarehouseAuthorizationService::ERROR_MODE_MISMATCH, $result['error']);
        self::assertSame(0, $auth->grantCount());
        self::assertFalse($auth->isAuthorized(0, 2, 10));
    }

    public function testNormalStoreCannotBindTestWarehouse(): void
    {
        $auth = WarehouseAuthorizationService::forTesting();
        $auth->registerWarehouse($this->warehouse(11, 0, Warehouse::MODE_TEST));

        $result = $auth->assertBindAllowed([
            'website_id' => 0,
            'store_id' => 1,
            'store_mode' => Warehouse::MODE_NORMAL,
            'warehouse_id' => 11,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(WarehouseAuthorizationService::ERROR_MODE_MISMATCH, $result['error']);
        self::assertSame(0, $auth->grantCount());
    }

    public function testDevStoreUsesTestWarehouseEnvironment(): void
    {
        $auth = WarehouseAuthorizationService::forTesting();
        $auth->registerWarehouse($this->warehouse(12, 0, Warehouse::MODE_NORMAL));
        $auth->registerWarehouse($this->warehouse(13, 0, Warehouse::MODE_TEST));

        $rejected = $auth->assertBindAllowed([
            'website_id' => 0,
            'store_id' => 3,
            'store_mode' => 'dev',
            'warehouse_id' => 12,
        ]);
        $accepted = $auth->assertBindAllowed([
            'website_id' => 0,
            'store_id' => 3,
            'store_mode' => 'dev',
            'warehouse_id' => 13,
        ]);

        self::assertSame(WarehouseAuthorizationService::ERROR_MODE_MISMATCH, $rejected['error']);
        self::assertTrue($accepted['ok']);
        self::assertSame(1, $auth->grantCount());
    }

    public function testUnknownStoreModeAndWebsiteMismatchLeaveNoGrant(): void
    {
        $auth = WarehouseAuthorizationService::forTesting();
        $auth->registerWarehouse($this->warehouse(14, 1, Warehouse::MODE_TEST));

        $unknown = $auth->assertBindAllowed([
            'website_id' => 1,
            'store_id' => 4,
            'store_mode' => 'preview',
            'warehouse_id' => 14,
        ]);
        $crossWebsite = $auth->assertBindAllowed([
            'website_id' => 0,
            'store_id' => 4,
            'store_mode' => 'test',
            'warehouse_id' => 14,
        ]);

        self::assertSame(WarehouseAuthorizationService::ERROR_STORE_MODE_INVALID, $unknown['error']);
        self::assertSame(WarehouseAuthorizationService::ERROR_WEBSITE_MISMATCH, $crossWebsite['error']);
        self::assertSame(0, $auth->grantCount());
    }

    public function testSameModeBindIsIdempotent(): void
    {
        $auth = WarehouseAuthorizationService::forTesting();
        $auth->registerWarehouse($this->warehouse(15, 0, Warehouse::MODE_NORMAL));
        $binding = [
            'website_id' => 0,
            'store_id' => 1,
            'store_mode' => Warehouse::MODE_NORMAL,
            'warehouse_id' => 15,
        ];

        self::assertTrue($auth->assertBindAllowed($binding)['ok']);
        self::assertTrue($auth->assertBindAllowed($binding)['ok']);
        self::assertTrue($auth->isAuthorized(0, 1, 15));
        self::assertSame(1, $auth->grantCount());
    }

    public function testSecondDefaultBindingIsRejected(): void
    {
        $auth = WarehouseAuthorizationService::forTesting();
        $auth->registerWarehouse($this->warehouse(16, 0, Warehouse::MODE_TEST, true));
        $auth->registerWarehouse($this->warehouse(17, 0, Warehouse::MODE_TEST, true));

        self::assertTrue($auth->assertBindAllowed([
            'website_id' => 0,
            'store_id' => 5,
            'store_mode' => 'test',
            'warehouse_id' => 16,
            'is_default' => true,
        ])['ok']);
        $conflict = $auth->assertBindAllowed([
            'website_id' => 0,
            'store_id' => 5,
            'store_mode' => 'test',
            'warehouse_id' => 17,
            'is_default' => true,
        ]);

        self::assertSame(WarehouseAuthorizationService::ERROR_DEFAULT_CONFLICT, $conflict['error']);
        self::assertSame(1, $auth->grantCount());
    }

    public function testSeedDefaultAuthorizationCanRebindButNotDelete(): void
    {
        $auth = WarehouseAuthorizationService::forTesting();
        $auth->registerWarehouse($this->warehouse(30, 0, Warehouse::MODE_NORMAL, true));
        $auth->registerWarehouse($this->warehouse(31, 0, Warehouse::MODE_NORMAL, true));

        $seed = $auth->bind([
            'website_id' => 0,
            'store_id' => 0,
            'store_mode' => Warehouse::MODE_NORMAL,
            'warehouse_id' => 30,
            'is_default' => true,
            'is_seed' => true,
        ]);
        self::assertSame(1, (int) ($seed['is_seed'] ?? 0));
        self::assertSame(30, (int) $seed['warehouse_id']);

        $rebound = $auth->bind([
            'website_id' => 0,
            'store_id' => 0,
            'store_mode' => Warehouse::MODE_NORMAL,
            'warehouse_id' => 31,
            'is_default' => true,
        ]);
        self::assertSame(31, (int) $rebound['warehouse_id']);
        self::assertSame(1, (int) ($rebound['is_seed'] ?? 0));
        self::assertTrue($auth->isAuthorized(0, 0, 31));
        self::assertFalse($auth->isAuthorized(0, 0, 30));
        self::assertSame(1, $auth->grantCount());

        try {
            $auth->deleteAuthorization(0, (int) $rebound['authorization_id']);
            self::fail('seed authorization must not delete');
        } catch (InventoryConflictException $exception) {
            self::assertSame(WarehouseAuthorizationService::ERROR_SEED_LOCKED, $exception->errorCode());
        }
        self::assertSame(1, $auth->grantCount());
    }

    public function testEnsureDefaultSiteAuthorizationIsIdempotentInMemory(): void
    {
        $auth = WarehouseAuthorizationService::forTesting();
        $auth->registerWarehouse($this->warehouse(40, 0, Warehouse::MODE_NORMAL, true));

        $first = $auth->ensureDefaultSiteAuthorization();
        $second = $auth->ensureDefaultSiteAuthorization();

        self::assertSame(40, (int) $first['warehouse_id']);
        self::assertSame(40, (int) $second['warehouse_id']);
        self::assertSame(1, (int) ($first['is_seed'] ?? 0));
        self::assertSame(1, $auth->grantCount());
    }

    public function testNonSeedAuthorizationCanBeDeleted(): void
    {
        $auth = WarehouseAuthorizationService::forTesting();
        $auth->registerWarehouse($this->warehouse(50, 0, Warehouse::MODE_NORMAL));
        $row = $auth->bind([
            'website_id' => 0,
            'store_id' => 2,
            'store_mode' => Warehouse::MODE_NORMAL,
            'warehouse_id' => 50,
        ]);
        $auth->deleteAuthorization(0, (int) $row['authorization_id']);
        self::assertSame(0, $auth->grantCount());
        self::assertFalse($auth->isAuthorized(0, 2, 50));
    }

    public function testDefaultLogicalResolverIsDeterministic(): void
    {
        $resolver = DefaultLogicalWarehouseResolver::forTesting();
        $resolver->seedWarehouse(1, 0, 'DEFAULT-N', Warehouse::MODE_NORMAL, true);
        $resolver->seedWarehouse(2, 0, 'OTHER-N', Warehouse::MODE_NORMAL, false);

        self::assertSame(1, (int) $resolver->resolve(0, 0, Warehouse::MODE_NORMAL)['warehouse_id']);
        self::assertSame(1, (int) $resolver->resolve(0, 0, Warehouse::MODE_NORMAL)['warehouse_id']);
    }

    public function testAmbiguousDefaultLogicalRejected(): void
    {
        $resolver = DefaultLogicalWarehouseResolver::forTesting();
        $resolver->seedWarehouse(1, 0, 'D1', Warehouse::MODE_NORMAL, true);
        $resolver->seedWarehouse(2, 0, 'D2', Warehouse::MODE_NORMAL, true);

        try {
            $resolver->resolve(0, 0, Warehouse::MODE_NORMAL);
            self::fail('ambiguous default must fail');
        } catch (InventoryConflictException $exception) {
            self::assertSame(
                DefaultLogicalWarehouseResolver::ERROR_AMBIGUOUS,
                $exception->errorCode(),
            );
        }
    }

    public function testExplicitStoreMapRequiresLogicalWarehouseAndOverridesWebsiteDefault(): void
    {
        $resolver = DefaultLogicalWarehouseResolver::forTesting();
        $resolver->seedWarehouse(1, 0, 'FLAG', Warehouse::MODE_NORMAL, true);
        $resolver->seedWarehouse(9, 0, 'MAPPED', Warehouse::MODE_NORMAL, false, [
            Warehouse::schema_fields_WAREHOUSE_TYPE => Warehouse::TYPE_LOGICAL,
        ]);
        $resolver->bindStoreDefault(0, 5, 9);

        self::assertSame(9, (int) $resolver->resolve(0, 5, Warehouse::MODE_NORMAL)['warehouse_id']);
    }

    /** @return array<string, mixed> */
    private function warehouse(int $id, int $websiteId, string $mode, bool $logical = false): array
    {
        return [
            Warehouse::schema_fields_ID => $id,
            Warehouse::schema_fields_WEBSITE_ID => $websiteId,
            Warehouse::schema_fields_WAREHOUSE_CODE => 'WH-' . $id,
            Warehouse::schema_fields_MODE => $mode,
            Warehouse::schema_fields_WAREHOUSE_TYPE => $logical
                ? Warehouse::TYPE_LOGICAL
                : Warehouse::TYPE_PHYSICAL,
            Warehouse::schema_fields_IS_DEFAULT_LOGICAL => $logical ? 1 : 0,
            Warehouse::schema_fields_ENABLED => 1,
        ];
    }
}
