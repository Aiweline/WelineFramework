<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\MediaManager\Service\MediaUploadBase64Hydrator;
use Weline\Storage\Service\StorageRequestResourceFactory;
use Weline\Storage\Service\StorageRequestResourceRegistry;
use Weline\Storage\Service\StorageTemporaryFile;

final class MediaUploadBase64HydratorTest extends TestCase
{
    private MediaUploadBase64Hydrator $hydrator;

    /** @var list<StorageTemporaryFile|string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hydrator = new MediaUploadBase64Hydrator(
            new StorageRequestResourceFactory(new StorageRequestResourceRegistry()),
        );
    }

    protected function tearDown(): void
    {
        $this->hydrator->cleanup($this->tmpFiles);
        parent::tearDown();
    }

    public function testHydrateReturnsExplicitFileRecordsWithoutMutatingFilesSuperglobal(): void
    {
        $previousFiles = $_FILES;
        $_FILES = ['sentinel' => ['name' => 'keep.txt']];
        try {
            $payload = 'hello-media';
            $result = $this->hydrator->hydrate([
                'cmd' => 'upload',
                'upload_base64' => [[
                    'name' => 'demo.txt',
                    'type' => 'text/plain',
                    'data' => base64_encode($payload),
                    'metadata' => [
                        'display_name' => 'Demo',
                        'default_alt' => 'Demo alt',
                        'description' => 'Demo description',
                    ],
                ]],
            ]);
            $this->tmpFiles = $result['temporary_resources'];

            self::assertCount(1, $result['files']);
            self::assertCount(1, $this->tmpFiles);
            self::assertFileExists($result['temporary_paths'][0]);
            self::assertSame($payload, (string)file_get_contents($result['temporary_paths'][0]));
            self::assertSame('demo.txt', $result['files'][0]['name']);
            self::assertSame('text/plain', $result['files'][0]['type']);
            self::assertSame(UPLOAD_ERR_OK, $result['files'][0]['error']);
            self::assertSame(strlen($payload), $result['files'][0]['size']);
            self::assertSame('Demo alt', $result['files'][0]['metadata']['default_alt']);
            self::assertSame(['sentinel' => ['name' => 'keep.txt']], $_FILES);
        } finally {
            $_FILES = $previousFiles;
        }
    }

    public function testHydrateReturnsEmptyTypedBatchWhenNoUploads(): void
    {
        self::assertSame(
            ['files' => [], 'temporary_paths' => [], 'temporary_resources' => []],
            $this->hydrator->hydrate(['cmd' => 'open']),
        );
    }

    public function testCompatibilityBridgeRetainsItsBoundedAggregateLimit(): void
    {
        self::assertSame(1024 * 1024, MediaUploadBase64Hydrator::MAX_BYTES);
    }

    public function testHydrateRejectsInvalidAndAggregateOversizedPayloads(): void
    {
        try {
            $this->hydrator->hydrate(['upload_base64' => [['name' => 'bad.txt', 'data' => '***']]]);
            self::fail('Invalid base64 must fail closed.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(\InvalidArgumentException::class);
        $chunk = str_repeat('a', intdiv(MediaUploadBase64Hydrator::MAX_BYTES, 2) + 1);
        $this->hydrator->hydrate(['upload_base64' => [
            ['name' => 'one.bin', 'data' => base64_encode($chunk)],
            ['name' => 'two.bin', 'data' => base64_encode($chunk)],
        ]]);
    }

    public function testHydrateRejectsNestedMetadataWithNonScalarValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('文件资源元数据格式无效');

        $this->hydrator->hydrate(['upload_base64' => [[
            'name' => 'demo.txt',
            'data' => base64_encode('demo'),
            'metadata' => ['default_alt' => ['not', 'text']],
        ]]]);
    }

    public function testHydrateRejectsAssociativeUploadCollections(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('上传文件载荷无效');

        $this->hydrator->hydrate(['upload_base64' => [
            'unexpected-key' => [
                'name' => 'demo.txt',
                'data' => base64_encode('demo'),
            ],
        ]]);
    }

    public function testHydrateAcceptsAnEmptyFilePayload(): void
    {
        $result = $this->hydrator->hydrate(['upload_base64' => [[
            'name' => 'empty.txt',
            'type' => 'text/plain',
            'data' => '',
        ]]]);
        $this->tmpFiles = $result['temporary_resources'];

        self::assertCount(1, $result['files']);
        self::assertSame(0, $result['files'][0]['size']);
        self::assertSame('', (string)file_get_contents($result['files'][0]['tmp_name']));
    }

    public function testHydrateRejectsNestedFileFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('上传文件载荷无效');

        $this->hydrator->hydrate(['upload_base64' => [[
            'name' => 'demo.txt',
            'data' => ['not', 'base64'],
        ]]]);
    }
}
