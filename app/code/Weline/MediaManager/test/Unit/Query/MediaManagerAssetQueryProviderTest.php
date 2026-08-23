<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\MediaManager\Extends\Module\Weline_Framework\Query\MediaManagerAssetQueryProvider;
use Weline\MediaManager\Service\MediaFileAccessContextFactory;
use Weline\MediaManager\Service\MediaStorageService;
use Weline\Storage\Api\StorageDirectoryManagerInterface;
use Weline\Storage\Api\StorageDiskInterface;
use Weline\Storage\Api\StorageManagerInterface;
use Weline\Storage\Api\StorageReadHandle;
use Weline\Storage\Service\StorageRequestResourceFactory;
use Weline\Storage\Service\StorageRequestResourceRegistry;

final class MediaManagerAssetQueryProviderTest extends TestCase
{
    public function testReadAssetUsesByteDetectedMimeInsteadOfFilenameMime(): void
    {
        $bytes = (string)\base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        $resources = new StorageRequestResourceRegistry();
        $stream = \fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        self::assertSame(\strlen($bytes), \fwrite($stream, $bytes));
        \rewind($stream);
        $handle = new StorageReadHandle($stream, $resources);

        $assets = $this->createMock(FileAssetLibraryInterface::class);
        $assets->method('normalizeLocale')->willReturnCallback(
            static fn (string $locale): string => $locale,
        );
        $assets->expects(self::once())->method('describe')->willReturn([
            'asset_id' => 'f50b8c76-cc04-4dbb-868d-929b2a33ba55',
            'asset_ready' => true,
            'size' => \strlen($bytes),
        ]);
        $assets->expects(self::once())
            ->method('resolveResourceUrl')
            ->willReturn('/media/private/misnamed');

        $disk = $this->createMock(StorageDiskInterface::class);
        $disk->method('diskCode')->willReturn('local::filesystem::media');
        $disk->expects(self::once())
            ->method('openRead')
            ->with('library/misnamed.jpg')
            ->willReturn($handle);
        $storageManager = $this->createMock(StorageManagerInterface::class);
        $storageManager->expects(self::once())
            ->method('disk')
            ->with('local::filesystem::media')
            ->willReturn($disk);
        $storage = new MediaStorageService(
            $assets,
            $storageManager,
            $this->createMock(StorageDirectoryManagerInterface::class),
            new StorageRequestResourceFactory($resources),
        );
        $hash = $storage->encodeHash('library/misnamed.jpg');
        $accessContexts = new MediaFileAccessContextFactory($assets);

        $result = (new MediaManagerAssetQueryProvider($storage, $accessContexts))->execute(
            'readAsset',
            [
                'hash' => $hash,
                'disk_code' => 'local::filesystem::media',
                'locale_code' => 'zh_Hans_CN',
                'actor_id' => 7,
                MediaFileAccessContextFactory::INPUT_KEY => [
                    'scope_identity' => ScopeIdentity::global()->toArray(),
                    'locale_code' => 'zh_Hans_CN',
                    'actor_id' => 7,
                    'purpose' => 'media_manager',
                    'policy_revision' => 1,
                ],
            ],
        );

        self::assertSame('image/png', $result['mime_type']);
        self::assertSame(\hash('sha256', $bytes), $result['sha256']);
        self::assertSame(\strlen($bytes), $result['size']);
        self::assertSame('/media/private/misnamed', $result['public_url']);
        self::assertSame('library/misnamed.jpg', $result['object_key']);
        self::assertSame(0, $resources->activeCount());
    }
}
