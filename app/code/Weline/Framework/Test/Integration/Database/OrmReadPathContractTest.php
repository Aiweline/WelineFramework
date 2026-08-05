<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Integration\Database;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Model;

final class OrmReadPathContractModel extends Model
{
    public const schema_table = 'codex_orm_read_path_contract';
    public const schema_primary_key = 'id';

    public int $beforeHookCalls = 0;
    public int $afterHookCalls = 0;

    public function set_data_before(mixed $key, mixed $value = null): void
    {
        unset($key, $value);
        $this->beforeHookCalls++;
    }

    public function set_data_after(mixed $key, mixed $value = null): void
    {
        unset($key, $value);
        $this->afterHookCalls++;
    }
}

final class OrmReadPathCloneAwareModel extends Model
{
    public const schema_table = 'codex_orm_read_path_clone_contract';
    public const schema_primary_key = 'id';

    /** @var list<int> */
    public static array $observedItemCounts = [];
    public static int $cloneCalls = 0;
    public static bool $reenterOnSecondClone = false;
    public static bool $throwOnSecondClone = false;
    public static ?self $sourceCollection = null;

    public function __clone(): void
    {
        self::$cloneCalls++;
        self::$observedItemCounts[] = count($this->getItems());
        if (self::$cloneCalls === 2 && self::$reenterOnSecondClone && self::$sourceCollection !== null) {
            self::$sourceCollection->addItem(new self(['id' => 99]));
        }
        if (self::$cloneCalls === 2 && self::$throwOnSecondClone) {
            throw new \RuntimeException('clone failed');
        }
    }

    public static function resetCloneContract(): void
    {
        self::$observedItemCounts = [];
        self::$cloneCalls = 0;
        self::$reenterOnSecondClone = false;
        self::$throwOnSecondClone = false;
        self::$sourceCollection = null;
    }
}

final class OrmReadPathContractTest extends TestCase
{
    protected function tearDown(): void
    {
        OrmReadPathCloneAwareModel::resetCloneContract();
    }

    public function testFetchDataKeepsSourcesWhileItemsAreIsolatedHydratedModels(): void
    {
        $source = new OrmReadPathContractModel(['id' => 1, 'name' => 'one']);
        $collection = new OrmReadPathContractModel();

        $collection->setFetchData([$source]);

        self::assertSame([$source], $collection->getFetchData());
        self::assertCount(1, $collection->getItems());
        $item = $collection->getItems()[0];
        self::assertNotSame($source, $item);
        self::assertSame(['id' => 1, 'name' => 'one'], $item->getData());
        self::assertSame(['id' => 1, 'name' => 'one'], $item->getChangedData());
        self::assertSame([], $source->getChangedData());
        self::assertSame(2, $item->beforeHookCalls);
        self::assertSame(2, $item->afterHookCalls);
    }

    public function testSetItemsAcceptsModelAndArrayInputsWithSameHydrationSemantics(): void
    {
        $source = new OrmReadPathContractModel(['id' => 1, 'name' => 'one']);
        $collection = new OrmReadPathContractModel();

        $collection->setItems([
            $source,
            ['id' => 2, 'name' => 'two'],
        ]);

        self::assertSame(
            [
                ['id' => 1, 'name' => 'one'],
                ['id' => 2, 'name' => 'two'],
            ],
            array_map(
                static fn (OrmReadPathContractModel $item): array => $item->getData(),
                $collection->getItems()
            )
        );
        foreach ($collection->getItems() as $item) {
            self::assertSame($item->getData(), $item->getChangedData());
            self::assertSame(2, $item->beforeHookCalls);
            self::assertSame(2, $item->afterHookCalls);
        }
    }

    public function testHydratedRowsDoNotRetainPreviouslyHydratedSiblings(): void
    {
        $collection = new OrmReadPathContractModel();
        $collection->setFetchData([
            new OrmReadPathContractModel(['id' => 1]),
            new OrmReadPathContractModel(['id' => 2]),
            new OrmReadPathContractModel(['id' => 3]),
        ]);

        self::assertCount(3, $collection->getItems());
        foreach ($collection->getItems() as $item) {
            self::assertSame([], $item->getItems());
        }
    }

    public function testCloneHookObservesLegacySiblingStateWithoutRetainingIt(): void
    {
        $collection = new OrmReadPathCloneAwareModel();

        $collection->setItems([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]);

        self::assertSame([0, 1, 2], OrmReadPathCloneAwareModel::$observedItemCounts);
        foreach ($collection->getItems() as $item) {
            self::assertSame([], $item->getItems());
        }
    }

    public function testCloneHookReentrantSourceMutationIsNotDiscarded(): void
    {
        $collection = new OrmReadPathCloneAwareModel();
        OrmReadPathCloneAwareModel::$sourceCollection = $collection;
        OrmReadPathCloneAwareModel::$reenterOnSecondClone = true;

        $collection->setItems([
            ['id' => 1],
            ['id' => 2],
        ]);

        self::assertSame([0, 1], OrmReadPathCloneAwareModel::$observedItemCounts);
        self::assertSame(
            [1, 99, 2],
            array_map(
                static fn (OrmReadPathCloneAwareModel $item): int => (int) $item->getData('id'),
                $collection->getItems()
            )
        );
    }

    public function testThrowingCloneDoesNotCorruptSourceCollection(): void
    {
        $collection = new OrmReadPathCloneAwareModel();
        OrmReadPathCloneAwareModel::$throwOnSecondClone = true;

        try {
            $collection->setItems([
                ['id' => 1],
                ['id' => 2],
            ]);
            self::fail('Expected clone failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('clone failed', $exception->getMessage());
        }

        self::assertSame([0, 1], OrmReadPathCloneAwareModel::$observedItemCounts);
        self::assertCount(1, $collection->getItems());
        self::assertSame(1, (int) $collection->getItems()[0]->getData('id'));
    }
}
