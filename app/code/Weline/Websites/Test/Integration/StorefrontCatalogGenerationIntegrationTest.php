<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;

final class StorefrontCatalogGenerationIntegrationTest extends TestCase
{
    public function testGenerationRepositoryRejectsDifferentLogicalConnection(): void
    {
        $repository = ObjectManager::getInstance(NamespaceGenerationRepository::class);
        $defaultConnection = ObjectManager::getInstance(ConnectionFactory::class);
        $repository->assertConnectionAffinity($defaultConnection);
        self::assertTrue(true);

        $foreignConnection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'path' => ':memory:',
            'prefix' => 'p1a001_affinity_',
            'persistent' => false,
        ]));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('同一逻辑数据库连接');
        $repository->assertConnectionAffinity($foreignConnection);
    }

    public function testStoreAndChannelSaveAdvanceCatalogInsideOwningTransactionAndRollbackCleanly(): void
    {
        $store = ObjectManager::getInstance(Store::class, [], false)
            ->where(Store::schema_fields_WEBSITE_ID, 0)
            ->where(Store::schema_fields_CODE, Store::CODE_DEFAULT)
            ->find()->fetch();
        self::assertTrue($store->hasData(Store::schema_fields_ID));
        $channel = ObjectManager::getInstance(SalesChannel::class, [], false)
            ->where(SalesChannel::schema_fields_WEBSITE_ID, 0)
            ->where(SalesChannel::schema_fields_STORE_ID, (int)$store->getData(Store::schema_fields_ID))
            ->where(SalesChannel::schema_fields_CODE, SalesChannel::CODE_DEFAULT)
            ->find()->fetch();
        self::assertTrue($channel->hasData(SalesChannel::schema_fields_ID));

        $repository = ObjectManager::getInstance(NamespaceGenerationRepository::class);
        $path = ObjectManager::getInstance(NamespacePath::class)->website('default', ['catalog']);
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);

        $this->assertModelSaveAdvancesAndRollsBack(
            $store,
            $store->getName(),
            $repository,
            $path,
            $transactions,
        );
        $this->assertModelSaveAdvancesAndRollsBack(
            $channel,
            $channel->getName(),
            $repository,
            $path,
            $transactions,
        );
    }

    public function testNormalChannelSaveRollsBackBusinessAndGenerationWhenAfterHookFails(): void
    {
        $channel = $this->defaultChannel();
        $repository = ObjectManager::getInstance(NamespaceGenerationRepository::class);
        $path = ObjectManager::getInstance(NamespacePath::class)->website('default', ['catalog']);
        $before = $this->generation($repository, $path);
        $originalName = $channel->getName();
        $probe = ObjectManager::getInstance(CatalogAfterSaveFailureSalesChannel::class, [], false);
        $probe->setConnection($channel->getConnection());
        $probe->setModelData($channel->getModelData());

        try {
            $probe->setName($originalName . ' [owner-transaction-probe]')->save();
            self::fail('The failing after hook must escape save().');
        } catch (StorefrontCatalogAfterHookProbe) {
        }

        $repository->clearSnapshot();
        self::assertSame($before, $this->generation($repository, $path));
        $reloaded = ObjectManager::getInstance(SalesChannel::class, [], false)
            ->where(SalesChannel::schema_fields_ID, $channel->getChannelId())
            ->find()->fetch();
        self::assertSame($originalName, $reloaded->getName());
    }

    public function testNormalChannelDeleteFailureRollsBackOwnerTransactionWithoutPersistentGeneration(): void
    {
        $store = $this->defaultStore();
        $code = 'probe_' . bin2hex(random_bytes(4));
        $repository = ObjectManager::getInstance(NamespaceGenerationRepository::class);
        $path = ObjectManager::getInstance(NamespacePath::class)->website('default', ['catalog']);
        $repository->clearSnapshot();
        $baseline = $this->generation($repository, $path);
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $connection = $store->getConnection();
        $channelId = 0;

        try {
            $transactions->runWrite($connection, function () use (
                $store,
                $code,
                &$channelId,
            ): void {
                $channel = ObjectManager::getInstance(SalesChannel::class, [], false)
                    ->setWebsiteId(0)
                    ->setStoreId($store->getStoreId())
                    ->setCode($code)
                    ->setName('Catalog delete probe')
                    ->setIsDefault(false)
                    ->setStatus(true);
                $channel->save();
                $channelId = $channel->getChannelId();
                self::assertGreaterThan(0, $channelId);

                $probe = ObjectManager::getInstance(CatalogAfterDeleteFailureSalesChannel::class, [], false)
                    ->where(SalesChannel::schema_fields_ID, $channelId)
                    ->find()->fetch();
                $probe->delete();
            });
            self::fail('The failing after hook must escape delete().');
        } catch (StorefrontCatalogAfterHookProbe) {
        }

        self::assertGreaterThan(0, $channelId);
        $repository->clearSnapshot();
        self::assertSame($baseline, $this->generation($repository, $path));
        $rolledBack = ObjectManager::getInstance(SalesChannel::class, [], false)
            ->where(SalesChannel::schema_fields_ID, $channelId)
            ->find()->fetch();
        self::assertFalse($rolledBack->hasData(SalesChannel::schema_fields_ID));
    }

    private function assertModelSaveAdvancesAndRollsBack(
        Store|SalesChannel $model,
        string $originalName,
        NamespaceGenerationRepository $repository,
        string $path,
        TransactionCoordinatorInterface $transactions,
    ): void {
        $before = $this->generation($repository, $path);
        try {
            $transactions->run($model->getConnection(), function () use (
                $model,
                $originalName,
                $repository,
                $path,
                $before,
            ): void {
                $model->setName($originalName . ' [catalog-generation-probe]')->save();
                $repository->clearSnapshot();
                self::assertGreaterThan($before, $this->generation($repository, $path));
                throw new StorefrontCatalogGenerationRollbackProbe();
            });
            self::fail('Rollback probe must escape the owning transaction.');
        } catch (StorefrontCatalogGenerationRollbackProbe) {
        }

        $repository->clearSnapshot();
        self::assertSame($before, $this->generation($repository, $path));
        $reloaded = clone $model;
        $reloaded->clearData()->clearQuery()
            ->where($model instanceof Store ? Store::schema_fields_ID : SalesChannel::schema_fields_ID, $model->getId())
            ->find()->fetch();
        self::assertSame($originalName, $reloaded->getName());
    }

    private function generation(NamespaceGenerationRepository $repository, string $path): int
    {
        $vector = $repository->resolveVector([$path]);
        return (int)($vector['generations'][$path] ?? 0);
    }

    private function defaultStore(): Store
    {
        $store = ObjectManager::getInstance(Store::class, [], false)
            ->where(Store::schema_fields_WEBSITE_ID, 0)
            ->where(Store::schema_fields_CODE, Store::CODE_DEFAULT)
            ->find()->fetch();
        self::assertTrue($store->hasData(Store::schema_fields_ID));
        return $store;
    }

    private function defaultChannel(): SalesChannel
    {
        $store = $this->defaultStore();
        $channel = ObjectManager::getInstance(SalesChannel::class, [], false)
            ->where(SalesChannel::schema_fields_WEBSITE_ID, 0)
            ->where(SalesChannel::schema_fields_STORE_ID, $store->getStoreId())
            ->where(SalesChannel::schema_fields_CODE, SalesChannel::CODE_DEFAULT)
            ->find()->fetch();
        self::assertTrue($channel->hasData(SalesChannel::schema_fields_ID));
        return $channel;
    }
}

final class StorefrontCatalogGenerationRollbackProbe extends \RuntimeException
{
}

final class StorefrontCatalogAfterHookProbe extends \RuntimeException
{
}

final class CatalogAfterSaveFailureSalesChannel extends SalesChannel
{
    public function save_after(): void
    {
        parent::save_after();
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        if (!$transactions->isActive($this->getConnection())) {
            throw new \LogicException('SalesChannel save_after must run inside its owner transaction.');
        }
        throw new StorefrontCatalogAfterHookProbe();
    }
}

final class CatalogAfterDeleteFailureSalesChannel extends SalesChannel
{
    public function delete_after(): void
    {
        parent::delete_after();
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        if (!$transactions->isActive($this->getConnection())) {
            throw new \LogicException('SalesChannel delete_after must run inside its owner transaction.');
        }
        throw new StorefrontCatalogAfterHookProbe();
    }
}
