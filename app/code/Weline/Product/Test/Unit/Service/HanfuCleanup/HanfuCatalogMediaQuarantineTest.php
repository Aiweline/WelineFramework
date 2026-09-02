<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\HanfuCleanup;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Service\HanfuCleanup\HanfuCatalogMediaQuarantine;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\Data\StorageUrlOptions;

final class HanfuCatalogMediaQuarantineTest extends TestCase
{
    public const DISK = StorageDiskCode::BUILTIN_LOCAL_MEDIA;

    public function testOnlyExclusiveUnreferencedObjectIsPlanned(): void
    {
        self::assertTrue(
            class_exists(HanfuCatalogMediaQuarantine::class),
            'Missing Hanfu catalog media quarantine service.',
        );
        $files = new FakeFileAssetLibrary();
        $rows = [
            $this->row(1, 'catalog/hanfu/test/exclusive.jpg', 'exclusive'),
            $this->row(2, 'catalog/hanfu/test/shared.jpg', 'shared'),
            $this->row(3, 'catalog/hanfu-entities/brand/logo.jpg', 'entity'),
            $this->row(4, 'catalog/hanfu/r2/categories/women.jpg', 'category'),
            $this->row(5, 'catalog/hanfu/test/homepage.jpg', 'homepage'),
            $this->row(6, 'catalog/hanfu/test/content.jpg', 'content'),
            $this->row(7, 'catalog/hanfu/test/config.jpg', 'config'),
        ];
        foreach ($rows as $row) {
            $files->seed((string)$row['object_key'], hash('sha256', (string)$row['blob_key']));
        }

        $manifest = $this->service($files)->plan('cleanup-20260902', $rows, [
            'exclusive' => $this->references(),
            'shared' => $this->references(mediaCount: 2),
            'entity' => $this->references(),
            'category' => $this->references(),
            'homepage' => $this->references(homepage: 1),
            'content' => $this->references(content: 1),
            'config' => $this->references(config: 1),
        ]);

        self::assertSame('planned', $manifest['status']);
        self::assertSame(
            ['catalog/hanfu/test/exclusive.jpg'],
            array_column($manifest['moves'], 'from'),
        );
        self::assertSame(
            'hanfu-1688/cleanup-20260902/quarantine/'
            . hash('sha256', 'exclusive')
            . '/exclusive.jpg',
            $manifest['moves'][0]['to'],
        );
        self::assertSame(1, $manifest['moves'][0]['asset_revision']);

        $reasons = [];
        foreach ($manifest['preserved'] as $row) {
            $reasons[(string)$row['object_key']] = (string)$row['preserve_reason'];
        }
        self::assertSame('shared_blob', $reasons['catalog/hanfu/test/shared.jpg']);
        self::assertSame('protected_prefix', $reasons['catalog/hanfu-entities/brand/logo.jpg']);
        self::assertSame('protected_prefix', $reasons['catalog/hanfu/r2/categories/women.jpg']);
        self::assertSame('external_reference', $reasons['catalog/hanfu/test/homepage.jpg']);
        self::assertSame('external_reference', $reasons['catalog/hanfu/test/content.jpg']);
        self::assertSame('external_reference', $reasons['catalog/hanfu/test/config.jpg']);
    }

    public function testMoveFailureRestoresCompletedMovesInReverseOrder(): void
    {
        $files = new FakeFileAssetLibrary();
        $rows = [];
        $references = [];
        foreach (['a', 'b', 'c'] as $index => $name) {
            $key = "catalog/hanfu/test/{$name}.jpg";
            $rows[] = $this->row($index + 1, $key, $name);
            $references[$name] = $this->references();
            $files->seed($key, hash('sha256', $name));
        }
        $manifest = $this->service($files)->plan('rollback-run', $rows, $references);
        $files->failMoveFrom = 'catalog/hanfu/test/c.jpg';

        try {
            $this->service($files)->quarantine($manifest);
            self::fail('Expected the injected move failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('fake_move_failure', $exception->getMessage());
        }

        $moves = $manifest['moves'];
        self::assertSame([
            'catalog/hanfu/test/a.jpg>' . $moves[0]['to'],
            'catalog/hanfu/test/b.jpg>' . $moves[1]['to'],
            'catalog/hanfu/test/c.jpg>' . $moves[2]['to'],
            $moves[1]['to'] . '>catalog/hanfu/test/b.jpg',
            $moves[0]['to'] . '>catalog/hanfu/test/a.jpg',
        ], $files->moveCalls);
        self::assertTrue($files->has('catalog/hanfu/test/a.jpg'));
        self::assertTrue($files->has('catalog/hanfu/test/b.jpg'));
        self::assertTrue($files->has('catalog/hanfu/test/c.jpg'));
    }

    public function testRestoreIsReplaySafeAndFinalizeDeletesOnlyCompletedMoves(): void
    {
        $files = new FakeFileAssetLibrary();
        foreach (['a', 'b'] as $name) {
            $files->seed("catalog/hanfu/test/{$name}.jpg", hash('sha256', $name));
        }
        $service = $this->service($files);
        $plan = $service->plan('lifecycle-run', [
            $this->row(1, 'catalog/hanfu/test/a.jpg', 'a'),
            $this->row(2, 'catalog/hanfu/test/b.jpg', 'b'),
        ], [
            'a' => $this->references(),
            'b' => $this->references(),
        ]);

        $aOnly = $plan;
        $aOnly['moves'] = [$plan['moves'][0]];
        $quarantined = $service->quarantine($aOnly);
        $restored = $service->restore($quarantined);
        self::assertSame('restored', $restored['status']);
        self::assertTrue($files->has('catalog/hanfu/test/a.jpg'));
        self::assertSame($restored, $service->restore($restored));

        $replanned = $service->plan('lifecycle-run', [
            $this->row(1, 'catalog/hanfu/test/a.jpg', 'a'),
            $this->row(2, 'catalog/hanfu/test/b.jpg', 'b'),
        ], [
            'a' => $this->references(),
            'b' => $this->references(),
        ]);
        $aOnly = $replanned;
        $aOnly['moves'] = [$replanned['moves'][0]];
        $quarantined = $service->quarantine($aOnly);
        $quarantined['moves'] = $replanned['moves'];
        $finalized = $service->finalize($quarantined);
        self::assertSame('finalized', $finalized['status']);
        self::assertSame([$replanned['moves'][0]['to']], $files->deleteCalls);
        self::assertFalse($files->has($replanned['moves'][0]['to']));
        self::assertTrue($files->has('catalog/hanfu/test/b.jpg'));
        self::assertFalse($files->has($replanned['moves'][1]['to']));
    }

    public function testExternalMissingReferencedAssetAndLegacyRowsAreClassifiedSafely(): void
    {
        $files = new FakeFileAssetLibrary();
        $legacyKey = 'storefront-theme/theme-store-001.svg';
        $managedKey = 'catalog/hanfu/r2/products/exclusive.webp';
        $referencedKey = 'banner/shared.png';
        $files->seed($legacyKey, hash('sha256', 'legacy'));
        $files->seed($managedKey, hash('sha256', 'managed'));
        $files->seed($referencedKey, hash('sha256', 'referenced'));

        $external = $this->row(1, 'https://images.example.test/catalog.jpg', 'external');
        $external['media_storage_kind'] = 'external';
        $missing = $this->row(2, 'catalog/hanfu/test/already-gone.jpg', 'missing');
        $missing['media_storage_kind'] = 'missing';
        $legacy = $this->row(3, $legacyKey, 'legacy');
        $legacy['media_storage_kind'] = 'legacy';
        $managed = $this->row(4, $managedKey, 'managed');
        $managed['media_storage_kind'] = 'managed';
        $referenced = $this->row(5, $referencedKey, 'referenced');
        $referenced['media_storage_kind'] = 'managed';

        $service = $this->service($files, $this->legacyInspector($files));
        $manifest = $service->plan('mixed-media-run', [
            $external,
            $missing,
            $legacy,
            $managed,
            $referenced,
        ], [
            'external' => $this->references(),
            'missing' => $this->references(),
            'legacy' => $this->references(),
            'managed' => $this->references(),
            'referenced' => $this->references() + ['file_asset_references' => 1],
        ]);

        self::assertSame(
            [$managedKey, $legacyKey],
            array_column($manifest['moves'], 'from'),
        );
        self::assertSame(
            ['managed', 'legacy'],
            array_column($manifest['moves'], 'media_storage_kind'),
        );
        $reasons = [];
        foreach ($manifest['preserved'] as $row) {
            $reasons[(string)$row['blob_key']] = (string)$row['preserve_reason'];
        }
        self::assertSame('external_resource', $reasons['external']);
        self::assertSame('already_missing', $reasons['missing']);
        self::assertSame('external_reference', $reasons['referenced']);

        $quarantined = $service->quarantine($manifest);
        self::assertSame('quarantined', $quarantined['status']);
        self::assertSame([2, 0], array_column($quarantined['completed'], 'quarantine_revision'));
        $finalized = $service->finalize($quarantined);
        self::assertSame('finalized', $finalized['status']);
        self::assertSame(2, count($finalized['finalized']));
        self::assertFalse($files->has($legacyKey));
        self::assertFalse($files->has($managedKey));
    }

    public function testInvalidPathsUnknownDisksAndDescriptorEscapeAreRejected(): void
    {
        $files = new FakeFileAssetLibrary();
        $service = $this->service($files);
        foreach ([
            '/absolute.jpg',
            '../escape.jpg',
            'catalog/../escape.jpg',
            '%252e%252e/escape.jpg',
            'C:\\escape.jpg',
        ] as $key) {
            try {
                $service->plan('invalid-run', [$this->row(1, $key, 'invalid')], [
                    'invalid' => $this->references(),
                ]);
                self::fail('Expected invalid object key rejection: ' . $key);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('hanfu_cleanup_media_path_invalid', $exception->getMessage());
            }
        }

        try {
            $service->plan('invalid-run', [[
                'media_id' => 1,
                'disk_code' => 'r2::unknown::disk',
                'object_key' => 'catalog/hanfu/test/a.jpg',
                'blob_key' => 'a',
            ]], ['a' => $this->references()]);
            self::fail('Expected unknown disk rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('hanfu_cleanup_media_disk_invalid', $exception->getMessage());
        }

        $key = 'catalog/hanfu/test/symlink.jpg';
        $files->seed($key, hash('sha256', 'symlink'), objectKey: 'catalog/outside.jpg');
        try {
            $service->plan('invalid-run', [$this->row(1, $key, 'symlink')], [
                'symlink' => $this->references(),
            ]);
            self::fail('Expected descriptor identity mismatch rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hanfu_cleanup_media_identity_drift', $exception->getMessage());
        }
    }

    public function testDigestAndRevisionDriftFailBeforeMutation(): void
    {
        $files = new FakeFileAssetLibrary();
        $key = 'catalog/hanfu/test/drift.jpg';
        $files->seed($key, hash('sha256', 'before'));
        $service = $this->service($files);
        $manifest = $service->plan('drift-run', [$this->row(1, $key, 'drift')], [
            'drift' => $this->references(),
        ]);

        $files->assets[self::DISK . '|' . $key]['sha256'] = hash('sha256', 'after');
        try {
            $service->quarantine($manifest);
            self::fail('Expected digest drift rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hanfu_cleanup_media_digest_drift', $exception->getMessage());
        }
        self::assertSame([], $files->moveCalls);

        $files->assets[self::DISK . '|' . $key]['sha256'] = hash('sha256', 'before');
        $files->assets[self::DISK . '|' . $key]['asset_revision'] = 2;
        try {
            $service->quarantine($manifest);
            self::fail('Expected revision drift rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hanfu_cleanup_media_revision_drift', $exception->getMessage());
        }
        self::assertSame([], $files->moveCalls);
    }

    public function testFinalizeRejectsQuarantineDigestDrift(): void
    {
        $files = new FakeFileAssetLibrary();
        $key = 'catalog/hanfu/test/finalize.jpg';
        $files->seed($key, hash('sha256', 'finalize'));
        $service = $this->service($files);
        $manifest = $service->plan('finalize-run', [$this->row(1, $key, 'finalize')], [
            'finalize' => $this->references(),
        ]);
        $manifest = $service->quarantine($manifest);
        $to = (string)$manifest['completed'][0]['to'];
        $files->assets[self::DISK . '|' . $to]['sha256'] = hash('sha256', 'changed');

        try {
            $service->finalize($manifest);
            self::fail('Expected finalization digest drift rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hanfu_cleanup_media_digest_drift', $exception->getMessage());
        }
        self::assertSame([], $files->deleteCalls);
    }

    /** @return array<string,mixed> */
    private function row(int $mediaId, string $objectKey, string $blobKey): array
    {
        return [
            'media_id' => $mediaId,
            'disk_code' => self::DISK,
            'object_key' => $objectKey,
            'blob_key' => $blobKey,
        ];
    }

    /** @return array<string,int> */
    private function references(
        int $mediaCount = 1,
        int $homepage = 0,
        int $content = 0,
        int $config = 0,
    ): array {
        return [
            'media_count' => $mediaCount,
            'homepage_references' => $homepage,
            'content_references' => $content,
            'config_references' => $config,
        ];
    }

    private function service(
        FakeFileAssetLibrary $files,
        ?callable $legacyInspector = null,
    ): HanfuCatalogMediaQuarantine
    {
        return new HanfuCatalogMediaQuarantine(
            $files,
            new FileAccessContext(
                ScopeIdentity::global(),
                'zh_Hans_CN',
                null,
                ['catalog_maintenance'],
                'metadata_edit',
            ),
            [self::DISK],
            $legacyInspector,
        );
    }

    private function legacyInspector(FakeFileAssetLibrary $files): callable
    {
        return static function (string $diskCode, string $objectKey) use ($files): array {
            $asset = $files->assets[$diskCode . '|' . $objectKey] ?? null;
            return [
                'exists' => is_array($asset),
                'disk_code' => $diskCode,
                'object_key' => $objectKey,
                'sha256' => is_array($asset) ? (string)($asset['sha256'] ?? '') : '',
            ];
        };
    }
}

final class FakeFileAssetLibrary implements FileAssetLibraryInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $assets = [];

    /** @var list<string> */
    public array $moveCalls = [];

    /** @var list<string> */
    public array $deleteCalls = [];

    public ?string $failMoveFrom = null;

    public function seed(
        string $key,
        string $sha256,
        int $revision = 1,
        ?string $objectKey = null,
    ): void {
        $this->assets[HanfuCatalogMediaQuarantineTest::DISK . '|' . $key] = [
            'asset_id' => 'asset-' . substr(hash('sha256', $key), 0, 16),
            'disk_code' => HanfuCatalogMediaQuarantineTest::DISK,
            'object_key' => $objectKey ?? $key,
            'sha256' => $sha256,
            'asset_revision' => $revision,
            'asset_ready' => true,
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->assets[HanfuCatalogMediaQuarantineTest::DISK . '|' . $key]);
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
            'locale_code' => $localeCode,
            'asset_ready' => false,
            'asset_selectable' => false,
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
        if ($this->failMoveFrom !== null && hash_equals($this->failMoveFrom, $from)) {
            throw new \RuntimeException('fake_move_failure');
        }
        $identity = $diskCode . '|' . $from;
        if (!isset($this->assets[$identity])) {
            throw new \RuntimeException('fake_source_missing');
        }
        $asset = $this->assets[$identity];
        unset($this->assets[$identity]);
        $asset['object_key'] = $to;
        $asset['asset_revision'] = (int)($asset['asset_revision'] ?? 0) + 1;
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
