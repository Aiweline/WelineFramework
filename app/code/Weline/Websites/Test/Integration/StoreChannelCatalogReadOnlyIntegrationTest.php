<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAuthorizationResult;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot;
use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Extends\Module\Weline_Framework\Query\WebsitesQueryProvider;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\SalesChannelCatalog;
use Weline\Websites\Service\StoreCatalog;
use Weline\Websites\Service\WebsiteStoreChannelDirectory;

final class StoreChannelCatalogReadOnlyIntegrationTest extends TestCase
{
    private ConnectionFactory $connection;
    private WriteIntentTransactionCoordinatorInterface $transactions;
    private StoreCatalogInterface $storeCatalog;
    private SalesChannelCatalogInterface $salesChannelCatalog;
    private WebsiteStoreChannelDirectory $directory;
    private WebsitesQueryProvider $queryProvider;
    private StoreChannelCatalogAllowGuard $objectAuthorizationGuard;
    private NamespaceGenerationRepository $generations;
    private string $catalogGenerationPath;

    protected function setUp(): void
    {
        $prefix = 'p1a002_catalog_' . \getmypid() . '_' . \bin2hex(\random_bytes(3)) . '_';
        $this->connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'path' => ':memory:',
            'prefix' => $prefix,
            'persistent' => false,
        ]));
        $this->transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $namespacePath = new NamespacePath();
        $this->catalogGenerationPath = $namespacePath->website('default', ['catalog']);

        $this->createSqliteSchema();
        $this->seedSystemDefaultScope();

        $this->storeCatalog = new StoreCatalog($this->newStore(), $this->newWebsite());
        $this->salesChannelCatalog = new SalesChannelCatalog($this->newChannel(), $this->storeCatalog);
        $this->directory = new WebsiteStoreChannelDirectory(
            $this->storeCatalog,
            $this->salesChannelCatalog,
        );
        $this->objectAuthorizationGuard = new StoreChannelCatalogAllowGuard();
        $this->queryProvider = new WebsitesQueryProvider(
            ObjectManager::getInstance(\Weline\Websites\Service\DomainRegistrarResolverService::class),
            ObjectManager::getInstance(\Weline\Websites\Service\DomainSyncService::class),
            ObjectManager::getInstance(\Weline\Websites\Model\DomainRegistrarAccount::class, [], false),
            ObjectManager::getInstance(\Weline\Websites\Model\DomainRegistrar::class, [], false),
            $this->newWebsite(),
            ObjectManager::getInstance(\Weline\Websites\Model\WebsiteLanguage::class, [], false),
            ObjectManager::getInstance(\Weline\Websites\Service\DnsProviderDetector::class),
            ObjectManager::getInstance(\Weline\Websites\Service\DefaultWebsiteService::class),
            $this->storeCatalog,
            $this->salesChannelCatalog,
            $this->objectAuthorizationGuard,
        );
        $generationModel = ObjectManager::getInstance(NamespaceVersion::class, [], false)
            ->setConnection($this->connection);
        $this->generations = new NamespaceGenerationRepository(
            $generationModel,
            $namespacePath,
            new NamespaceGenerationSnapshot(),
            new NamespaceKeyDecorator(),
            $this->transactions,
        );
        $this->generations->ensureAuthorityClock();
        $this->generations->bump($this->catalogGenerationPath);

        self::assertSame(
            'sqlite',
            \strtolower((string)$this->connection->getConnector()->getConfigProvider()->getDbType()),
            'This integration contract must run against the real SQLite test database.',
        );
    }

    public function testRepeatedZeroWebsiteReadsAreStableAndDoNotMutateRowsOrGeneration(): void
    {
        $beforeRows = $this->stableDatabaseSummary();
        $beforeGeneration = $this->catalogGeneration();

        $first = $this->readAllCatalogSurfaces(0);
        $second = $this->readAllCatalogSurfaces(0);

        self::assertSame($first, $second);
        self::assertSame($first['stores'], $first['provider_stores']);
        self::assertNotEmpty($first['stores']);
        self::assertSame(0, $first['stores'][0]['website_id']);

        $defaultStores = \array_values(\array_filter(
            $first['stores'],
            static fn(array $store): bool => ($store['code'] ?? null) === Store::CODE_DEFAULT,
        ));
        self::assertCount(1, $defaultStores);
        self::assertTrue($defaultStores[0]['is_default']);
        self::assertSame(Store::MODE_NORMAL, $defaultStores[0]['store_mode']);
        self::assertTrue($defaultStores[0]['enabled']);
        self::assertSame(Store::LIFECYCLE_ACTIVE, $defaultStores[0]['lifecycle_status']);

        $defaultStoreId = (int)$defaultStores[0]['store_id'];
        self::assertGreaterThan(0, $defaultStoreId);
        self::assertArrayHasKey($defaultStoreId, $first['channels']);
        self::assertCount(1, $first['channels'][$defaultStoreId]);
        self::assertTrue($first['channels'][$defaultStoreId][0]['effective_enabled']);
        self::assertSame(0, $first['channels'][$defaultStoreId][0]['website_id']);
        self::assertSame($defaultStoreId, $first['directory'][0]['store_id']);
        self::assertSame($first['channels'][$defaultStoreId], $first['directory'][0]['channels']);

        self::assertSame($beforeRows, $this->stableDatabaseSummary());
        self::assertSame($beforeGeneration, $this->catalogGeneration());
    }

    public function testDeniedRoleReceivesEmptyStoreAndChannelCatalogWithoutExistenceLeak(): void
    {
        $store = $this->storeCatalog->byWebsite(0)[0] ?? null;
        self::assertNotNull($store);

        $this->objectAuthorizationGuard->allowed = false;

        self::assertSame([], $this->queryProvider->execute(
            'getStoreCatalogV1',
            ['website_id' => 0],
        ));
        self::assertSame([], $this->queryProvider->execute(
            'getSalesChannelCatalogV1',
            ['store_id' => $store->id],
        ));
    }

    public function testDisabledAndTombstonedStoresRemainVisibleWithFailClosedChannelState(): void
    {
        $beforeRows = $this->stableDatabaseSummary();
        $beforeGeneration = $this->catalogGeneration();
        $token = 'p1a002_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        $disabledCode = $token . '_disabled';
        $tombstoneCode = $token . '_tombstone';

        try {
            $this->transactions->runWrite($this->connection, function () use (
                $beforeGeneration,
                $disabledCode,
                $tombstoneCode,
            ): void {
                $disabledStore = $this->createStore($disabledCode, Store::MODE_DEV, false);
                $disabledChannel = $this->createChannel($disabledStore->getStoreId(), $disabledCode . '_web');

                $tombstonedStore = $this->createStore($tombstoneCode, Store::MODE_TEST, true);
                $tombstonedChannel = $this->createChannel(
                    $tombstonedStore->getStoreId(),
                    $tombstoneCode . '_web',
                );
                $this->tombstoneStore($tombstonedStore->getStoreId());
                $tombstonedStore = $this->newStore()
                    ->where(Store::schema_fields_ID, $tombstonedStore->getStoreId())
                    ->find()
                    ->fetch();

                $stores = $this->indexBy($this->queryProvider->execute(
                    'getStoreCatalogV1',
                    ['website_id' => 0],
                ), 'code');
                self::assertArrayHasKey($disabledCode, $stores);
                self::assertFalse($stores[$disabledCode]['enabled']);
                self::assertSame(Store::LIFECYCLE_ACTIVE, $stores[$disabledCode]['lifecycle_status']);
                self::assertNull($stores[$disabledCode]['tombstoned_at']);
                self::assertArrayHasKey($tombstoneCode, $stores);
                self::assertFalse($stores[$tombstoneCode]['enabled']);
                self::assertSame(Store::LIFECYCLE_TOMBSTONE, $stores[$tombstoneCode]['lifecycle_status']);
                self::assertNotEmpty($stores[$tombstoneCode]['tombstoned_at']);

                $disabledChannels = $this->queryProvider->execute(
                    'getSalesChannelCatalogV1',
                    ['store_id' => $disabledStore->getStoreId()],
                );
                self::assertCount(1, $disabledChannels);
                self::assertSame($disabledChannel->getChannelId(), $disabledChannels[0]['channel_id']);
                self::assertTrue($disabledChannels[0]['enabled']);
                self::assertSame(Store::LIFECYCLE_ACTIVE, $disabledChannels[0]['parent_store_lifecycle_status']);
                self::assertFalse($disabledChannels[0]['effective_enabled']);

                $tombstonedChannels = $this->salesChannelCatalog->byStore(
                    $tombstonedStore->getStoreId(),
                );
                self::assertCount(1, $tombstonedChannels);
                self::assertSame($tombstonedChannel->getChannelId(), $tombstonedChannels[0]->id);
                self::assertTrue($tombstonedChannels[0]->enabled);
                self::assertSame(
                    Store::LIFECYCLE_TOMBSTONE,
                    $tombstonedChannels[0]->parentStoreLifecycleStatus,
                );
                self::assertFalse($tombstonedChannels[0]->effectiveEnabled);

                $directory = $this->indexBy($this->directory->forWebsite(0), 'code');
                self::assertFalse($directory[$disabledCode]['channels'][0]['effective_enabled']);
                self::assertFalse($directory[$tombstoneCode]['channels'][0]['effective_enabled']);

                self::assertSame($beforeGeneration, $this->catalogGeneration());
                throw new StoreChannelCatalogRollbackProbe();
            });
            self::fail('The owner transaction fixture must roll back.');
        } catch (StoreChannelCatalogRollbackProbe) {
        }

        self::assertSame(0, $this->countStoresByCode($disabledCode));
        self::assertSame(0, $this->countStoresByCode($tombstoneCode));
        self::assertSame(0, $this->countChannelsByCode($disabledCode . '_web'));
        self::assertSame(0, $this->countChannelsByCode($tombstoneCode . '_web'));
        self::assertSame($beforeRows, $this->stableDatabaseSummary());
        self::assertSame($beforeGeneration, $this->catalogGeneration());
    }

    public function testOrphanChannelProjectionFailsClosedAndFixtureRollsBack(): void
    {
        $beforeRows = $this->stableDatabaseSummary();
        $beforeGeneration = $this->catalogGeneration();
        $code = 'p1a002_orphan_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        $orphanStoreId = 2147483647;

        try {
            $this->transactions->runWrite($this->connection, function () use ($code, $orphanStoreId): void {
                self::assertNull($this->storeCatalog->byId($orphanStoreId));
                $channelId = $this->insertOrphanChannel($code, $orphanStoreId);

                try {
                    $this->salesChannelCatalog->byId($channelId);
                    self::fail('An orphan SalesChannel must never be projected as valid Catalog data.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('不存在的父店铺', $exception->getMessage());
                }

                throw new StoreChannelCatalogRollbackProbe();
            });
            self::fail('The orphan fixture transaction must roll back.');
        } catch (StoreChannelCatalogRollbackProbe) {
        }

        self::assertSame(0, $this->countChannelsByCode($code));
        self::assertSame($beforeRows, $this->stableDatabaseSummary());
        self::assertSame($beforeGeneration, $this->catalogGeneration());
    }

    public function testOrphanStoreProjectionFailsClosedAndFixtureRollsBack(): void
    {
        $beforeRows = $this->stableDatabaseSummary();
        $beforeGeneration = $this->catalogGeneration();
        $code = 'p1a002_orphan_store_' . \getmypid() . '_' . \bin2hex(\random_bytes(3));
        $orphanWebsiteId = 2147483647;

        try {
            $this->transactions->runWrite(
                $this->connection,
                function () use ($beforeGeneration, $code, $orphanWebsiteId): void {
                    $this->insertRow($this->newStore(), [
                        Store::schema_fields_WEBSITE_ID => $orphanWebsiteId,
                        Store::schema_fields_CODE => $code,
                        Store::schema_fields_NAME => $code,
                        Store::schema_fields_STORE_MODE => Store::MODE_TEST,
                        Store::schema_fields_IS_DEFAULT => 0,
                        Store::schema_fields_STATUS => 0,
                        Store::schema_fields_URL => null,
                        Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_ACTIVE,
                        Store::schema_fields_TOMBSTONED_AT => null,
                    ]);
                    $matches = $this->newStore()
                        ->where(Store::schema_fields_WEBSITE_ID, $orphanWebsiteId)
                        ->where(Store::schema_fields_CODE, $code)
                        ->select()
                        ->fetchArray();
                    self::assertCount(1, $matches);
                    $storeId = (int)$matches[0][Store::schema_fields_ID];
                    self::assertGreaterThan(0, $storeId);

                    foreach ([
                        fn() => $this->storeCatalog->byWebsite($orphanWebsiteId),
                        fn() => $this->storeCatalog->byId($storeId),
                        fn() => $this->storeCatalog->all(),
                    ] as $read) {
                        try {
                            $read();
                            self::fail('An orphan Store must never be projected as valid Catalog data.');
                        } catch (\RuntimeException $exception) {
                            self::assertStringContainsString('不存在的父 Website', $exception->getMessage());
                        }
                    }

                    self::assertSame($beforeGeneration, $this->catalogGeneration());
                    throw new StoreChannelCatalogRollbackProbe();
                },
            );
            self::fail('The orphan Store fixture transaction must roll back.');
        } catch (StoreChannelCatalogRollbackProbe) {
        }

        self::assertSame(0, $this->countStoresByCode($code, $orphanWebsiteId));
        self::assertSame($beforeRows, $this->stableDatabaseSummary());
        self::assertSame($beforeGeneration, $this->catalogGeneration());
    }

    /**
     * @return array{
     *     stores:list<array<string, mixed>>,
     *     channels:array<int, list<array<string, mixed>>>,
     *     directory:list<array<string, mixed>>,
     *     provider_stores:list<array<string, mixed>>
     * }
     */
    private function readAllCatalogSurfaces(int $websiteId): array
    {
        $stores = \array_map(
            static fn($store): array => $store->toArray(),
            $this->storeCatalog->byWebsite($websiteId),
        );
        $channels = [];
        foreach ($stores as $store) {
            $storeId = (int)$store['store_id'];
            $channels[$storeId] = $this->queryProvider->execute(
                'getSalesChannelCatalogV1',
                ['store_id' => $storeId],
            );
        }

        return [
            'stores' => $stores,
            'channels' => $channels,
            'directory' => $this->directory->forWebsite($websiteId),
            'provider_stores' => $this->queryProvider->execute(
                'getStoreCatalogV1',
                ['website_id' => $websiteId],
            ),
        ];
    }

    private function createStore(string $code, string $mode, bool $enabled): Store
    {
        $this->insertRow($this->newStore(), [
            Store::schema_fields_WEBSITE_ID => 0,
            Store::schema_fields_CODE => $code,
            Store::schema_fields_NAME => $code,
            Store::schema_fields_STORE_MODE => $mode,
            Store::schema_fields_IS_DEFAULT => 0,
            Store::schema_fields_STATUS => $enabled ? 1 : 0,
            Store::schema_fields_URL => null,
            Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_ACTIVE,
            Store::schema_fields_TOMBSTONED_AT => null,
        ]);
        $store = $this->newStore()
            ->where(Store::schema_fields_WEBSITE_ID, 0)
            ->where(Store::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        self::assertGreaterThan(0, $store->getStoreId());

        return $store;
    }

    private function createChannel(int $storeId, string $code): SalesChannel
    {
        $this->insertRow($this->newChannel(), [
            SalesChannel::schema_fields_WEBSITE_ID => 0,
            SalesChannel::schema_fields_STORE_ID => $storeId,
            SalesChannel::schema_fields_CODE => $code,
            SalesChannel::schema_fields_NAME => $code,
            SalesChannel::schema_fields_IS_DEFAULT => 0,
            SalesChannel::schema_fields_STATUS => 1,
        ]);
        $channel = $this->newChannel()
            ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
            ->where(SalesChannel::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        self::assertGreaterThan(0, $channel->getChannelId());

        return $channel;
    }

    private function insertOrphanChannel(string $code, int $storeId): int
    {
        $row = [
            SalesChannel::schema_fields_WEBSITE_ID => 0,
            SalesChannel::schema_fields_STORE_ID => $storeId,
            SalesChannel::schema_fields_CODE => $code,
            SalesChannel::schema_fields_NAME => $code,
            SalesChannel::schema_fields_IS_DEFAULT => 0,
            SalesChannel::schema_fields_STATUS => 1,
        ];
        $this->insertRow($this->newChannel(), $row);

        $matches = $this->newChannel()
            ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
            ->where(SalesChannel::schema_fields_CODE, $code)
            ->select()
            ->fetchArray();
        self::assertCount(1, $matches);

        return (int)$matches[0][SalesChannel::schema_fields_ID];
    }

    private function tombstoneStore(int $storeId): void
    {
        $connector = $this->connection->getConnector();
        $statement = $connector->getWrappedConnection()->prepare(
            'UPDATE ' . $this->newStore()->getTable()
            . ' SET ' . $connector->quoteIdentifier(Store::schema_fields_STATUS) . ' = 0, '
            . $connector->quoteIdentifier(Store::schema_fields_LIFECYCLE_STATUS) . ' = :lifecycle_status, '
            . $connector->quoteIdentifier(Store::schema_fields_TOMBSTONED_AT) . ' = :tombstoned_at'
            . ' WHERE ' . $connector->quoteIdentifier(Store::schema_fields_ID) . ' = :store_id',
        );
        $statement->execute([
            'lifecycle_status' => Store::LIFECYCLE_TOMBSTONE,
            'tombstoned_at' => '2026-07-24 00:00:00',
            'store_id' => $storeId,
        ]);
        self::assertSame(1, $statement->rowCount());
    }

    /** @param array<string, int|string|null> $row */
    private function insertRow(Website|Store|SalesChannel $model, array $row): void
    {
        $connector = $this->connection->getConnector();
        $columns = \array_keys($row);
        $quotedColumns = \array_map(
            static fn(string $column): string => $connector->quoteIdentifier($column),
            $columns,
        );
        $placeholders = \array_map(static fn(string $column): string => ':' . $column, $columns);
        $statement = $connector->getWrappedConnection()->prepare(
            'INSERT INTO ' . $model->getTable()
            . ' (' . \implode(', ', $quotedColumns) . ') VALUES (' . \implode(', ', $placeholders) . ')',
        );
        $statement->execute($row);
        self::assertSame(1, $statement->rowCount());
    }

    private function createSqliteSchema(): void
    {
        $connection = $this->connection->getConnector()->getWrappedConnection();
        $connection->execute('CREATE TABLE ' . $this->newWebsite()->getTable() . ' (
            website_id INTEGER PRIMARY KEY,
            code VARCHAR(64) NOT NULL UNIQUE
        )');
        $connection->execute('CREATE TABLE ' . $this->newStore()->getTable() . ' (
            store_id INTEGER PRIMARY KEY AUTOINCREMENT,
            website_id INTEGER NOT NULL,
            code VARCHAR(64) NOT NULL,
            name VARCHAR(128) NOT NULL,
            store_mode VARCHAR(16) NOT NULL,
            is_default SMALLINT NOT NULL,
            status SMALLINT NOT NULL,
            url VARCHAR(255) NULL,
            lifecycle_status VARCHAR(16) NOT NULL,
            tombstoned_at DATETIME NULL,
            UNIQUE (website_id, code)
        )');
        $connection->execute('CREATE TABLE ' . $this->newChannel()->getTable() . ' (
            channel_id INTEGER PRIMARY KEY AUTOINCREMENT,
            website_id INTEGER NOT NULL,
            store_id INTEGER NOT NULL,
            code VARCHAR(64) NOT NULL,
            name VARCHAR(128) NOT NULL,
            is_default SMALLINT NOT NULL,
            status SMALLINT NOT NULL,
            UNIQUE (store_id, code)
        )');
        $generation = ObjectManager::getInstance(NamespaceVersion::class, [], false)
            ->setConnection($this->connection);
        $connection->execute('CREATE TABLE ' . $generation->getTable() . ' (
            namespace_hash CHAR(64) PRIMARY KEY,
            namespace VARCHAR(512) NOT NULL,
            generation BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        )');
    }

    private function seedSystemDefaultScope(): void
    {
        $this->insertRow($this->newWebsite(), [
            Website::schema_fields_ID => Website::ID_DEFAULT,
            Website::schema_fields_CODE => 'default',
        ]);
        $this->insertRow($this->newStore(), [
            Store::schema_fields_ID => 1,
            Store::schema_fields_WEBSITE_ID => Website::ID_DEFAULT,
            Store::schema_fields_CODE => Store::CODE_DEFAULT,
            Store::schema_fields_NAME => '默认店铺',
            Store::schema_fields_STORE_MODE => Store::MODE_NORMAL,
            Store::schema_fields_IS_DEFAULT => 1,
            Store::schema_fields_STATUS => 1,
            Store::schema_fields_URL => null,
            Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_ACTIVE,
            Store::schema_fields_TOMBSTONED_AT => null,
        ]);
        $this->insertRow($this->newChannel(), [
            SalesChannel::schema_fields_ID => 1,
            SalesChannel::schema_fields_WEBSITE_ID => Website::ID_DEFAULT,
            SalesChannel::schema_fields_STORE_ID => 1,
            SalesChannel::schema_fields_CODE => SalesChannel::CODE_DEFAULT,
            SalesChannel::schema_fields_NAME => '默认渠道',
            SalesChannel::schema_fields_IS_DEFAULT => 1,
            SalesChannel::schema_fields_STATUS => 1,
        ]);
    }

    /** @return array{store:array{count:int,sha256:string},channel:array{count:int,sha256:string}} */
    private function stableDatabaseSummary(): array
    {
        return [
            'store' => $this->modelSummary(
                $this->newStore(),
                Store::schema_fields_ID,
                [
                    Store::schema_fields_ID,
                    Store::schema_fields_WEBSITE_ID,
                    Store::schema_fields_CODE,
                    Store::schema_fields_NAME,
                    Store::schema_fields_STORE_MODE,
                    Store::schema_fields_IS_DEFAULT,
                    Store::schema_fields_STATUS,
                    Store::schema_fields_URL,
                    Store::schema_fields_LIFECYCLE_STATUS,
                    Store::schema_fields_TOMBSTONED_AT,
                ],
            ),
            'channel' => $this->modelSummary(
                $this->newChannel(),
                SalesChannel::schema_fields_ID,
                [
                    SalesChannel::schema_fields_ID,
                    SalesChannel::schema_fields_WEBSITE_ID,
                    SalesChannel::schema_fields_STORE_ID,
                    SalesChannel::schema_fields_CODE,
                    SalesChannel::schema_fields_NAME,
                    SalesChannel::schema_fields_IS_DEFAULT,
                    SalesChannel::schema_fields_STATUS,
                ],
            ),
        ];
    }

    /**
     * @param list<string> $fields
     * @return array{count:int,sha256:string}
     */
    private function modelSummary(Store|SalesChannel $model, string $idField, array $fields): array
    {
        $rows = $model->order($idField, 'ASC')->select()->fetchArray();
        $stableRows = [];
        foreach ($rows as $row) {
            $stable = [];
            foreach ($fields as $field) {
                $stable[$field] = $row[$field] ?? null;
            }
            $stableRows[] = $stable;
        }

        return [
            'count' => \count($stableRows),
            'sha256' => \hash(
                'sha256',
                \json_encode(
                    $stableRows,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function indexBy(array $rows, string $field): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string)$row[$field]] = $row;
        }
        return $indexed;
    }

    private function catalogGeneration(): int
    {
        $this->generations->clearSnapshot();
        $vector = $this->generations->resolveVector([$this->catalogGenerationPath]);
        return (int)($vector['generations'][$this->catalogGenerationPath] ?? 0);
    }

    private function countStoresByCode(string $code, int $websiteId = 0): int
    {
        return \count($this->newStore()
            ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Store::schema_fields_CODE, $code)
            ->select()
            ->fetchArray());
    }

    private function countChannelsByCode(string $code): int
    {
        return \count($this->newChannel()
            ->where(SalesChannel::schema_fields_WEBSITE_ID, 0)
            ->where(SalesChannel::schema_fields_CODE, $code)
            ->select()
            ->fetchArray());
    }

    private function newStore(): Store
    {
        return ObjectManager::getInstance(Store::class, [], false)->setConnection($this->connection);
    }

    private function newChannel(): SalesChannel
    {
        return ObjectManager::getInstance(SalesChannel::class, [], false)->setConnection($this->connection);
    }

    private function newWebsite(): Website
    {
        return ObjectManager::getInstance(Website::class, [], false)->setConnection($this->connection);
    }
}

final class StoreChannelCatalogRollbackProbe extends \RuntimeException
{
}

final class StoreChannelCatalogAllowGuard implements BackendObjectAuthorizationGuardInterface
{
    public bool $allowed = true;

    public function currentRoleId(): int
    {
        return 1;
    }

    public function check(string $action, ScopeIdentity $scope): ObjectAuthorizationResult
    {
        return $this->allowed
            ? ObjectAuthorizationResult::allow('integration_fixture', 1)
            : ObjectAuthorizationResult::deny('integration_fixture_denied');
    }

    public function checkForSubmit(
        string $action,
        ScopeIdentity $scope,
        int $expectedGrantVersion,
    ): ObjectAuthorizationResult {
        return $this->check($action, $scope);
    }

    public function isAllowed(string $action, ScopeIdentity $scope): bool
    {
        return $this->allowed;
    }

    public function requireForQuery(string $action, ScopeIdentity $scope): ObjectAuthorizationResult
    {
        return $this->check($action, $scope);
    }

    public function requireSubmitForQuery(
        string $action,
        ScopeIdentity $scope,
        int $expectedGrantVersion,
    ): ObjectAuthorizationResult {
        return $this->check($action, $scope);
    }

    public function denyForQuery(string $action, ScopeIdentity $scope): never
    {
        throw new \RuntimeException('integration_fixture_denied');
    }
}
