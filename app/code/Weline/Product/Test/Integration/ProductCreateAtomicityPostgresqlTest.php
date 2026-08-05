<?php

declare(strict_types=1);

namespace Weline\Product\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductIdentity;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\Shard\StoreProduct;
use Weline\Product\Model\SkuRegistry;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\SkuRegistryService;

/**
 * TEST-MIG-P2-06: a controlled failure after global registry DML or after the
 * Website/Store shard writes rolls the complete create unit back.
 */
final class ProductCreateAtomicityPostgresqlTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $database = trim((string)getenv('WELINE_PRODUCT_MIGRATION_TEST_DATABASE'));
        if ($database === '') {
            self::markTestSkipped(
                'WELINE_PRODUCT_MIGRATION_TEST_DATABASE must identify a registered mig_clone_* PostgreSQL database',
            );
        }

        $env = include BP . '/app/etc/env.php';
        $db = is_array($env) ? ($env['db']['master'] ?? $env['db'] ?? []) : [];
        if (!is_array($db)) {
            self::fail('master database config is unavailable');
        }
        $db['database'] = $database;

        ObjectManager::clearInstances();
        $binding = ObjectManager::getInstance(MigrationTargetBinder::class)->bindIsolated($db);
        self::assertSame($database, $binding['database']);
        self::assertNotSame('', $binding['fingerprint']);
    }

    public function testRegistryWebsiteAndStoreRowsRollbackAtBothControlledFailurePoints(): void
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class);
        self::assertSame(
            'pgsql',
            strtolower($connection->getConnector()->getConfigProvider()->getDbType()),
        );
        ObjectManager::getInstance(ProductShardProvisioner::class)->assertReady(0);

        foreach (['after_registry', 'after_store_overlay'] as $failurePoint) {
            $this->assertFailurePointRollsBack($failurePoint, $connection);
        }
    }

    private function assertFailurePointRollsBack(string $failurePoint, ConnectionFactory $connection): void
    {
        $token = strtolower(bin2hex(random_bytes(8)));
        $sku = 'R43-P2-06-' . strtoupper($token);
        $requestHash = hash('sha256', $sku);
        $identity = null;
        $productId = 0;

        $registry = ObjectManager::getInstance(SkuRegistryService::class);
        $products = ObjectManager::getInstance(ProductRepository::class);
        $storeProducts = ObjectManager::getInstance(StoreProductRepository::class);
        $transactions = ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
        $tables = $this->tables();
        $before = $this->counts($connection, $tables);

        try {
            try {
                $transactions->run(
                    $connection,
                    function () use (
                        $failurePoint,
                        $sku,
                        $requestHash,
                        $registry,
                        $products,
                        $storeProducts,
                        &$identity,
                        &$productId,
                    ): void {
                        $identity = $registry->claimLocked($sku, $requestHash);
                        if ($failurePoint === 'after_registry') {
                            throw new \RuntimeException('controlled_product_create_failure_after_registry');
                        }

                        $product = $products->create(0, [
                            Product::schema_fields_SKU => $identity->sku,
                            Product::schema_fields_GLOBAL_PRODUCT_UUID => $identity->globalProductUuid,
                        ]);
                        $productId = (int)$product->getId();
                        $storeProducts->select(0, 1, $productId, true);
                        throw new \RuntimeException('controlled_product_create_failure_after_store_overlay');
                    },
                );
                self::fail('controlled failure must escape the create transaction');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'controlled_product_create_failure_' . $failurePoint,
                    $exception->getMessage(),
                );
            }

            self::assertNull($registry->resolveBySku($sku));
            if ($identity instanceof ProductIdentity) {
                self::assertNull($products->findByGlobalUuid(0, $identity->globalProductUuid));
            }
            if ($productId > 0) {
                self::assertNull($storeProducts->find(0, 1, $productId));
            }
            self::assertSame($before, $this->counts($connection, $tables));
        } finally {
            $this->cleanup($connection, $tables, $sku, $identity, $productId);
        }
    }

    /** @return array{registry:string,website:string,store:string} */
    private function tables(): array
    {
        /** @var SkuRegistry $registry */
        $registry = ObjectManager::create(SkuRegistry::class, [], false);
        /** @var Product $product */
        $product = ObjectManager::create(Product::class, [], false)->forWebsite(0);
        /** @var StoreProduct $storeProduct */
        $storeProduct = ObjectManager::create(StoreProduct::class, [], false)->forWebsite(0);

        return [
            'registry' => $registry->getTable(),
            'website' => $product->getTable(),
            'store' => $storeProduct->getTable(),
        ];
    }

    /** @param array{registry:string,website:string,store:string} $tables @return array<string,int> */
    private function counts(ConnectionFactory $connection, array $tables): array
    {
        $link = $connection->getConnector()->getLink();
        $counts = [];
        foreach ($tables as $key => $table) {
            $counts[$key] = (int)$link->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }

        return $counts;
    }

    /** @param array{registry:string,website:string,store:string} $tables */
    private function cleanup(
        ConnectionFactory $connection,
        array $tables,
        string $sku,
        ?ProductIdentity $identity,
        int $productId,
    ): void {
        $link = $connection->getConnector()->getLink();
        if ($productId > 0) {
            $statement = $link->prepare('DELETE FROM ' . $tables['store'] . ' WHERE product_id = ?');
            $statement->execute([$productId]);
        }
        if ($identity instanceof ProductIdentity) {
            $statement = $link->prepare('DELETE FROM ' . $tables['website'] . ' WHERE global_product_uuid = ?');
            $statement->execute([$identity->globalProductUuid]);
        }
        $statement = $link->prepare('DELETE FROM ' . $tables['registry'] . ' WHERE sku = ?');
        $statement->execute([$sku]);
    }
}
