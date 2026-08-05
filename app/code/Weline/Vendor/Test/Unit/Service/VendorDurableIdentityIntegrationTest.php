<?php

declare(strict_types=1);

namespace Weline\Vendor\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Product\Api\ProductIdentity;
use Weline\Product\Api\ProductIdentityResolverInterface;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorProductBindingRecord;
use Weline\Vendor\Model\VendorRecord;
use Weline\Vendor\Model\VendorStoreAccountBindingRecord;
use Weline\Vendor\Model\VendorWebsiteAuthorizationRecord;
use Weline\Vendor\Service\VendorAuthorizationService;
use Weline\Vendor\Service\VendorEligibilityService;
use Weline\Vendor\Service\VendorProductBindingService;
use Weline\Vendor\Service\VendorRegistryStore;
use Weline\Vendor\Service\VendorStoreAccountBindingService;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/** TASK-P4A-001: real SQL durability and fresh-service-instance readback. */
final class VendorDurableIdentityIntegrationTest extends TestCase
{
    public function testSqliteRowsSurviveFreshServiceInstances(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        foreach ([
            VendorRecord::class,
            VendorWebsiteAuthorizationRecord::class,
            VendorStoreAccountBindingRecord::class,
            VendorProductBindingRecord::class,
        ] as $modelClass) {
            self::assertNotNull((new SchemaParser())->parse($modelClass));
        }

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p4a001_vendor_'
            . bin2hex(random_bytes(8))
            . '.sqlite';
        $connectionFactory = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connectionFactory->getConnector();
        $this->createTables($connector);

        $vendorFactory = $this->factory($connectionFactory, VendorRecord::class);
        $authFactory = $this->factory($connectionFactory, VendorWebsiteAuthorizationRecord::class);
        $accountFactory = $this->factory($connectionFactory, VendorStoreAccountBindingRecord::class);
        $bindingFactory = $this->factory($connectionFactory, VendorProductBindingRecord::class);
        $stores = $this->storeCatalog();
        $products = $this->productResolver();

        try {
            $registry = new VendorRegistryStore($vendorFactory);
            $authorization = new VendorAuthorizationService($authFactory);
            $accounts = new VendorStoreAccountBindingService(
                $registry,
                $authorization,
                $stores,
                $accountFactory,
            );
            $eligibility = new VendorEligibilityService($registry, $authorization, $accounts);
            $bindings = new VendorProductBindingService($eligibility, $products, $bindingFactory);

            $vendor = $registry->register([
                'code' => 'durable_vendor',
                'legal_name' => 'Durable Vendor LLC',
                'environment' => VendorIdentity::ENV_SANDBOX,
            ]);
            $authorization->authorizeWebsite((string) $vendor['vendor_id'], 0);
            $account = $accounts->bind([
                'vendor_id' => $vendor['vendor_id'],
                'website_id' => 0,
                'store_id' => 21,
                'environment' => VendorIdentity::ENV_SANDBOX,
                'account_ref' => 'sandbox:acct_durable',
            ]);
            $binding = $bindings->bind([
                'vendor_id' => $vendor['vendor_id'],
                'website_id' => 0,
                'store_id' => 21,
                'product_sku' => 'SKU-DURABLE',
                'required_environment' => VendorIdentity::ENV_SANDBOX,
            ]);
            self::assertSame('bound', $account['status']);
            self::assertSame(501, (int) $binding['product_registry_id']);

            // Recreate every service to prove the result is not process-local array state.
            $freshRegistry = new VendorRegistryStore($vendorFactory);
            $freshAuthorization = new VendorAuthorizationService($authFactory);
            $freshAccounts = new VendorStoreAccountBindingService(
                $freshRegistry,
                $freshAuthorization,
                $stores,
                $accountFactory,
            );
            $freshEligibility = new VendorEligibilityService(
                $freshRegistry,
                $freshAuthorization,
                $freshAccounts,
            );
            $freshBindings = new VendorProductBindingService(
                $freshEligibility,
                $products,
                $bindingFactory,
            );

            self::assertSame('durable_vendor', $freshRegistry->get((string) $vendor['vendor_id'])['code']);
            self::assertTrue($freshAuthorization->isAuthorized((string) $vendor['vendor_id'], 0));
            self::assertSame(
                'sandbox:acct_durable',
                $freshAccounts->assertBound((string) $vendor['vendor_id'], 0, 21)['account_ref'],
            );
            self::assertTrue(
                $freshBindings->isBound((string) $vendor['vendor_id'], 0, 'SKU-DURABLE', 21),
            );
            self::assertTrue($freshEligibility->assertEligible([
                'vendor_id' => $vendor['vendor_id'],
                'website_id' => 0,
                'store_id' => 21,
                'required_environment' => VendorIdentity::ENV_SANDBOX,
            ])['eligible']);

            $freshAuthorization->revoke((string) $vendor['vendor_id'], 0);
            self::assertFalse(
                (new VendorAuthorizationService($authFactory))
                    ->isAuthorized((string) $vendor['vendor_id'], 0),
            );
            self::assertSame(2, (int) $connector->query(
                'SELECT grant_version FROM weline_vendor_website_authorization'
            )->fetch()[0]['grant_version']);
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
        self::assertFileDoesNotExist($dbPath);
    }

    /**
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return \Closure(): T
     */
    private function factory(ConnectionFactory $connectionFactory, string $modelClass): \Closure
    {
        return static function () use ($connectionFactory, $modelClass): Model {
            $model = new $modelClass();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };
    }

    private function storeCatalog(): StoreCatalogInterface
    {
        $store = new StoreSummary(
            21,
            0,
            'durable-test',
            'Durable Test Store',
            'test',
            false,
            true,
            'active',
            null,
        );
        return new class($store) implements StoreCatalogInterface {
            public function __construct(private readonly StoreSummary $store)
            {
            }

            public function byWebsite(int $websiteId): array
            {
                return $websiteId === $this->store->websiteId ? [$this->store] : [];
            }

            public function byCode(int $websiteId, string $storeCode): ?StoreSummary
            {
                return $websiteId === $this->store->websiteId && $storeCode === $this->store->code
                    ? $this->store
                    : null;
            }

            public function byId(int $storeId): ?StoreSummary
            {
                return $storeId === $this->store->id ? $this->store : null;
            }

            public function defaultStore(int $websiteId): ?StoreSummary
            {
                return $websiteId === $this->store->websiteId ? $this->store : null;
            }

            public function all(): array
            {
                return [$this->store];
            }
        };
    }

    private function productResolver(): ProductIdentityResolverInterface
    {
        $identity = new ProductIdentity(
            501,
            'SKU-DURABLE',
            '00000000-0000-4000-8000-000000000501',
            '10000000-0000-4000-8000-000000000501',
            str_repeat('cd', 32),
        );
        return new class($identity) implements ProductIdentityResolverInterface {
            public function __construct(private readonly ProductIdentity $identity)
            {
            }

            public function resolveBySku(string $sku): ?ProductIdentity
            {
                return $sku === $this->identity->sku ? $this->identity : null;
            }

            public function resolveByProductUuid(string $uuid): ?ProductIdentity
            {
                return $uuid === $this->identity->globalProductUuid ? $this->identity : null;
            }

            public function resolveByOfferUuid(string $uuid): ?ProductIdentity
            {
                return $uuid === $this->identity->globalOfferUuid ? $this->identity : null;
            }
        };
    }

    private function createTables(ConnectorInterface $connector): void
    {
        $queries = [
            'CREATE TABLE weline_vendor_identity ('
                . 'identity_id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'code VARCHAR(64) NOT NULL, legal_name VARCHAR(255) NOT NULL, environment VARCHAR(16) NOT NULL, '
                . "status VARCHAR(16) NOT NULL, account_ref VARCHAR(255) NOT NULL DEFAULT '', "
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(code, environment))',
            'CREATE TABLE weline_vendor_website_authorization ('
                . 'authorization_id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, status VARCHAR(16) NOT NULL, grant_version INTEGER NOT NULL DEFAULT 1, '
                . 'authorized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at DATETIME NULL, '
                . 'UNIQUE(vendor_id, website_id))',
            'CREATE TABLE weline_vendor_store_account_binding ('
                . 'binding_id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, store_mode_snapshot VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, account_ref VARCHAR(255) NOT NULL, '
                . 'account_ref_hash VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, '
                . 'binding_version INTEGER NOT NULL DEFAULT 1, bound_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'revoked_at DATETIME NULL, UNIQUE(vendor_id, website_id, store_id))',
            'CREATE TABLE weline_vendor_product_binding ('
                . 'binding_id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, product_registry_id INTEGER NOT NULL, '
                . 'product_sku VARCHAR(128) NOT NULL, global_product_uuid VARCHAR(36) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, '
                . 'binding_version INTEGER NOT NULL DEFAULT 1, bound_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'unbound_at DATETIME NULL, UNIQUE(vendor_id, website_id, store_id, product_registry_id))',
        ];
        foreach ($queries as $query) {
            $connector->query($query)->fetch();
        }
    }
}
