<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\UnitTest\TestCore;
use Weline\Product\Service\DetailTextifiedAssetPurger;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\Data\StorageUrlOptions;

class DetailTextifiedAssetPurgerTest extends TestCore
{
    public function testSkipsStillReferencedAndPurgesOrphans(): void
    {
        $assetIdKeep = '11111111-1111-4111-8111-111111111111';
        $assetIdDrop = '22222222-2222-4222-8222-222222222222';
        $tmp = sys_get_temp_dir() . '/weline-detail-textify-purge-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0777, true));
        $relative = 'catalog/test/detail-drop.jpg';
        $absolute = $tmp . '/' . $relative;
        self::assertTrue(mkdir(dirname($absolute), 0777, true));
        self::assertNotFalse(file_put_contents($absolute, 'jpeg-bytes'));

        $library = new class implements FileAssetLibraryInterface {
            /** @var list<array{disk:string,key:string}> */
            public array $deleted = [];

            public function describe(string $diskCode, string $objectKey, string $localeCode, FileAccessContext $access): array
            {
                return [];
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
                return [];
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
                return [];
            }

            public function saveAssetMetadata(
                string $assetId,
                string $diskCode,
                string $objectKey,
                string $localeCode,
                FileAccessContext $access,
                int $expectedRevision,
                array $metadata,
            ): array {
                return [];
            }

            public function referenceCount(string $assetId, FileAccessContext $access): int
            {
                return 0;
            }

            public function moveObject(string $diskCode, string $from, string $to, FileAccessContext $access): void
            {
            }

            public function moveDirectory(string $diskCode, string $from, string $to, FileAccessContext $access): void
            {
            }

            public function deleteObject(string $diskCode, string $objectKey, FileAccessContext $access): void
            {
                $this->deleted[] = ['disk' => $diskCode, 'key' => $objectKey];
            }

            public function deleteDirectory(string $diskCode, string $prefix, FileAccessContext $access): void
            {
            }

            public function normalizeLocale(string $localeCode): string
            {
                return $localeCode;
            }
        };

        $purgedMeta = [];
        $clearedRefs = [];
        $purger = new DetailTextifiedAssetPurger(
            $library,
            static function (string $assetId) use ($assetIdDrop, $relative): ?array {
                if ($assetId !== $assetIdDrop) {
                    return null;
                }

                return [
                    FileAsset::schema_fields_DISK_CODE => StorageDiskCode::BUILTIN_LOCAL_MEDIA,
                    FileAsset::schema_fields_OBJECT_KEY => $relative,
                ];
            },
            static function (string $assetId) use (&$clearedRefs): void {
                $clearedRefs[] = $assetId;
            },
            static function (string $assetId) use (&$purgedMeta): void {
                $purgedMeta[] = $assetId;
            },
        );

        $stillUsed = static fn(string $id): bool => $id === $assetIdKeep;
        $results = $purger->purgeReplaced(
            [$assetIdKeep, $assetIdDrop, $assetIdDrop],
            $stillUsed,
            $tmp,
        );

        self::assertSame('skipped_still_referenced', $results[0]['status']);
        self::assertSame('deleted', $results[1]['status']);
        self::assertCount(1, $library->deleted);
        self::assertSame($relative, $library->deleted[0]['key']);
        self::assertFalse(is_file($absolute));
        self::assertSame([$assetIdDrop], $clearedRefs);
        self::assertSame([$assetIdDrop], $purgedMeta);

        @unlink($absolute);
        @rmdir(dirname($absolute));
        @rmdir(dirname(dirname($absolute)));
        @rmdir($tmp);
    }
}
