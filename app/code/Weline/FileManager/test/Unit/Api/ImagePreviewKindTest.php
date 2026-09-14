<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Image;

final class ImagePreviewKindTest extends TestCase
{
    public function testAudioPathsDoNotUseImageResizeEndpoint(): void
    {
        self::assertSame('audio', Image::previewKindForPath('store-music/demo.m4a'));
        self::assertSame('audio', Image::previewKindForPath('/media/store-music/a.mp3'));
        self::assertSame('image', Image::previewKindForPath('logo/a.png'));

        $items = Image::processImagesValuePreviewData(
            '/media/store-music/高山流水.m4a,/media/store-music-e2e/beep.mp3,catalog/x.jpg',
            64,
            64,
        );
        self::assertCount(3, $items);
        self::assertSame('audio', $items[0]['kind']);
        self::assertFalse($items[0]['is_image']);
        self::assertStringStartsWith('/media/', $items[0]['url']);
        self::assertStringNotContainsString('/media/image/', $items[0]['url']);
        self::assertSame('audio', $items[1]['kind']);
        self::assertSame('image', $items[2]['kind']);
        self::assertTrue($items[2]['is_image']);
        self::assertStringContainsString('/media/image/', $items[2]['url']);

        self::assertStringStartsWith('/media/', Image::pathToMediaUrl('store-music/a.m4a', 64, 64));
        self::assertStringNotContainsString('/media/image/', Image::pathToMediaUrl('store-music/a.m4a', 64, 64));
    }
}
