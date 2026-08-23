<?php

declare(strict_types=1);

namespace Weline\StorageOss\Test\Unit\Url;

use PHPUnit\Framework\TestCase;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\StorageOss\Service\AliyunOssClientFactory;
use Weline\StorageOss\Url\AliyunOssUrlAdapter;

final class AliyunOssUrlAdapterTest extends TestCase
{
    public function testPublicAndImageVariantUrlsAreOwnedByTheDiskAdapter(): void
    {
        $adapter = $this->adapter([
            'visibility' => 'public',
            'cdn_domain' => 'https://cdn.example.test/assets',
        ]);
        $object = new StorageObjectReference(
            'oss::aliyun::media_public',
            'catalog/空 格.jpg',
        );

        $public = $adapter->publicUrl($object, new StorageUrlOptions());
        self::assertSame(
            'https://cdn.example.test/assets/media/catalog/%E7%A9%BA%20%E6%A0%BC.jpg',
            $public->url,
        );
        self::assertTrue($public->cacheable);

        $variant = $adapter->imageVariantUrl(
            $object,
            new StorageUrlOptions(
                StorageUrlOptions::KIND_IMAGE_VARIANT,
                3600,
                480,
                320,
                'webp',
                'cover',
            ),
        );
        self::assertSame(StorageUrlOptions::KIND_IMAGE_VARIANT, $variant->kind);
        self::assertTrue($variant->cacheable);
        self::assertStringContainsString(
            'x-oss-process=image%2Fresize%2Cm_fill%2Cw_480%2Ch_320%2Fimage%2Fformat%2Cwebp',
            $variant->url,
        );
    }

    public function testPrivateDiskFailsClosedForPublicUrl(): void
    {
        $adapter = $this->adapter(['visibility' => 'private']);

        $this->expectException(\RuntimeException::class);
        $adapter->publicUrl(
            new StorageObjectReference('oss::aliyun::media_public', 'private.jpg'),
            new StorageUrlOptions(),
        );
    }

    public function testAdapterRejectsObjectFromAnotherDisk(): void
    {
        $adapter = $this->adapter(['visibility' => 'public']);

        $this->expectException(\InvalidArgumentException::class);
        $adapter->publicUrl(
            new StorageObjectReference('local::filesystem::media', 'image.jpg'),
            new StorageUrlOptions(),
        );
    }

    public function testSnapshotDebugProjectionNeverExposesCredentials(): void
    {
        $snapshot = $this->snapshot(['access_key_secret' => 'must-not-leak']);

        self::assertSame([
            'disk_code' => 'oss::aliyun::media_public',
            'config_revision' => 9,
        ], $snapshot->__debugInfo());
        self::assertStringNotContainsString('must-not-leak', print_r($snapshot, true));
    }

    /** @param array<string,mixed> $override */
    private function adapter(array $override): AliyunOssUrlAdapter
    {
        $snapshot = $this->snapshot($override);
        $resources = $this->createMock(StorageRequestResourceFactoryInterface::class);
        return new AliyunOssUrlAdapter(
            $snapshot,
            new AliyunOssClientFactory($snapshot, $resources),
        );
    }

    /** @param array<string,mixed> $override */
    private function snapshot(array $override): StorageConfigSnapshot
    {
        return new StorageConfigSnapshot(
            'oss::aliyun::media_public',
            9,
            array_replace([
                'access_key_id' => 'test-id',
                'access_key_secret' => 'test-secret',
                'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
                'bucket' => 'example-bucket',
                'prefix' => 'media',
                'use_ssl' => true,
                'is_cname' => false,
                'visibility' => 'public',
            ], $override),
            hash('sha256', 'oss-namespace'),
        );
    }
}
