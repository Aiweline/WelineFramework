<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Service;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use stdClass;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetBatchUrlResolverInterface;
use Weline\FileManager\Api\FileAccessPolicyInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\FileManager\Service\FileAccessPolicy;
use Weline\FileManager\Service\FileAssetManager;
use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Query\QueryDelegator;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\StorageDiskInterface;
use Weline\Storage\Api\StorageManagerInterface;

final class FileAssetBatchUrlResolverTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';
    private const C = '33333333-3333-4333-8333-333333333333';
    private const MISSING = '44444444-4444-4444-8444-444444444444';
    private const NO_LOCALE = '55555555-5555-4555-8555-555555555555';

    public function testBatchResolvesIndependentInputsWithBoundedDatabaseReadsAcrossChunks(): void
    {
        $fixtures = [
            [self::A, ['en_US', 'zh_Hans_CN']],
            [self::B, ['fr_FR']],
            [self::C, ['zh_Hans_CN']],
            [self::NO_LOCALE, []],
        ];
        $largeIds = [];
        for ($i = 1; $i <= 201; ++$i) {
            $largeIds[$i] = sprintf('60000000-0000-4000-8000-%012d', $i);
            $fixtures[] = [$largeIds[$i], ['en_US']];
        }
        [$manager, $ledger] = $this->managerFixture($fixtures);
        $singleAsset = $manager->get(self::A);
        $singleLocale = $manager->locale(self::A, 'en_US');
        $singleHooks = $ledger->fetchHooks;
        self::assertSame(['asset', 'asset', 'locale', 'locale'], array_column($singleHooks, 'kind'));
        self::assertSame(['before', 'after', 'before', 'after'], array_column($singleHooks, 'event'));
        self::assertSame([[], $singleAsset->getData(), [], $singleLocale->getData()], array_column($singleHooks, 'model_data'));
        self::assertSame($singleAsset->getData(), $singleHooks[1]['fetch_data']);
        self::assertSame($singleLocale->getData(), $singleHooks[3]['fetch_data']);
        $ledger->sql = $ledger->bindings = $ledger->fetchHooks = [];
        $scope = ScopeIdentity::channel(7, 'site', 'shop', 'web', ScopeIdentity::MODE_NORMAL);
        $contexts = array_map(static fn(string $locale): FileAccessContext => new FileAccessContext(
            $scope, $locale, purpose: FileAccessContext::PURPOSE_PUBLIC_PUBLISH,
        ), ['fr_FR', 'en_US', 'zh_Hans_CN']);
        $requests = [
            'english' => ['asset_id' => self::A, 'contexts' => $contexts],
            9 => ['asset_id' => self::B, 'contexts' => $contexts],
            'website-default' => ['asset_id' => self::C, 'contexts' => $contexts],
            'same-asset-new-input' => ['asset_id' => self::A, 'contexts' => $contexts],
            'missing' => ['asset_id' => self::MISSING, 'contexts' => $contexts],
            'no-locale' => ['asset_id' => self::NO_LOCALE, 'contexts' => $contexts],
        ];
        foreach ($largeIds as $i => $assetId) { $requests['bulk-' . $i] = ['asset_id' => $assetId, 'contexts' => $contexts]; }
        $result = $this->resolveBatch($manager, $requests);

        self::assertSame(array_keys($requests), array_keys($result));
        self::assertSame('/media/' . self::A . '.jpg?call=1', $result['english']?->url);
        self::assertSame('/media/' . self::B . '.jpg?call=2', $result[9]?->url);
        self::assertSame('/media/' . self::C . '.jpg?call=3', $result['website-default']?->url);
        self::assertSame('/media/' . self::A . '.jpg?call=4', $result['same-asset-new-input']?->url);
        self::assertNull($result['missing']);
        self::assertNull($result['no-locale']);
        self::assertSame(['en_US', 'fr_FR', 'zh_Hans_CN', 'en_US'], array_slice(array_map(static fn(FileAccessContext $context): string => $context->localeCode, $ledger->accessContexts), 0, 4));
        foreach ($largeIds as $i => $assetId) { self::assertSame('/media/' . $assetId . '.jpg?call=' . ($i + 4), $result['bulk-' . $i]?->url); }
        foreach ($ledger->accessContexts as $context) { self::assertSame($scope, $context->scope); }
        self::assertSame(205, $ledger->urlCalls, 'Each input resolves its own URL, even for duplicate asset IDs.');
        self::assertCount(4, $ledger->sql, 'Read assets and locale rows in two bounded chunks, without per-input reads.');
        foreach (['asset', 'locale'] as $kind) {
            $expectedHooks = array_values(array_filter($singleHooks, static fn(array $hook): bool => $hook['kind'] === $kind));
            $actualHooks = array_values(array_filter($ledger->fetchHooks, static fn(array $hook): bool =>
                $hook['kind'] === $kind && $hook['query_asset_id'] === self::A
                && ($kind === 'asset' || $hook['query_locale'] === 'en_US'),
            ));
            self::assertCount(4, $actualHooks);
            self::assertSame($expectedHooks, array_slice($actualHooks, 0, 2));
            self::assertSame($expectedHooks, array_slice($actualHooks, 2, 2));
        }
        self::assertNotSame($ledger->accessAssets[0], $ledger->accessAssets[3]);
        $ledger->accessAssets[0]->setData('disk_code', 'mutated-first-result');
        self::assertSame('fixture', $ledger->accessAssets[3]->getDiskCode());

        // The same shared hydration entry point also retains generic fetch results.
        $model = clone $ledger->accessAssets[3];
        $model->clearData();
        $model->setInsertFlag(false);
        $model->setDeleteFlag(true);
        $model->getQuery()->where('asset_id', self::A)->find();
        $row = new \Weline\Framework\DataObject\DataObject(['asset_id' => self::A, 'disk_code' => 'hydrated']);
        $model->setQueryData($row);
        self::assertSame($model, $this->hydrateFetchResult($model, $row));
        self::assertSame(['asset_id' => self::A, 'disk_code' => 'hydrated'], $model->getData());
        self::assertSame($model->getData(), $model->getFetchData());
        self::assertFalse($model->getIsDelete());
        self::assertSame('', $model->getQuery()->getPrepareSql(false));

        $model->clearData();
        $model->setInsertFlag(true);
        $model->setQueryData(321);
        self::assertSame($model, $this->hydrateFetchResult($model, 321));
        self::assertSame(321, $model->getId());
        self::assertTrue($model->getIsInsert(), 'Hydration retains the original insert-flag semantics.');

        $model->setInsertFlag(false);
        $model->setFindFieldsValue('asset_id,disk_code');
        $fields = ['asset_id' => self::B, 'disk_code' => 'projected', 'extra' => 'raw-result'];
        $model->setQueryData($fields);
        self::assertSame($fields, $this->hydrateFetchResult($model, $fields));
        self::assertSame(['asset_id' => self::B, 'disk_code' => 'projected'], $model->getData());
        self::assertSame('', $model->getFindFieldsValue());
        self::assertFalse($model->getIsInsert());
        self::assertFalse($model->getIsDelete());
        self::assertSame('', $model->getQuery()->getPrepareSql(false));
    }

    protected function tearDown(): void
    {
        RequestContext::resetWelineVars();
        parent::tearDown();
    }

    public function testBatchPreservesPerInputPrivatePolicyTtlAndAdapterValidation(): void
    {
        $scope = ScopeIdentity::channel(7, 'site', 'shop', 'web', ScopeIdentity::MODE_NORMAL);
        $otherStore = ScopeIdentity::channel(7, 'site', 'other', 'web', ScopeIdentity::MODE_NORMAL);
        $otherChannel = ScopeIdentity::channel(7, 'site', 'shop', 'feed', ScopeIdentity::MODE_NORMAL);
        $metadata = json_encode(['access_policy' => [
            'allowed_actor_ids' => [21], 'allowed_scope_keys' => [$scope->canonicalKey()],
            'allowed_roles' => ['customer'], 'policy_revision' => 3,
        ]], JSON_THROW_ON_ERROR);
        [$manager, $ledger] = $this->managerFixture([
            [self::A, ['en_US'], ['visibility' => 'private', 'metadata' => $metadata]],
        ], static function (string $key, ?StorageUrlOptions $options, stdClass $ledger): ResolvedStorageUrl {
            $ttl = $options?->ttlSeconds ?? 0;
            return match ($ledger->adapterMode ?? '') {
                'wrong-kind' => new ResolvedStorageUrl('/private/wrong.jpg', StorageUrlOptions::KIND_PUBLIC, true),
                'expired' => new ResolvedStorageUrl('/private/expired.jpg', StorageUrlOptions::KIND_TEMPORARY, false, time() - 1),
                'excessive-ttl' => new ResolvedStorageUrl('/private/long.jpg', StorageUrlOptions::KIND_TEMPORARY, false, time() + $ttl + 120),
                'missing-expiry' => new ResolvedStorageUrl('/private/missing.jpg', StorageUrlOptions::KIND_TEMPORARY, false),
                'cacheable' => new ResolvedStorageUrl('/private/cache.jpg', StorageUrlOptions::KIND_TEMPORARY, true, time() + $ttl),
                default => new ResolvedStorageUrl('/private/' . $key . '?call=' . $ledger->urlCalls, $options->kind, false, time() + $ttl),
            };
        });
        $good = new FileAccessContext($scope, 'en_US', 21, ['customer'], 'render', 3);
        $badActor = new FileAccessContext($scope, 'en_US', 22, ['customer'], 'render', 3);
        $requests = [];
        foreach ([
            'actor' => $badActor,
            'store' => new FileAccessContext($otherStore, 'en_US', 21, ['customer'], 'render', 3),
            'channel' => new FileAccessContext($otherChannel, 'en_US', 21, ['customer'], 'render', 3),
            'role' => new FileAccessContext($scope, 'en_US', 21, ['guest'], 'render', 3),
            'revision' => new FileAccessContext($scope, 'en_US', 21, ['customer'], 'render', 2),
        ] as $key => $context) {
            $requests[$key] = ['asset_id' => self::A, 'contexts' => [$context]];
        }
        $requests['custom'] = ['asset_id' => self::A, 'contexts' => [$good], 'options' => new StorageUrlOptions(StorageUrlOptions::KIND_PUBLIC, 42)];
        $requests['default'] = ['asset_id' => self::A, 'contexts' => [$good]];
        $requests['recover-context'] = ['asset_id' => self::A, 'contexts' => [$badActor, $good]];
        $result = $this->resolveBatch($manager, $requests);

        foreach (['actor', 'store', 'channel', 'role', 'revision'] as $key) { self::assertNull($result[$key]); }
        self::assertSame('/private/' . self::A . '.jpg?call=1', $result['custom']?->url);
        self::assertSame('/private/' . self::A . '.jpg?call=2', $result['default']?->url);
        self::assertSame('/private/' . self::A . '.jpg?call=3', $result['recover-context']?->url);
        self::assertSame([42, 300, 300], array_map(static fn(StorageUrlOptions $options): int => $options->ttlSeconds, $ledger->options));
        self::assertSame(['temporary', 'temporary', 'temporary'], array_map(static fn(StorageUrlOptions $options): string => $options->kind, $ledger->options));
        self::assertCount(9, $ledger->accessContexts, 'Every candidate context is authorized separately.');
        self::assertSame($good, $ledger->accessContexts[8]);
        self::assertTrue(SharedResponseCachePolicy::isForbidden());
        self::assertContains('private_file_asset', SharedResponseCachePolicy::reasons());
        $again = $this->resolveBatch($manager, ['later' => ['asset_id' => self::A, 'contexts' => [$good]]]);
        self::assertSame('/private/' . self::A . '.jpg?call=4', $again['later']?->url, 'A later call cannot reuse a final signed URL.');

        foreach (['wrong-kind', 'expired', 'excessive-ttl', 'missing-expiry', 'cacheable'] as $mode) {
            RequestContext::resetWelineVars();
            $ledger->adapterMode = $mode;
            $invalid = $this->resolveBatch($manager, ['invalid' => ['asset_id' => self::A, 'contexts' => [$good]]]);
            self::assertNull($invalid['invalid'], $mode);
            self::assertTrue(SharedResponseCachePolicy::isForbidden(), $mode);
        }
    }

    /** @return array{FileAssetManager,stdClass} */
    private function managerFixture(array $fixtures, ?callable $urlResolver = null): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE asset_fixture (asset_id TEXT PRIMARY KEY, disk_code TEXT, object_key TEXT, visibility TEXT, lifecycle_state TEXT, deleted_at TEXT, metadata TEXT)');
        $pdo->exec('CREATE TABLE locale_fixture (asset_locale_id INTEGER PRIMARY KEY, asset_id TEXT, locale_code TEXT)');
        $assetInsert = $pdo->prepare('INSERT INTO asset_fixture VALUES (?, ?, ?, ?, ?, ?, ?)');
        $localeInsert = $pdo->prepare('INSERT INTO locale_fixture (asset_id, locale_code) VALUES (?, ?)');
        foreach ($fixtures as $fixture) {
            [$assetId, $locales] = $fixture;
            $overrides = $fixture[2] ?? [];
            $assetInsert->execute([$assetId, 'fixture', $assetId . '.jpg', $overrides['visibility'] ?? 'public', 'ready', null, $overrides['metadata'] ?? '{}']);
            foreach ($locales as $locale) { $localeInsert->execute([$assetId, $locale]); }
        }
        $ledger = (object)['sql' => [], 'bindings' => [], 'urlCalls' => 0, 'options' => [], 'accessContexts' => [], 'accessAssets' => [], 'fetchHooks' => []];
        $assets = new class extends FileAsset {
            use FileAssetBatchHydrationObserver;
            public ?QueryInterface $fixtureQuery = null;
            public function __construct(array $data = []) { $this->_primary_key = static::schema_fields_ID; parent::__construct($data); }
            public function __init() {}
            public function getQuery(bool $keep_condition = true): QueryInterface { return $this->fixtureQuery->table('asset_fixture'); }
            public function newQuery(bool $really_new = true): QueryInterface { return $this->getQuery(); }
        };
        $assets->fixtureQuery = $this->query($pdo, $ledger, 'asset_fixture');
        $assets->fixtureLedger = $ledger;
        $locales = new class extends FileAssetLocale {
            use FileAssetBatchHydrationObserver;
            public ?QueryInterface $fixtureQuery = null;
            public function __construct(array $data = []) { $this->_primary_key = static::schema_fields_ID; parent::__construct($data); }
            public function __init() {}
            public function getQuery(bool $keep_condition = true): QueryInterface { return $this->fixtureQuery->table('locale_fixture'); }
            public function newQuery(bool $really_new = true): QueryInterface { return $this->getQuery(); }
        };
        $locales->fixtureQuery = $this->query($pdo, $ledger, 'locale_fixture');
        $locales->fixtureLedger = $ledger;
        $disk = $this->createMock(StorageDiskInterface::class);
        $disk->method('resolveUrl')->willReturnCallback(static function (string $key, ?StorageUrlOptions $options) use ($ledger, $urlResolver): ResolvedStorageUrl {
            ++$ledger->urlCalls;
            $ledger->options[] = $options;
            if ($urlResolver !== null) { return $urlResolver($key, $options, $ledger); }
            return new ResolvedStorageUrl('/media/' . $key . '?call=' . $ledger->urlCalls, StorageUrlOptions::KIND_PUBLIC, true);
        });
        $storage = $this->createMock(StorageManagerInterface::class);
        $storage->method('disk')->with('fixture')->willReturn($disk);
        $policy = new class($ledger) implements FileAccessPolicyInterface {
            public function __construct(private stdClass $ledger) {}
            public function assertCanRead(FileAsset $asset, FileAccessContext $context): void
            {
                $this->ledger->accessContexts[] = $context;
                $this->ledger->accessAssets[] = $asset;
                (new FileAccessPolicy())->assertCanRead($asset, $context);
            }
            public function assertCanManage(FileAsset $asset, FileAccessContext $context): void
            {
                (new FileAccessPolicy())->assertCanManage($asset, $context);
            }
        };
        return [new FileAssetManager($assets, $locales, $storage, $policy), $ledger];
    }

    private function query(PDO $pdo, stdClass $ledger, string $table): Query
    {
        return new class($pdo, $ledger, $table) extends Query {
            public function __construct(private PDO $database, private stdClass $ledger, string $table)
            {
                parent::__construct();
                $this->db_name = '';
                $this->table = $table;
                $this->table_alias = 'main_table';
                $this->fields = 'main_table.*';
            }
            public function getLink(): PDO { return $this->database; }
            protected function preparePgsql(string $sql, array $options = []): PDOStatement|false
            {
                $this->ledger->sql[] = $sql;
                $this->ledger->bindings[] = $this->bound_values;
                return $this->database->prepare($sql, $options);
            }
        };
    }

    private function hydrateFetchResult(AbstractModel $model, mixed $queryData): mixed
    {
        return (new QueryDelegator())->hydrateFetchResult($model, $queryData);
    }

    private function resolveBatch(FileAssetManager $manager, array $requests): array
    {
        return $manager->resolveUrls($requests);
    }
}

/** Test-only observer: real Query fetch and manager batch still own hydration. */
trait FileAssetBatchHydrationObserver
{
    public ?stdClass $fixtureLedger = null;

    public function fetch_before() { $this->observeHydration('before'); }
    public function fetch_after() { $this->observeHydration('after'); }

    private function observeHydration(string $event): void
    {
        if ($this->fixtureLedger === null) { return; }
        $queryData = $this->getQueryData();
        $row = is_object($queryData) ? $queryData->getData() : (is_array($queryData) ? $queryData : []);
        $this->fixtureLedger->fetchHooks[] = [
            'kind' => $this instanceof FileAssetLocale ? 'locale' : 'asset',
            'event' => $event,
            'query_asset_id' => (string)($row['asset_id'] ?? ''),
            'query_locale' => (string)($row['locale_code'] ?? ''),
            'model_data' => $this->getData(),
            'fetch_data' => $this->getFetchData(),
        ];
    }
}
