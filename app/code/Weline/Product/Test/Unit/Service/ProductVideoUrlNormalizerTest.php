<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductVideoUrlNormalizer;

final class ProductVideoUrlNormalizerTest extends TestCase
{
    private ProductVideoUrlNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ProductVideoUrlNormalizer();
    }

    public function testNormalizesYouTubeWatchUrl(): void
    {
        $result = $this->normalizer->normalize('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        self::assertSame('youtube', $result['provider']);
        self::assertSame('dQw4w9WgXcQ', $result['provider_id']);
        self::assertSame('video://youtube/dQw4w9WgXcQ', $result['path']);
        self::assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $result['embed_url']);
        self::assertStringContainsString('hqdefault.jpg', $result['poster_url']);
    }

    public function testNormalizesYouTubeShortAndEmbedIframe(): void
    {
        $short = $this->normalizer->normalize('https://youtu.be/dQw4w9WgXcQ');
        self::assertSame('dQw4w9WgXcQ', $short['provider_id']);

        $iframe = $this->normalizer->normalize(
            '<iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube"></iframe>',
        );
        self::assertSame('youtube', $iframe['provider']);
        self::assertSame('dQw4w9WgXcQ', $iframe['provider_id']);
    }

    public function testNormalizesVimeoAndDirectFile(): void
    {
        $vimeo = $this->normalizer->normalize('https://vimeo.com/123456789');
        self::assertSame('vimeo', $vimeo['provider']);
        self::assertSame('123456789', $vimeo['provider_id']);
        self::assertSame('https://player.vimeo.com/video/123456789', $vimeo['embed_url']);

        $file = $this->normalizer->normalize('https://cdn.example.com/media/demo.mp4');
        self::assertSame('file', $file['provider']);
        self::assertSame('video/mp4', $file['mime_type']);
        self::assertSame('https://cdn.example.com/media/demo.mp4', $file['embed_url']);
    }

    public function testRejectsUnsupportedProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('product_media_video_provider_unsupported');
        $this->normalizer->normalize('https://example.com/watch/abc');
    }
}
