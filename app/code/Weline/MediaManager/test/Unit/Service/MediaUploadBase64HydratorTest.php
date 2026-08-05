<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\MediaManager\Service\MediaUploadBase64Hydrator;

final class MediaUploadBase64HydratorTest extends TestCase
{
    private MediaUploadBase64Hydrator $hydrator;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hydrator = new MediaUploadBase64Hydrator();
        unset($_FILES['upload']);
    }

    protected function tearDown(): void
    {
        $this->hydrator->cleanup($this->tmpFiles);
        unset($_FILES['upload']);
        parent::tearDown();
    }

    public function testHydrateBuildsFilesUploadArrayFromBase64(): void
    {
        $payload = 'hello-media';
        $this->tmpFiles = $this->hydrator->hydrate([
            'cmd' => 'upload',
            'upload_base64' => [[
                'name' => 'demo.txt',
                'type' => 'text/plain',
                'data' => base64_encode($payload),
            ]],
        ]);

        self::assertCount(1, $this->tmpFiles);
        self::assertFileExists($this->tmpFiles[0]);
        self::assertSame($payload, (string)file_get_contents($this->tmpFiles[0]));
        self::assertSame(['demo.txt'], $_FILES['upload']['name']);
        self::assertSame(['text/plain'], $_FILES['upload']['type']);
        self::assertSame([UPLOAD_ERR_OK], $_FILES['upload']['error']);
        self::assertSame([strlen($payload)], $_FILES['upload']['size']);
    }

    public function testHydrateReturnsEmptyWhenNoUploads(): void
    {
        $created = $this->hydrator->hydrate(['cmd' => 'open']);
        self::assertSame([], $created);
        self::assertArrayNotHasKey('upload', $_FILES);
    }

    public function testHydrateRejectsOversizedPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hydrator->hydrate([
            'upload_base64' => [[
                'name' => 'big.bin',
                'type' => 'application/octet-stream',
                'data' => base64_encode(str_repeat('a', 20 * 1024 * 1024 + 1)),
            ]],
        ]);
    }
}
