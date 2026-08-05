<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\MediaManager\Extends\Module\Weline_Framework\Query\MediaManagerAssetQueryProvider;
use Weline\MediaManager\Service\MediaStorageService;

final class MediaManagerAssetQueryProviderTest extends TestCase
{
    public function testReadAssetUsesByteDetectedMimeInsteadOfFilenameMime(): void
    {
        $bytes = (string)\base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        $storage = $this->createMock(MediaStorageService::class);
        $storage->expects(self::once())
            ->method('readFileBytes')
            ->with('mm_misnamed_image')
            ->willReturn([
                'relative' => 'library/misnamed.jpg',
                'bytes' => $bytes,
                'mime' => 'image/jpeg',
                'hash' => 'mm_misnamed_image',
            ]);

        $result = (new MediaManagerAssetQueryProvider($storage))->execute(
            'readAsset',
            ['hash' => 'mm_misnamed_image'],
        );

        self::assertSame('image/png', $result['mime_type']);
        self::assertSame(\hash('sha256', $bytes), $result['sha256']);
        self::assertSame(\strlen($bytes), $result['size']);
        self::assertSame('/pub/media/library/misnamed.jpg', $result['public_url']);
    }
}
