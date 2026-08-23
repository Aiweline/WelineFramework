<?php

declare(strict_types=1);

namespace Weline\Storage\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageObjectStat;
use Weline\Storage\Api\StorageDiskInterface;
use Weline\Storage\Api\StorageManagerInterface;
use Weline\Storage\Driver\LocalFilesystemDriver;
use Weline\Storage\Service\StorageDisk;
use Weline\Storage\Service\StorageDirectoryManager;
use Weline\Storage\Service\StorageRequestResourceRegistry;
use Weline\Storage\Url\LocalFilesystemUrlAdapter;

final class StorageDirectoryManagerTest extends TestCase
{
    private string $root;
    private StorageDirectoryManager $directories;
    private StorageDiskInterface $disk;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-storage-directory-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($this->root, 0755, true));
        $diskCode = 'local::filesystem::test';
        $snapshot = new StorageConfigSnapshot(
            $diskCode,
            1,
            ['visibility' => 'public'],
            \hash('sha256', $this->root),
        );
        $this->disk = new StorageDisk(
            $snapshot,
            new LocalFilesystemDriver($diskCode, $this->root, new StorageRequestResourceRegistry()),
            new LocalFilesystemUrlAdapter($diskCode, '/test-media'),
        );
        $manager = $this->createMock(StorageManagerInterface::class);
        $manager->method('disk')->willReturn($this->disk);
        $this->directories = new StorageDirectoryManager($manager);
    }

    protected function tearDown(): void
    {
        if (!\is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }
        \rmdir($this->root);
    }

    public function testDirectoryLifecycleUsesProviderAndPreservesContents(): void
    {
        self::assertTrue($this->directories->makeDirectory('local::filesystem::test', 'catalog/source'));
        $this->writeObject('catalog/source/readme.txt', 'kept');

        self::assertTrue($this->directories->move(
            'local::filesystem::test',
            'catalog/source',
            'catalog/renamed',
        ));
        self::assertSame('kept', $this->readObject('catalog/renamed/readme.txt'));
        self::assertFalse($this->disk->exists('catalog/source/readme.txt'));

        $entries = $this->directories->list('local::filesystem::test', 'catalog');
        self::assertSame(['catalog/renamed'], \array_column($entries, 'path'));
        self::assertSame(['directory'], \array_column($entries, 'type'));

        self::assertTrue($this->directories->delete('local::filesystem::test', 'catalog/renamed'));
        self::assertSame([], $this->directories->list('local::filesystem::test', 'catalog'));
    }

    public function testRootAndTraversalCannotReachProviderMutation(): void
    {
        try {
            $this->directories->delete('local::filesystem::test', '');
            self::fail('Root deletion must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertDirectoryExists($this->root);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->directories->makeDirectory('local::filesystem::test', '../outside');
    }

    public function testRenameCannotOverwriteExistingPath(): void
    {
        self::assertTrue($this->directories->makeDirectory('local::filesystem::test', 'from'));
        self::assertTrue($this->directories->makeDirectory('local::filesystem::test', 'to'));
        self::assertFalse($this->directories->move('local::filesystem::test', 'from', 'to'));
        self::assertDirectoryExists($this->root . DIRECTORY_SEPARATOR . 'from');
        self::assertDirectoryExists($this->root . DIRECTORY_SEPARATOR . 'to');
    }

    public function testCloudDirectoryMovePreservesEmptyDirectoryMarkers(): void
    {
        $created = [];
        $copied = [];
        $deleted = [];
        $disk = $this->createMock(StorageDiskInterface::class);
        $disk->method('diskCode')->willReturn('oss::aliyun::test');
        $disk->method('list')->willReturnCallback(
            static function (string $directory, bool $recursive): array {
                if ($directory === '' && !$recursive) {
                    return [new StorageObjectStat(
                        new StorageObjectReference('oss::aliyun::test', 'source'),
                        0,
                        'application/x-directory',
                        null,
                        null,
                        ['type' => 'directory'],
                    )];
                }
                if ($directory === 'source' && $recursive) {
                    return [
                        new StorageObjectStat(
                            new StorageObjectReference('oss::aliyun::test', 'source/empty'),
                            0,
                            'application/x-directory',
                            null,
                            null,
                            ['type' => 'directory'],
                        ),
                        new StorageObjectStat(
                            new StorageObjectReference('oss::aliyun::test', 'source/nested/readme.txt'),
                            4,
                            'text/plain',
                        ),
                    ];
                }

                return [];
            },
        );
        $disk->method('makeDirectory')->willReturnCallback(
            static function (string $path) use (&$created): bool {
                $created[] = $path;
                return true;
            },
        );
        $disk->method('copy')->willReturnCallback(
            static function (string $from, string $to) use (&$copied): StorageObjectReference {
                $copied[] = [$from, $to];
                return new StorageObjectReference('oss::aliyun::test', $to);
            },
        );
        $disk->method('deleteDirectory')->willReturnCallback(
            static function (string $path) use (&$deleted): bool {
                $deleted[] = $path;
                return true;
            },
        );
        $manager = $this->createMock(StorageManagerInterface::class);
        $manager->method('disk')->willReturn($disk);

        self::assertTrue((new StorageDirectoryManager($manager))->move(
            'oss::aliyun::test',
            'source',
            'target',
        ));
        self::assertSame(['target', 'target/empty'], $created);
        self::assertSame([['source/nested/readme.txt', 'target/nested/readme.txt']], $copied);
        self::assertSame(['source'], $deleted);
    }

    private function writeObject(string $objectKey, string $contents): void
    {
        $source = \fopen('php://temp', 'w+b');
        self::assertIsResource($source);
        try {
            self::assertSame(\strlen($contents), \fwrite($source, $contents));
            \rewind($source);
            $this->disk->writeStream($objectKey, $source);
        } finally {
            \fclose($source);
        }
    }

    private function readObject(string $objectKey): string
    {
        $handle = $this->disk->openRead($objectKey);
        $contents = '';
        try {
            while (!$handle->eof()) {
                $contents .= $handle->read(1024);
            }
        } finally {
            $handle->close();
        }

        return $contents;
    }
}
