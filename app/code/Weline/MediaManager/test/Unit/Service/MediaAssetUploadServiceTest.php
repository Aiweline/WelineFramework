<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\MediaManager\Service\MediaAssetUploadService;
use Weline\Storage\Service\StorageRequestResourceFactory;
use Weline\Storage\Service\StorageRequestResourceRegistry;

final class MediaAssetUploadServiceTest extends TestCase
{
    public function testBatchKeepsMetadataBoundToEachFile(): void
    {
        $paths = [tempnam(sys_get_temp_dir(), 'mmu1_'), tempnam(sys_get_temp_dir(), 'mmu2_')];
        self::assertIsString($paths[0]);
        self::assertIsString($paths[1]);
        file_put_contents($paths[0], 'first');
        file_put_contents($paths[1], 'second');
        $seen = [];
        $access = self::accessContext();

        try {
            $assets = $this->createMock(FileAssetLibraryInterface::class);
            $assets->expects(self::exactly(2))->method('upload')->willReturnCallback(
                static function (
                    string $disk,
                    string $key,
                    mixed $stream,
                    string $name,
                    string $mime,
                    string $locale,
                    FileAccessContext $access,
                    array $metadata,
                ) use (&$seen): array {
                    self::assertIsResource($stream);
                    self::assertSame('media_manager', $access->purpose);
                    $seen[$name] = $metadata;
                    return [
                        'asset_id' => 'asset-' . $name,
                        'disk_code' => $disk,
                        'object_key' => $key,
                        'locale_code' => $locale,
                        'mime' => $mime,
                        'asset_ready' => true,
                        'asset_selectable' => true,
                    ];
                },
            );

            $service = $this->createService($assets);
            $result = $service->uploadFiles([
                [
                    'name' => 'first.txt',
                    'tmp_name' => $paths[0],
                    'type' => 'text/plain',
                    'error' => UPLOAD_ERR_OK,
                    'size' => 5,
                    'metadata' => [
                        'default_alt' => 'First alt',
                        'description' => 'First description',
                    ],
                ],
                [
                    'name' => 'second.txt',
                    'tmp_name' => $paths[1],
                    'type' => 'text/plain',
                    'error' => UPLOAD_ERR_OK,
                    'size' => 6,
                    'metadata' => [
                        'default_alt' => 'Second alt',
                        'description' => 'Second description',
                    ],
                ],
            ], 'local::filesystem::media', 'batch', 'zh_Hans_CN', $access, [],
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                ['text/plain'],
                1024,
                [],
                ['txt'],
            );

            self::assertCount(2, $result);
            self::assertSame('First alt', $seen['first.txt']['default_alt']);
            self::assertSame('Second alt', $seen['second.txt']['default_alt']);
            self::assertSame('first', $seen['first.txt']['display_name']);
            self::assertSame('second', $seen['second.txt']['display_name']);
        } finally {
            foreach ($paths as $path) {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testConfiguredByteLimitAppliesToTheWholeUploadBatch(): void
    {
        $paths = [tempnam(sys_get_temp_dir(), 'mmu_limit1_'), tempnam(sys_get_temp_dir(), 'mmu_limit2_')];
        self::assertIsString($paths[0]);
        self::assertIsString($paths[1]);
        file_put_contents($paths[0], str_repeat('a', 600));
        file_put_contents($paths[1], str_repeat('b', 600));

        try {
            $assets = $this->createMock(FileAssetLibraryInterface::class);
            $assets->expects(self::never())->method('upload');
            $service = $this->createService($assets);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('单次上传文件总大小超过服务端限制');
            $service->uploadFiles([
                [
                    'name' => 'first.txt',
                    'tmp_name' => $paths[0],
                    'error' => UPLOAD_ERR_OK,
                    'metadata' => ['default_alt' => 'First', 'description' => 'First'],
                ],
                [
                    'name' => 'second.txt',
                    'tmp_name' => $paths[1],
                    'error' => UPLOAD_ERR_OK,
                    'metadata' => ['default_alt' => 'Second', 'description' => 'Second'],
                ],
            ], 'local::filesystem::media', '', 'zh_Hans_CN', self::accessContext(), [],
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                ['text/plain'],
                1024,
                [],
                ['txt'],
            );
        } finally {
            foreach ($paths as $path) {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testMismatchedOrUnknownExtensionFailsBeforeStorageWrite(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mmu_bad_');
        self::assertIsString($path);
        file_put_contents($path, '<?php echo "unsafe";');
        $access = self::accessContext();
        try {
            $assets = $this->createMock(FileAssetLibraryInterface::class);
            $assets->expects(self::never())->method('upload');
            $service = $this->createService($assets);
            $this->expectException(\InvalidArgumentException::class);
            $service->uploadFiles([[
                'name' => 'unsafe.jpg',
                'tmp_name' => $path,
                'type' => 'image/jpeg',
                'error' => UPLOAD_ERR_OK,
                'metadata' => ['default_alt' => 'Unsafe', 'description' => 'Unsafe'],
            ]], 'local::filesystem::media', '', 'zh_Hans_CN', $access, [],
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                ['image/jpeg', 'text/plain'],
                1024,
                [],
                ['jpg'],
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testLaterBatchFailureRollsBackEarlierUploadedObjects(): void
    {
        $paths = [tempnam(sys_get_temp_dir(), 'mmu_rb1_'), tempnam(sys_get_temp_dir(), 'mmu_rb2_')];
        self::assertIsString($paths[0]);
        self::assertIsString($paths[1]);
        file_put_contents($paths[0], 'first');
        file_put_contents($paths[1], 'second');
        $access = self::accessContext();

        try {
            $attempt = 0;
            $assets = $this->createMock(FileAssetLibraryInterface::class);
            $assets->expects(self::exactly(2))->method('upload')->willReturnCallback(
                static function (...$arguments) use (&$attempt): array {
                    $attempt++;
                    if ($attempt === 2) {
                        throw new \RuntimeException('second upload failed');
                    }
                    return [
                        'asset_id' => 'asset-first',
                        'disk_code' => $arguments[0],
                        'object_key' => $arguments[1],
                        'locale_code' => $arguments[5],
                    ];
                },
            );
            $assets->expects(self::once())
                ->method('deleteObject')
                ->with('local::filesystem::media', 'batch/first.txt', $access);

            $service = $this->createService($assets);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('second upload failed');
            $service->uploadFiles([
                [
                    'name' => 'first.txt',
                    'tmp_name' => $paths[0],
                    'type' => 'text/plain',
                    'error' => UPLOAD_ERR_OK,
                    'metadata' => ['default_alt' => 'First', 'description' => 'First description'],
                ],
                [
                    'name' => 'second.txt',
                    'tmp_name' => $paths[1],
                    'type' => 'text/plain',
                    'error' => UPLOAD_ERR_OK,
                    'metadata' => ['default_alt' => 'Second', 'description' => 'Second description'],
                ],
            ], 'local::filesystem::media', 'batch', 'zh_Hans_CN', $access, [],
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                ['text/plain'],
                1024,
                [],
                ['txt'],
            );
        } finally {
            foreach ($paths as $path) {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testOverlongNameFailsBeforeStorageWrite(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mmu_name_');
        self::assertIsString($path);
        file_put_contents($path, 'plain text');
        $access = self::accessContext();

        try {
            $assets = $this->createMock(FileAssetLibraryInterface::class);
            $assets->expects(self::never())->method('upload');
            $service = $this->createService($assets);
            $this->expectException(\InvalidArgumentException::class);
            $service->uploadFiles([[
                'name' => str_repeat('a', 252) . '.txt',
                'tmp_name' => $path,
                'type' => 'text/plain',
                'error' => UPLOAD_ERR_OK,
                'metadata' => ['default_alt' => 'Long', 'description' => 'Long name'],
            ]], 'local::filesystem::media', '', 'zh_Hans_CN', $access, [],
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                ['text/plain'],
                1024,
                [],
                ['txt'],
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testOverlongNestedMetadataFailsBeforeStorageWrite(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mmu_meta_');
        self::assertIsString($path);
        file_put_contents($path, 'plain text');
        $access = self::accessContext();

        try {
            $assets = $this->createMock(FileAssetLibraryInterface::class);
            $assets->expects(self::never())->method('upload');
            $service = $this->createService($assets);
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('文件资源元数据内容或长度无效');
            $service->uploadFiles([[
                'name' => 'demo.txt',
                'tmp_name' => $path,
                'type' => 'text/plain',
                'error' => UPLOAD_ERR_OK,
                'metadata' => [
                    'default_alt' => str_repeat('a', 513),
                    'description' => 'Description',
                ],
            ]], 'local::filesystem::media', '', 'zh_Hans_CN', $access, [],
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                ['text/plain'],
                1024,
                [],
                ['txt'],
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testPerFileMetadataCountMismatchFailsBeforeStorageWrite(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mmu_bind_');
        self::assertIsString($path);
        file_put_contents($path, 'plain text');
        $access = self::accessContext();

        try {
            $assets = $this->createMock(FileAssetLibraryInterface::class);
            $assets->expects(self::never())->method('upload');
            $service = $this->createService($assets);
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('逐文件元数据数量与上传文件数量不一致');
            $service->uploadFiles([[
                'name' => 'demo.txt',
                'tmp_name' => $path,
                'type' => 'text/plain',
                'error' => UPLOAD_ERR_OK,
            ]], 'local::filesystem::media', '', 'zh_Hans_CN', $access, [],
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                ['text/plain'],
                1024,
                [
                    ['default_alt' => 'One', 'description' => 'One'],
                    ['default_alt' => 'Two', 'description' => 'Two'],
                ],
                ['txt'],
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private static function accessContext(): FileAccessContext
    {
        return new FileAccessContext(
            ScopeIdentity::global(),
            'zh_Hans_CN',
            7,
            [],
            'media_manager',
        );
    }

    private function createService(FileAssetLibraryInterface $assets): MediaAssetUploadService
    {
        return new MediaAssetUploadService(
            $assets,
            new StorageRequestResourceFactory(new StorageRequestResourceRegistry()),
        );
    }
}
