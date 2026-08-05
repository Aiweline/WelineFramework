<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\Exception\RollbackOnlyException;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context as SetupContext;
use Weline\Framework\Setup\Data\Setup as SetupData;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ScopeMigrationService;
use Weline\Websites\Service\StoreChannelSeedService;
use Weline\Websites\Setup\Upgrade as WebsitesUpgrade;

/**
 * TEST-P1A-01, TEST-MIG-P1A-01 and TEST-MIG-P1A-02:
 * default Store/Channel seeding is atomic, complete and idempotent.
 */
final class StoreChannelSeedIntegrationTest extends TestCase
{
    private ConnectionFactory $connection;
    private WriteIntentTransactionCoordinatorInterface $transactions;
    private StoreChannelSeedService $seeder;

    protected function setUp(): void
    {
        $this->connection = ObjectManager::getInstance(ConnectionFactory::class);
        $this->transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $this->seeder = ObjectManager::getInstance(StoreChannelSeedService::class);
    }

    public function testWebsiteSaveAndRepeatedSeedAreAtomicAndIdempotent(): void
    {
        $token = 'p1a001_' . bin2hex(random_bytes(5));
        $websiteId = 0;
        try {
            $this->transactions->runWrite($this->connection, function () use ($token, &$websiteId): void {
                $website = $this->newWebsite($token);
                $website->save();
                $websiteId = $website->getWebsiteId();
                self::assertGreaterThan(Website::ID_DEFAULT, $websiteId);

                [$store, $channel] = $this->assertDefaultPair($websiteId);
                self::assertSame(Store::MODE_NORMAL, $store->getStoreMode());
                self::assertTrue($store->isEnabled());
                self::assertTrue($channel->isEnabled());

                for ($attempt = 0; $attempt < 3; $attempt++) {
                    self::assertSame(
                        ['stores_created' => 0, 'channels_created' => 0],
                        $this->seeder->ensureDefaultsForWebsite($websiteId, $website->getName(), $this->connection),
                    );
                }
                $this->assertDefaultPair($websiteId);
                throw new StoreChannelSeedRollbackProbe();
            });
            self::fail('The fixture transaction must roll back.');
        } catch (StoreChannelSeedRollbackProbe) {
        }

        self::assertSame(0, $this->countRows(Website::class, Website::schema_fields_CODE, $token));
        if ($websiteId > Website::ID_DEFAULT) {
            self::assertSame(0, $this->countRows(Store::class, Store::schema_fields_WEBSITE_ID, $websiteId));
            self::assertSame(0, $this->countRows(SalesChannel::class, SalesChannel::schema_fields_WEBSITE_ID, $websiteId));
        }
    }

    public function testStoreChannelAndWebsiteWriteGuardsRejectInvalidTransitions(): void
    {
        $token = 'p1a001_guard_' . bin2hex(random_bytes(5));
        try {
            $this->transactions->runWrite($this->connection, function () use ($token): void {
                $website = $this->newWebsite($token);
                $website->save();
                $websiteId = $website->getWebsiteId();
                [$defaultStore, $defaultChannel] = $this->assertDefaultPair($websiteId);

                $this->expectSavepointFailure(
                    'missing_parent_store',
                    fn(): bool|int => $this->newStore()
                        ->setWebsiteId(2_147_483_000)
                        ->setCode('orphan')
                        ->setName('Orphan')
                        ->setStoreMode(Store::MODE_TEST)
                        ->setIsDefault(false)
                        ->setStatus(true)
                        ->save(),
                    'Website 不存在',
                );
                $this->expectSavepointFailure(
                    'missing_seed_website',
                    fn(): array => $this->seeder->ensureDefaultsForWebsite(2_147_483_000, 'Missing'),
                    'Website 不存在',
                );

                $store = $this->newStore()
                    ->setWebsiteId($websiteId)
                    ->setCode('sandbox')
                    ->setName('Sandbox')
                    ->setStoreMode(Store::MODE_TEST)
                    ->setIsDefault(false)
                    ->setStatus(true);
                $store->save();
                self::assertSame(Store::MODE_TEST, $store->getStoreMode());

                $this->expectSavepointFailure(
                    'store_code_length',
                    fn(): bool|int => $this->newStore()
                        ->setWebsiteId($websiteId)
                        ->setCode(str_repeat('a', Store::CODE_MAX_LENGTH + 1))
                        ->setName('Too long code')
                        ->setStoreMode(Store::MODE_TEST)
                        ->setIsDefault(false)
                        ->setStatus(true)
                        ->save(),
                    '不能超过',
                );
                $this->expectSavepointFailure(
                    'store_name_length',
                    fn(): bool|int => $this->newStore()
                        ->setWebsiteId($websiteId)
                        ->setCode('too_long_name')
                        ->setName(str_repeat('店', Store::NAME_MAX_LENGTH + 1))
                        ->setStoreMode(Store::MODE_TEST)
                        ->setIsDefault(false)
                        ->setStatus(true)
                        ->save(),
                    '不能超过',
                );
                $this->expectSavepointFailure(
                    'channel_code_length',
                    fn(): bool|int => $this->newChannel()
                        ->setWebsiteId($websiteId)
                        ->setStoreId($store->getStoreId())
                        ->setCode(str_repeat('a', SalesChannel::CODE_MAX_LENGTH + 1))
                        ->setName('Too long code')
                        ->setIsDefault(false)
                        ->setStatus(true)
                        ->save(),
                    '不能超过',
                );
                $this->expectSavepointFailure(
                    'channel_name_length',
                    fn(): bool|int => $this->newChannel()
                        ->setWebsiteId($websiteId)
                        ->setStoreId($store->getStoreId())
                        ->setCode('too_long_name')
                        ->setName(str_repeat('渠', SalesChannel::NAME_MAX_LENGTH + 1))
                        ->setIsDefault(false)
                        ->setStatus(true)
                        ->save(),
                    '不能超过',
                );

                $this->expectSavepointFailure(
                    'immutable_store_mode',
                    function () use ($store): bool|int {
                        $update = $this->loadStore($store->getStoreId());
                        return $update->setStoreMode(Store::MODE_DEV)->save();
                    },
                    '不可变更',
                );
                $this->expectSavepointFailure(
                    'cross_website_channel',
                    fn(): bool|int => $this->newChannel()
                        ->setWebsiteId(Website::ID_DEFAULT)
                        ->setStoreId($store->getStoreId())
                        ->setCode('cross')
                        ->setName('Cross')
                        ->setIsDefault(false)
                        ->setStatus(true)
                        ->save(),
                    '必须与所属店铺一致',
                );
                $this->expectSavepointFailure(
                    'default_store_delete',
                    fn(): Store => $defaultStore->delete(),
                    '默认店铺不允许删除',
                );
                $this->expectSavepointFailure(
                    'default_channel_delete',
                    fn(): SalesChannel => $defaultChannel->delete(),
                    '默认渠道不允许删除',
                );
                $this->expectSavepointFailure(
                    'website_with_children_delete',
                    fn(): Website => $website->delete(),
                    '仍有 Store 或 SalesChannel 引用',
                );

                $store->delete();
                self::assertTrue($store->isTombstoned());
                self::assertFalse($store->isEnabled());
                self::assertNotNull($store->getTombstonedAt());
                $this->expectSavepointFailure(
                    'tombstone_revive',
                    fn(): bool|int => $this->loadStore($store->getStoreId())->setStatus(true)->save(),
                    '墓碑',
                );

                throw new StoreChannelSeedRollbackProbe();
            });
            self::fail('The fixture transaction must roll back.');
        } catch (StoreChannelSeedRollbackProbe) {
        }

        self::assertSame(0, $this->countRows(Website::class, Website::schema_fields_CODE, $token));
    }

    public function testWebsiteSeedFailureRollsBackWebsiteAndChildren(): void
    {
        $token = 'p1a001_fail_' . bin2hex(random_bytes(5));
        $original = ObjectManager::getInstance(StoreChannelSeedService::class);
        $failing = new FailingStoreChannelSeedService();
        ObjectManager::setInstance(StoreChannelSeedService::class, $failing);
        try {
            try {
                $this->newWebsite($token)->save();
                self::fail('The injected seed failure must escape Website::save().');
            } catch (StoreChannelSeedInjectedFailure) {
            }
        } finally {
            ObjectManager::setInstance(StoreChannelSeedService::class, $original);
        }

        self::assertSame(0, $this->countRows(Website::class, Website::schema_fields_CODE, $token));
    }

    public function testCaughtWebsiteSeedFailureStillForcesOwnerRollback(): void
    {
        $token = 'p1a001_caught_fail_' . bin2hex(random_bytes(5));
        $original = ObjectManager::getInstance(StoreChannelSeedService::class);
        ObjectManager::setInstance(StoreChannelSeedService::class, new FailingStoreChannelSeedService());
        try {
            try {
                $this->transactions->runWrite($this->connection, function () use ($token): void {
                    try {
                        $this->newWebsite($token)->save();
                        self::fail('The injected seed failure must escape Website::save().');
                    } catch (StoreChannelSeedInjectedFailure) {
                        // Simulate an owner callback that mistakenly swallows the child failure.
                    }
                });
                self::fail('A swallowed child failure must make the owner transaction rollback-only.');
            } catch (RollbackOnlyException $exception) {
                self::assertInstanceOf(StoreChannelSeedInjectedFailure::class, $exception->getPrevious());
            }
        } finally {
            ObjectManager::setInstance(StoreChannelSeedService::class, $original);
        }

        self::assertSame(0, $this->countRows(Website::class, Website::schema_fields_CODE, $token));
    }

    public function testCaughtStoreLifecycleAffinityFailureForcesOwnerRollback(): void
    {
        $foreignConnection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'path' => ':memory:',
            'prefix' => 'p1a001_lifecycle_affinity_',
            'persistent' => false,
        ]));
        $store = ObjectManager::getInstance(Store::class, [], false)
            ->setConnection($foreignConnection)
            ->setData(Store::schema_fields_ID, 1);

        try {
            $this->transactions->runWrite($foreignConnection, function () use ($store): void {
                try {
                    $store->delete();
                    self::fail('A foreign generation connection must be rejected.');
                } catch (\LogicException $exception) {
                    self::assertStringContainsString('同一逻辑数据库连接', $exception->getMessage());
                }
            });
            self::fail('A swallowed affinity failure must make the owner transaction rollback-only.');
        } catch (RollbackOnlyException $exception) {
            self::assertInstanceOf(\LogicException::class, $exception->getPrevious());
        }
    }

    public function testUpgradeSetupBackfillsAnExistingWebsiteIdempotently(): void
    {
        $token = 'p1a001_upgrade_' . bin2hex(random_bytes(5));
        $websiteId = 0;
        try {
            $this->transactions->runWrite($this->connection, function () use ($token, &$websiteId): void {
                $this->insertWebsiteWithoutLifecycleHook($token);
                $website = ObjectManager::getInstance(Website::class, [], false)
                    ->setConnection($this->connection)
                    ->where(Website::schema_fields_CODE, $token)
                    ->find()->fetch();
                $websiteId = $website->getWebsiteId();
                self::assertGreaterThan(Website::ID_DEFAULT, $websiteId);
                self::assertSame(0, $this->countRows(Store::class, Store::schema_fields_WEBSITE_ID, $websiteId));
                self::assertSame(0, $this->countRows(SalesChannel::class, SalesChannel::schema_fields_WEBSITE_ID, $websiteId));

                $setup = ObjectManager::getInstance(SetupData::class);
                $context = new SetupContext('Weline_Websites', '1.6.8');
                $setup->setModuleContext($context);
                $upgrade = ObjectManager::getInstance(WebsitesUpgrade::class);

                $upgrade->setup($setup, $context);
                [$firstStore, $firstChannel] = $this->assertDefaultPair($websiteId);
                $upgrade->setup($setup, $context);
                [$secondStore, $secondChannel] = $this->assertDefaultPair($websiteId);

                self::assertSame($firstStore->getStoreId(), $secondStore->getStoreId());
                self::assertSame($firstChannel->getChannelId(), $secondChannel->getChannelId());
                throw new StoreChannelSeedRollbackProbe();
            });
            self::fail('The fixture transaction must roll back.');
        } catch (StoreChannelSeedRollbackProbe) {
        }

        self::assertSame(0, $this->countRows(Website::class, Website::schema_fields_CODE, $token));
        if ($websiteId > Website::ID_DEFAULT) {
            self::assertSame(0, $this->countRows(Store::class, Store::schema_fields_WEBSITE_ID, $websiteId));
            self::assertSame(0, $this->countRows(SalesChannel::class, SalesChannel::schema_fields_WEBSITE_ID, $websiteId));
        }
    }

    public function testMaxLengthWebsiteNameProducesBoundedDefaultStoreName(): void
    {
        $token = 'p1a001_name_' . bin2hex(random_bytes(5));
        $websiteName = substr(hash('sha256', $token), 0, 16) . str_repeat('站', 112);
        self::assertSame(Store::NAME_MAX_LENGTH, mb_strlen($websiteName, 'UTF-8'));
        $websiteId = 0;
        try {
            $this->transactions->runWrite($this->connection, function () use ($token, $websiteName, &$websiteId): void {
                $website = $this->newWebsite($token)->setName($websiteName);
                $website->save();
                $websiteId = $website->getWebsiteId();
                [$store] = $this->assertDefaultPair($websiteId);
                self::assertLessThanOrEqual(
                    Store::NAME_MAX_LENGTH,
                    mb_strlen($store->getName(), 'UTF-8'),
                );
                self::assertStringEndsWith((string)__('默认店铺'), $store->getName());
                throw new StoreChannelSeedRollbackProbe();
            });
            self::fail('The fixture transaction must roll back.');
        } catch (StoreChannelSeedRollbackProbe) {
        }

        self::assertSame(0, $this->countRows(Website::class, Website::schema_fields_CODE, $token));
        if ($websiteId > Website::ID_DEFAULT) {
            self::assertSame(0, $this->countRows(Store::class, Store::schema_fields_WEBSITE_ID, $websiteId));
            self::assertSame(0, $this->countRows(SalesChannel::class, SalesChannel::schema_fields_WEBSITE_ID, $websiteId));
        }
    }

    public function testSeederRepairsActiveStoresAndIgnoresTombstones(): void
    {
        $token = 'p1a001_repair_' . bin2hex(random_bytes(5));
        $websiteId = 0;
        try {
            $this->transactions->runWrite($this->connection, function () use ($token, &$websiteId): void {
                $website = $this->newWebsite($token);
                $website->save();
                $websiteId = $website->getWebsiteId();

                $activeStore = $this->newStore()
                    ->setWebsiteId($websiteId)
                    ->setCode('repair_active')
                    ->setName('Repair active')
                    ->setStoreMode(Store::MODE_TEST)
                    ->setIsDefault(false)
                    ->setStatus(true);
                $activeStore->save();
                $tombstoneStoreId = $this->insertTombstoneStoreWithoutChannel($websiteId, $token);

                self::assertSame(0, $this->countRows(
                    SalesChannel::class,
                    SalesChannel::schema_fields_STORE_ID,
                    $activeStore->getStoreId(),
                ));
                self::assertSame(0, $this->countRows(
                    SalesChannel::class,
                    SalesChannel::schema_fields_STORE_ID,
                    $tombstoneStoreId,
                ));

                self::assertSame(
                    ['stores_created' => 0, 'channels_created' => 1],
                    $this->seeder->ensureDefaultsForWebsite($websiteId, $website->getName(), $this->connection),
                );
                self::assertSame(1, $this->countRows(
                    SalesChannel::class,
                    SalesChannel::schema_fields_STORE_ID,
                    $activeStore->getStoreId(),
                ));
                self::assertSame(0, $this->countRows(
                    SalesChannel::class,
                    SalesChannel::schema_fields_STORE_ID,
                    $tombstoneStoreId,
                ));

                $preflight = ObjectManager::getInstance(ScopeMigrationService::class)->preflight();
                self::assertSame(0, $preflight['stores_missing_channel']);
                self::assertSame(
                    ['stores_created' => 0, 'channels_created' => 0],
                    $this->seeder->ensureDefaultsForWebsite($websiteId, $website->getName(), $this->connection),
                );
                throw new StoreChannelSeedRollbackProbe();
            });
            self::fail('The fixture transaction must roll back.');
        } catch (StoreChannelSeedRollbackProbe) {
        }

        self::assertSame(0, $this->countRows(Website::class, Website::schema_fields_CODE, $token));
        if ($websiteId > Website::ID_DEFAULT) {
            self::assertSame(0, $this->countRows(Store::class, Store::schema_fields_WEBSITE_ID, $websiteId));
            self::assertSame(0, $this->countRows(SalesChannel::class, SalesChannel::schema_fields_WEBSITE_ID, $websiteId));
        }
    }

    /** @return array{Store, SalesChannel} */
    private function assertDefaultPair(int $websiteId): array
    {
        $stores = $this->newStore()
            ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Store::schema_fields_CODE, Store::CODE_DEFAULT)
            ->select()->fetchArray();
        self::assertCount(1, $stores);
        $store = $this->loadStore((int)$stores[0][Store::schema_fields_ID]);
        self::assertTrue($store->isDefault());

        $channels = $this->newChannel()
            ->where(SalesChannel::schema_fields_STORE_ID, $store->getStoreId())
            ->where(SalesChannel::schema_fields_CODE, SalesChannel::CODE_DEFAULT)
            ->select()->fetchArray();
        self::assertCount(1, $channels);
        $channel = $this->newChannel()
            ->where(SalesChannel::schema_fields_ID, (int)$channels[0][SalesChannel::schema_fields_ID])
            ->find()->fetch();
        self::assertTrue($channel->isDefault());
        self::assertSame($websiteId, $channel->getWebsiteId());
        self::assertSame($store->getStoreId(), $channel->getStoreId());
        return [$store, $channel];
    }

    private function expectSavepointFailure(
        string $purpose,
        callable $callback,
        string $messageFragment,
    ): void {
        try {
            $this->transactions->withSavepoint($this->connection, $purpose, $callback);
            self::fail('Expected guarded operation to fail: ' . $purpose);
        } catch (\Throwable $exception) {
            self::assertStringContainsString($messageFragment, $exception->getMessage());
        }
    }

    private function newWebsite(string $token): Website
    {
        return ObjectManager::getInstance(Website::class, [], false)
            ->setConnection($this->connection)
            ->setName($token)
            ->setCode($token)
            ->setUrl('https://' . $token . '.invalid')
            ->setDefaultCurrency('CNY')
            ->setDefaultLanguage('zh_Hans_CN')
            ->setData(Website::schema_fields_DEFAULT_TIMEZONE, 'UTC')
            ->setData(Website::schema_fields_SCOPE, '');
    }

    private function insertWebsiteWithoutLifecycleHook(string $token): void
    {
        $row = [
            Website::schema_fields_NAME => $token,
            Website::schema_fields_CODE => $token,
            Website::schema_fields_URL => 'https://' . $token . '.invalid',
            Website::schema_fields_DEFAULT_CURRENCY => 'CNY',
            Website::schema_fields_DEFAULT_LANGUAGE => 'zh_Hans_CN',
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
            Website::schema_fields_SCOPE => '',
        ];
        $connector = $this->connection->getConnector();
        $columns = array_keys($row);
        $quotedColumns = array_map(
            static fn(string $column): string => $connector->quoteIdentifier($column),
            $columns,
        );
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $statement = $connector->getWrappedConnection()->prepare(
            'INSERT INTO ' . $this->newWebsite($token)->getTable()
            . ' (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ')',
        );
        $statement->execute($row);
    }

    private function insertTombstoneStoreWithoutChannel(int $websiteId, string $token): int
    {
        $row = [
            Store::schema_fields_WEBSITE_ID => $websiteId,
            Store::schema_fields_CODE => 'repair_tombstone',
            Store::schema_fields_NAME => 'Repair tombstone',
            Store::schema_fields_STORE_MODE => Store::MODE_TEST,
            Store::schema_fields_IS_DEFAULT => 0,
            Store::schema_fields_STATUS => 0,
            Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_TOMBSTONE,
            Store::schema_fields_TOMBSTONED_AT => gmdate('Y-m-d H:i:s'),
        ];
        $connector = $this->connection->getConnector();
        $columns = array_keys($row);
        $quotedColumns = array_map(
            static fn(string $column): string => $connector->quoteIdentifier($column),
            $columns,
        );
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $statement = $connector->getWrappedConnection()->prepare(
            'INSERT INTO ' . $this->newStore()->getTable()
            . ' (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ')',
        );
        $statement->execute($row);

        $store = $this->newStore()
            ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Store::schema_fields_CODE, 'repair_tombstone')
            ->find()->fetch();
        return $store->getStoreId();
    }

    private function newStore(): Store
    {
        return ObjectManager::getInstance(Store::class, [], false)->setConnection($this->connection);
    }

    private function loadStore(int $storeId): Store
    {
        return $this->newStore()->where(Store::schema_fields_ID, $storeId)->find()->fetch();
    }

    private function newChannel(): SalesChannel
    {
        return ObjectManager::getInstance(SalesChannel::class, [], false)->setConnection($this->connection);
    }

    /** @param class-string<Website|Store|SalesChannel> $modelClass */
    private function countRows(string $modelClass, string $field, int|string $value): int
    {
        $model = ObjectManager::getInstance($modelClass, [], false);
        $model->setConnection($this->connection)->where($field, $value);
        return count($model->select()->fetchArray());
    }
}

final class StoreChannelSeedRollbackProbe extends \RuntimeException
{
}

final class StoreChannelSeedInjectedFailure extends \RuntimeException
{
}

final class FailingStoreChannelSeedService extends StoreChannelSeedService
{
    public function __construct()
    {
    }

    public function ensureDefaultsForWebsite(
        int $websiteId,
        string $websiteName = '',
        ?ConnectionFactory $connection = null,
    ): array {
        throw new StoreChannelSeedInjectedFailure('injected_scope_seed_failure');
    }
}
