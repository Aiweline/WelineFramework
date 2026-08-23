<?php

declare(strict_types=1);

namespace Weline\Storage\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Storage\Model\StorageConfig;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\StorageDriverInterface;
use Weline\Storage\Api\StorageUrlAdapterInterface;
use Weline\Storage\Extends\Module\Weline_Framework\Query\StorageAdminQueryProvider;
use Weline\Storage\Service\StorageDisk;
use Weline\Storage\Url\LocalFilesystemUrlAdapter;

final class StorageContractsTest extends TestCase
{
    public function testStorageConfigRevisionSchemaUsesAPostCheckpointModuleVersion(): void
    {
        $schema = (new SchemaParser())->parse(StorageConfig::class);
        self::assertNotNull($schema);
        self::assertContains(
            StorageConfig::schema_fields_CONFIG_REVISION,
            array_column(array_map('get_object_vars', $schema->columns), 'name'),
        );

        $module = require BP . 'app/code/Weline/Storage/etc/module.php';
        self::assertTrue(version_compare((string)$module['version'], '1.2.1', '>='));
    }

    public function testDiskCodeUsesCanonicalThreeSegmentIdentity(): void
    {
        $code = StorageDiskCode::parse(' OSS::Aliyun::Media_Public ');

        self::assertSame('oss', $code->type);
        self::assertSame('aliyun', $code->vendor);
        self::assertSame('media_public', $code->instance);
        self::assertSame('oss::aliyun', $code->providerCode());
        self::assertSame('oss::aliyun::media_public', (string)$code);
    }

    /** @dataProvider invalidDiskCodes */
    public function testDiskCodeRejectsLegacyOrMalformedIdentities(string $diskCode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StorageDiskCode::parse($diskCode);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDiskCodes(): iterable
    {
        yield 'legacy one segment' => ['oss_public'];
        yield 'provider only' => ['oss::aliyun'];
        yield 'four segments' => ['oss::aliyun::media::public'];
        yield 'unsafe segment' => ['oss::aliyun::../media'];
    }

    public function testObjectReferenceRejectsTraversalAndAbsoluteKeys(): void
    {
        foreach (['../secret', 'catalog/../secret', '/absolute.jpg', 'catalog//image.jpg'] as $key) {
            try {
                new StorageObjectReference('local::filesystem::media', $key);
                self::fail('Unsafe object key was accepted: ' . $key);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLocalAdapterEncodesEachPathSegmentAndFailsClosedForTemporaryUrls(): void
    {
        $adapter = new LocalFilesystemUrlAdapter(
            'local::filesystem::media',
            'https://cdn.example.test/media',
        );
        $object = new StorageObjectReference(
            'local::filesystem::media',
            '目录/空 格.jpg',
        );

        $resolved = $adapter->publicUrl($object, new StorageUrlOptions());
        self::assertSame(
            'https://cdn.example.test/media/%E7%9B%AE%E5%BD%95/%E7%A9%BA%20%E6%A0%BC.jpg',
            $resolved->url,
        );
        self::assertTrue($resolved->cacheable);

        $this->expectException(\RuntimeException::class);
        $adapter->temporaryUrl(
            $object,
            new StorageUrlOptions(StorageUrlOptions::KIND_TEMPORARY, 300),
        );
    }

    public function testResolvedTemporaryUrlMustExpireAndMustNotBeSharedCacheable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResolvedStorageUrl(
            'https://private.example.test/file?signature=redacted',
            StorageUrlOptions::KIND_TEMPORARY,
            true,
            time() + 300,
        );
    }

    public function testStorageDiskRejectsAdapterKindMismatch(): void
    {
        $snapshot = new StorageConfigSnapshot(
            'local::filesystem::media',
            7,
            ['visibility' => 'public'],
            hash('sha256', 'storage-contract-test'),
        );
        $driver = $this->createMock(StorageDriverInterface::class);
        $urls = $this->createMock(StorageUrlAdapterInterface::class);
        $urls->expects(self::once())
            ->method('temporaryUrl')
            ->willReturn(new ResolvedStorageUrl(
                'https://cdn.example.test/media/image.jpg',
                StorageUrlOptions::KIND_PUBLIC,
                true,
            ));
        $disk = new StorageDisk($snapshot, $driver, $urls);

        $this->expectException(\RuntimeException::class);
        $disk->resolveUrl(
            'image.jpg',
            new StorageUrlOptions(StorageUrlOptions::KIND_TEMPORARY, 300),
        );
    }

    public function testRuntimeDiagnosticsAreBackendReadOnlyAndContainNoStorageIdentity(): void
    {
        $provider = new StorageAdminQueryProvider();
        $operations = [];
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            $operations[$operation['name']] = $operation;
        }

        $descriptor = $operations['runtimeDiagnostics'];
        self::assertTrue($descriptor['frontend']);
        self::assertTrue($descriptor['backend']);
        self::assertSame('backend', $descriptor['auth']);
        self::assertSame('read', $descriptor['mode']);
        self::assertFalse($descriptor['graph']);

        $result = $provider->execute('runtimeDiagnostics');
        self::assertTrue($result['success']);
        self::assertArrayHasKey('active_resource_handles', $result['diagnostics']);
        self::assertArrayHasKey('uncleaned_at_last_reset', $result['diagnostics']);
        self::assertDoesNotMatchRegularExpression(
            '/object[_ -]?(?:key|path)|secret|signature|signed[_ -]?url/i',
            json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
