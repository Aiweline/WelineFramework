<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\HanfuCleanup;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Inventory\Api\InventoryCatalogMaintenanceInterface;
use Weline\Product\Service\HanfuCleanup\HanfuCatalogCleanupService;
use Weline\Product\Service\HanfuCleanup\HanfuCatalogMediaQuarantine;
use Weline\Product\Service\HanfuCleanup\HanfuTestCatalogSelection;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\Data\StorageUrlOptions;

final class HanfuCatalogCleanupServiceTest extends TestCase
{
    private string $artifactRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artifactRoot = sys_get_temp_dir() . '/hanfu-cleanup-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->artifactRoot);
        parent::tearDown();
    }

    public function testPreviewFreezesExactlyTwentyNineProductsAndPreservationIds(): void
    {
        $fixture = new CleanupCatalogFixture();
        $service = $this->service($fixture);

        $preview = $service->preview(0, 'cleanup-20260902');

        self::assertSame((new HanfuTestCatalogSelection())->productIds(), $preview['product_ids']);
        self::assertCount(29, $preview['products']);
        self::assertSame([10, 11], $preview['preservation_snapshot']['category_ids']);
        self::assertSame([20, 21], $preview['preservation_snapshot']['brand_ids']);
        self::assertSame([30, 31], $preview['preservation_snapshot']['supplier_ids']);
        self::assertSame(['30:20', '31:21'], $preview['preservation_snapshot']['supplier_brand_ids']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['selection_digest']);
        self::assertSame([], $fixture->events);

        $path = $this->artifactRoot . '/cleanup-20260902/cleanup-selection.json';
        self::assertFileExists($path);
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testPreviewFailsWhenOneFrozenProductIsMissing(): void
    {
        $fixture = new CleanupCatalogFixture();
        unset($fixture->products[45]);

        $this->expectExceptionMessage('hanfu_cleanup_selection_incomplete');
        $this->service($fixture)->preview(0, 'cleanup-20260902');
    }

    public function testDigestDriftPreventsEveryMutation(): void
    {
        $fixture = new CleanupCatalogFixture();
        $service = $this->service($fixture);
        $selection = $service->preview(0, 'cleanup-20260902');
        $fixture->products[45]['name'] = 'drifted-name';

        try {
            $service->apply(0, 'cleanup-20260902', (string)$selection['selection_digest']);
            self::fail('Digest drift must reject the cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hanfu_cleanup_selection_drift', $exception->getMessage());
        }

        self::assertSame([], $fixture->files->moveCalls);
        self::assertSame([], $fixture->events);
    }

    public function testProtectedReferencesPreventEveryMutation(): void
    {
        $fixture = new CleanupCatalogFixture();
        $fixture->inventory->protectedReferences = [[
            'reference_type' => 'active_reservation',
            'offer_id' => 101,
        ]];
        $service = $this->service($fixture);
        $selection = $service->preview(0, 'cleanup-20260902');

        try {
            $service->apply(0, 'cleanup-20260902', (string)$selection['selection_digest']);
            self::fail('Protected references must reject the cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hanfu_cleanup_protected_references', $exception->getMessage());
        }

        self::assertSame([], $fixture->files->moveCalls);
        self::assertSame([], $fixture->events);
    }

    public function testApplyQuarantinesBeforeDeclaredDeletionOrderAndInvalidatesOnce(): void
    {
        $fixture = new CleanupCatalogFixture();
        $service = $this->service($fixture);
        $selection = $service->preview(0, 'cleanup-20260902');

        $report = $service->apply(
            0,
            'cleanup-20260902',
            (string)$selection['selection_digest'],
        );

        self::assertSame('database_clean_files_quarantined', $report['status']);
        self::assertSame([
            'file:catalog/hanfu/test/exclusive.jpg>quarantine',
            'transaction:start',
            'delete:prices',
            'delete:store_offers',
            'delete:inventory',
            'delete:offer_attributes',
            'delete:offers',
            'delete:category_links',
            'delete:store_products',
            'delete:media',
            'delete:product_attributes',
            'delete:products',
            'delete:identities',
            'transaction:commit',
            'cache:invalidate',
            'search:invalidate',
        ], $fixture->events);
        self::assertSame(1, $fixture->cacheInvalidations);
        self::assertSame(1, $fixture->searchInvalidations);
        self::assertTrue($fixture->deleted);
    }

    public function testDatabaseFailureRestoresQuarantine(): void
    {
        $fixture = new CleanupCatalogFixture();
        $fixture->failStep = 'offers';
        $service = $this->service($fixture);
        $selection = $service->preview(0, 'cleanup-20260902');

        try {
            $service->apply(0, 'cleanup-20260902', (string)$selection['selection_digest']);
            self::fail('Database failure was not surfaced.');
        } catch (\RuntimeException $exception) {
            self::assertSame('fixture_database_failure', $exception->getMessage());
        }

        self::assertContains('transaction:rollback', $fixture->events);
        self::assertSame(
            [
                'catalog/hanfu/test/exclusive.jpg>' . $fixture->quarantineTarget(),
                $fixture->quarantineTarget() . '>catalog/hanfu/test/exclusive.jpg',
            ],
            $fixture->files->moveCalls,
        );
        self::assertTrue($fixture->files->has('catalog/hanfu/test/exclusive.jpg'));
        self::assertSame(0, $fixture->cacheInvalidations);
        self::assertSame(0, $fixture->searchInvalidations);
    }

    public function testVerifyRequiresZeroRowsAndUnchangedPreservationThenFinalizes(): void
    {
        $fixture = new CleanupCatalogFixture();
        $service = $this->service($fixture);
        $selection = $service->preview(0, 'cleanup-20260902');
        $service->apply(0, 'cleanup-20260902', (string)$selection['selection_digest']);

        $fixture->livePreservation['brand_ids'][] = 99;
        try {
            $service->verify(0, 'cleanup-20260902', (string)$selection['selection_digest']);
            self::fail('Preservation drift must fail verification.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hanfu_cleanup_postcondition_failed', $exception->getMessage());
        }
        self::assertTrue($fixture->files->has($fixture->quarantineTarget()));

        $fixture->livePreservation = $fixture->preservation;
        $verification = $service->verify(
            0,
            'cleanup-20260902',
            (string)$selection['selection_digest'],
        );
        self::assertSame('verified', $verification['status']);
        self::assertTrue($verification['postconditions']['passed']);
        self::assertFalse($fixture->files->has($fixture->quarantineTarget()));
        self::assertSame([$fixture->quarantineTarget()], $fixture->files->deleteCalls);
    }

    public function testFinalizeFailureRetainsRecoverableManifest(): void
    {
        $fixture = new CleanupCatalogFixture();
        $service = $this->service($fixture);
        $selection = $service->preview(0, 'cleanup-20260902');
        $service->apply(0, 'cleanup-20260902', (string)$selection['selection_digest']);
        $fixture->files->failDelete = true;

        $verification = $service->verify(
            0,
            'cleanup-20260902',
            (string)$selection['selection_digest'],
        );

        self::assertSame('database_clean_files_pending', $verification['status']);
        self::assertSame('fixture_delete_failure', $verification['file_error']);
        self::assertTrue($fixture->files->has($fixture->quarantineTarget()));
        self::assertFileExists(
            $this->artifactRoot . '/cleanup-20260902/quarantine-manifest.json',
        );
    }

    public function testOnlyWebsiteZeroAndSafeRunIdsAreAccepted(): void
    {
        $service = $this->service(new CleanupCatalogFixture());

        foreach ([[1, 'safe-run'], [0, '../escape'], [0, 'bad/run']] as [$websiteId, $runId]) {
            try {
                $service->preview($websiteId, $runId);
                self::fail('Invalid cleanup scope must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertContains($exception->getMessage(), [
                    'hanfu_cleanup_website_invalid',
                    'hanfu_cleanup_run_id_invalid',
                ]);
            }
        }
    }

    private function service(CleanupCatalogFixture $fixture): HanfuCatalogCleanupService
    {
        $connection = (new \ReflectionClass(ConnectionFactory::class))->newInstanceWithoutConstructor();
        return new HanfuCatalogCleanupService(
            $connection,
            new CleanupTransactionRunner($fixture->events),
            $fixture->inventory,
            new HanfuTestCatalogSelection(),
            new HanfuCatalogMediaQuarantine($fixture->files),
            null,
            null,
            snapshotLoader: fn(int $websiteId, array $productIds): array =>
                $fixture->snapshot($websiteId, $productIds),
            deletionStep: function (string $step, int $websiteId, array $snapshot) use ($fixture): array {
                $fixture->events[] = 'delete:' . $step;
                if ($fixture->failStep === $step) {
                    throw new \RuntimeException('fixture_database_failure');
                }
                if ($step === 'products') {
                    $fixture->deleted = true;
                }
                return ['deleted' => $step === 'products' ? 29 : 1];
            },
            postconditionLoader: fn(int $websiteId, array $selection): array => [
                'remaining_product_ids' => $fixture->deleted ? [] : $selection['product_ids'],
                'remaining_offer_ids' => $fixture->deleted ? [] : $selection['offer_ids'],
                'remaining_media_ids' => $fixture->deleted ? [] : $selection['media_ids'],
                'inventory_stock_items' => $fixture->deleted ? 0 : 29,
                'inventory_reservations' => 0,
                'preservation_snapshot' => $fixture->livePreservation,
            ],
            cacheInvalidator: function (int $websiteId, array $context) use ($fixture): array {
                $fixture->cacheInvalidations++;
                $fixture->events[] = 'cache:invalidate';
                return ['status' => 'invalidated'];
            },
            searchInvalidator: function (int $websiteId, array $context) use ($fixture): array {
                $fixture->searchInvalidations++;
                $fixture->events[] = 'search:invalidate';
                return ['status' => 'rebuilt'];
            },
            artifactRoot: $this->artifactRoot,
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}

final class CleanupCatalogFixture
{
    /** @var array<int,array<string,mixed>> */
    public array $products = [];

    /** @var list<string> */
    public array $events = [];

    /** @var array<string,list<int|string>> */
    public array $preservation = [
        'category_ids' => [10, 11],
        'brand_ids' => [20, 21],
        'supplier_ids' => [30, 31],
        'supplier_brand_ids' => ['30:20', '31:21'],
    ];

    /** @var array<string,list<int|string>> */
    public array $livePreservation;

    public readonly CleanupFileAssetLibrary $files;
    public readonly CleanupInventoryMaintenance $inventory;
    public bool $deleted = false;
    public ?string $failStep = null;
    public int $cacheInvalidations = 0;
    public int $searchInvalidations = 0;

    public function __construct()
    {
        foreach ((new HanfuTestCatalogSelection())->productIds() as $productId) {
            $this->products[$productId] = [
                'product_id' => $productId,
                'global_product_uuid' => sprintf('00000000-0000-4000-8000-%012d', $productId),
                'sku' => 'TEST-' . $productId,
                'name' => 'Test product ' . $productId,
            ];
        }
        $this->livePreservation = $this->preservation;
        $this->files = new CleanupFileAssetLibrary($this->events);
        $this->files->seed('catalog/hanfu/test/exclusive.jpg', hash('sha256', 'exclusive'));
        $this->inventory = new CleanupInventoryMaintenance();
    }

    /** @param list<int> $productIds @return array<string,mixed> */
    public function snapshot(int $websiteId, array $productIds): array
    {
        $products = [];
        $offers = [];
        foreach ($productIds as $productId) {
            if (!isset($this->products[$productId])) {
                continue;
            }
            $products[] = $this->products[$productId];
            $offers[] = [
                'offer_id' => 100 + $productId,
                'product_id' => $productId,
                'global_offer_uuid' => sprintf('10000000-0000-4000-8000-%012d', $productId),
                'sku' => 'TEST-' . $productId . '-ONE',
            ];
        }

        return [
            'products' => $products,
            'offers' => $offers,
            'media' => [[
                'media_id' => 501,
                'product_id' => 1,
                'disk_code' => StorageDiskCode::BUILTIN_LOCAL_MEDIA,
                'object_key' => 'catalog/hanfu/test/exclusive.jpg',
                'blob_key' => 'exclusive',
            ]],
            'reference_index' => [
                'exclusive' => [
                    'media_count' => 1,
                    'homepage_references' => 0,
                    'content_references' => 0,
                    'config_references' => 0,
                ],
            ],
            'dependent_counts' => [
                'prices' => count($offers),
                'store_offers' => count($offers),
                'offer_attributes' => count($offers),
                'category_links' => count($products),
                'store_products' => count($products),
                'media' => 1,
                'product_attributes' => count($products),
            ],
            'preservation_snapshot' => $this->preservation,
            'protected_references' => [],
        ];
    }

    public function quarantineTarget(): string
    {
        return 'hanfu-1688/cleanup-20260902/quarantine/'
            . hash('sha256', 'exclusive')
            . '/exclusive.jpg';
    }
}

final class CleanupTransactionRunner implements DatabaseTransactionRunnerInterface
{
    /** @var list<string> */
    private array $events;

    /** @param list<string> $events */
    public function __construct(array &$events)
    {
        $this->events =& $events;
    }

    public function run(ConnectionFactory $connection, callable $callback): mixed
    {
        $this->events[] = 'transaction:start';
        try {
            $result = $callback();
            $this->events[] = 'transaction:commit';
            return $result;
        } catch (\Throwable $exception) {
            $this->events[] = 'transaction:rollback';
            throw $exception;
        }
    }
}

final class CleanupInventoryMaintenance implements InventoryCatalogMaintenanceInterface
{
    /** @var list<array<string,mixed>> */
    public array $protectedReferences = [];

    public function previewCatalogPurge(int $websiteId, array $offerIds): array
    {
        return [
            'stock_items' => count($offerIds),
            'reservations' => 0,
            'ledger_events' => 0,
            'protected_references' => $this->protectedReferences,
        ];
    }

    public function purgeCatalogOffers(int $websiteId, array $offerIds): array
    {
        return [
            'stock_items' => count($offerIds),
            'reservations' => 0,
            'ledger_events' => 0,
        ];
    }
}

final class CleanupFileAssetLibrary implements FileAssetLibraryInterface
{
    /** @var array<string,array<string,mixed>> */
    private array $assets = [];

    /** @var list<string> */
    private array $events;

    /** @var list<string> */
    public array $moveCalls = [];

    /** @var list<string> */
    public array $deleteCalls = [];

    public bool $failDelete = false;

    /** @param list<string> $events */
    public function __construct(array &$events)
    {
        $this->events =& $events;
    }

    public function seed(string $objectKey, string $sha256): void
    {
        $this->assets[StorageDiskCode::BUILTIN_LOCAL_MEDIA . '|' . $objectKey] = [
            'asset_id' => 'asset-' . substr(hash('sha256', $objectKey), 0, 16),
            'disk_code' => StorageDiskCode::BUILTIN_LOCAL_MEDIA,
            'object_key' => $objectKey,
            'sha256' => $sha256,
            'asset_revision' => 1,
            'asset_ready' => true,
        ];
    }

    public function has(string $objectKey): bool
    {
        return isset($this->assets[StorageDiskCode::BUILTIN_LOCAL_MEDIA . '|' . $objectKey]);
    }

    public function describe(
        string $diskCode,
        string $objectKey,
        string $localeCode,
        FileAccessContext $access,
    ): array {
        return $this->assets[$diskCode . '|' . $objectKey] ?? [
            'asset_id' => null,
            'disk_code' => $diskCode,
            'object_key' => $objectKey,
            'asset_ready' => false,
        ];
    }

    public function resolveResourceUrl(
        string $diskCode,
        string $objectKey,
        FileAccessContext $access,
        ?StorageUrlOptions $options = null,
    ): string {
        return '';
    }

    public function upload(
        string $diskCode,
        string $objectKey,
        mixed $source,
        string $originalName,
        string $mimeType,
        string $localeCode,
        FileAccessContext $access,
        array $localeMetadata,
        string $visibility = self::VISIBILITY_PUBLIC,
        array $metadata = [],
        ?int $width = null,
        ?int $height = null,
    ): array {
        throw new \LogicException('not used');
    }

    public function saveMetadata(
        string $assetId,
        string $diskCode,
        string $objectKey,
        string $localeCode,
        FileAccessContext $access,
        int $expectedRevision,
        array $metadata,
    ): array {
        throw new \LogicException('not used');
    }

    public function moveObject(
        string $diskCode,
        string $from,
        string $to,
        FileAccessContext $access,
    ): void {
        $this->moveCalls[] = $from . '>' . $to;
        $this->events[] = str_contains($to, '/quarantine/')
            ? 'file:' . $from . '>quarantine'
            : 'file:quarantine>restore';
        $identity = $diskCode . '|' . $from;
        if (!isset($this->assets[$identity])) {
            throw new \RuntimeException('fixture_source_missing');
        }
        $asset = $this->assets[$identity];
        unset($this->assets[$identity]);
        $asset['object_key'] = $to;
        $asset['asset_revision'] = (int)$asset['asset_revision'] + 1;
        $this->assets[$diskCode . '|' . $to] = $asset;
    }

    public function moveDirectory(
        string $diskCode,
        string $from,
        string $to,
        FileAccessContext $access,
    ): void {
        throw new \LogicException('not used');
    }

    public function deleteObject(
        string $diskCode,
        string $objectKey,
        FileAccessContext $access,
    ): void {
        if ($this->failDelete) {
            throw new \RuntimeException('fixture_delete_failure');
        }
        $this->deleteCalls[] = $objectKey;
        unset($this->assets[$diskCode . '|' . $objectKey]);
    }

    public function deleteDirectory(
        string $diskCode,
        string $prefix,
        FileAccessContext $access,
    ): void {
        throw new \LogicException('not used');
    }

    public function normalizeLocale(string $localeCode): string
    {
        return $localeCode;
    }
}
