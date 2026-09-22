<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\VideoEmbedResolver;

final class VideoEmbedResolverTest extends TestCase
{
    public function testResolveYoutubeIdFromWatchUrl(): void
    {
        self::assertSame(
            'Xv6HAscPv24',
            VideoEmbedResolver::resolveYoutubeId('https://www.youtube.com/watch?v=Xv6HAscPv24')
        );
    }

    public function testResolveYoutubeIdFromShortAndEmbedUrls(): void
    {
        self::assertSame('Xv6HAscPv24', VideoEmbedResolver::resolveYoutubeId('https://youtu.be/Xv6HAscPv24'));
        self::assertSame('Xv6HAscPv24', VideoEmbedResolver::resolveYoutubeId('https://www.youtube.com/embed/Xv6HAscPv24'));
        self::assertSame('Xv6HAscPv24', VideoEmbedResolver::resolveYoutubeId('https://www.youtube.com/shorts/Xv6HAscPv24'));
    }

    public function testResolveYoutubeIdFromIframeEmbedCode(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/Xv6HAscPv24" width="560" height="315"></iframe>';
        self::assertSame('Xv6HAscPv24', VideoEmbedResolver::resolveYoutubeId($html));
    }

    public function testCoalesceSourceUrlFallsBackToBareEmbedCode(): void
    {
        self::assertSame(
            'https://www.youtube.com/watch?v=Xv6HAscPv24',
            VideoEmbedResolver::coalesceSourceUrl('', 'https://www.youtube.com/watch?v=Xv6HAscPv24')
        );
        self::assertSame(
            'https://www.youtube.com/watch?v=primary',
            VideoEmbedResolver::coalesceSourceUrl(
                'https://www.youtube.com/watch?v=primary',
                'https://www.youtube.com/watch?v=secondary'
            )
        );
        self::assertSame('', VideoEmbedResolver::coalesceSourceUrl('', '<iframe src="https://www.youtube.com/embed/x"></iframe>'));
    }

    public function testResolveVimeoId(): void
    {
        self::assertSame('76979871', VideoEmbedResolver::resolveVimeoId('https://vimeo.com/76979871'));
        self::assertSame('76979871', VideoEmbedResolver::resolveVimeoId('https://player.vimeo.com/video/76979871'));
    }

    public function testResolveYoutubeIdFromPlaylistWatchUrl(): void
    {
        self::assertSame(
            'CF_afGTGgUY',
            VideoEmbedResolver::resolveYoutubeId(
                'https://www.youtube.com/watch?v=CF_afGTGgUY&list=RDCF_afGTGgUY&start_radio=1'
            )
        );
    }

    public function testTrustedEmbedSrc(): void
    {
        self::assertTrue(VideoEmbedResolver::isTrustedEmbedSrc('https://www.youtube.com/embed/CF_afGTGgUY'));
        self::assertTrue(VideoEmbedResolver::isTrustedEmbedSrc('https://player.bilibili.com/player.html?bvid=BV1xx411c7mD'));
        self::assertFalse(VideoEmbedResolver::isTrustedEmbedSrc('https://evil.example/embed/x'));
        self::assertContains('player.bilibili.com', VideoEmbedResolver::trustedEmbedHosts());
    }

    public function testResolveBilibiliIdFromBvPageAndPlayerUrl(): void
    {
        self::assertSame(
            'BV1xx411c7mD',
            VideoEmbedResolver::resolveBilibiliId('https://www.bilibili.com/video/BV1xx411c7mD')
        );
        self::assertSame(
            'BV1xx411c7mD',
            VideoEmbedResolver::resolveBilibiliId('https://player.bilibili.com/player.html?bvid=BV1xx411c7mD&high_quality=1')
        );
    }

    public function testResolveBilibiliIdFromAvAndAid(): void
    {
        self::assertSame(
            'av170001',
            VideoEmbedResolver::resolveBilibiliId('https://www.bilibili.com/video/av170001')
        );
        self::assertSame(
            'av170001',
            VideoEmbedResolver::resolveBilibiliId('https://player.bilibili.com/player.html?aid=170001')
        );
    }

    public function testResolveBilibiliIdFromIframeEmbedCode(): void
    {
        $html = '<iframe src="https://player.bilibili.com/player.html?bvid=BV1GJ411x7h7&page=1" allowfullscreen></iframe>';
        self::assertSame('BV1GJ411x7h7', VideoEmbedResolver::resolveBilibiliId($html));
    }

    public function testBilibiliEmbedUrl(): void
    {
        self::assertSame(
            'https://player.bilibili.com/player.html?bvid=BV1xx411c7mD',
            VideoEmbedResolver::bilibiliEmbedUrl('BV1xx411c7mD')
        );
        self::assertSame(
            'https://player.bilibili.com/player.html?aid=170001',
            VideoEmbedResolver::bilibiliEmbedUrl('av170001')
        );
        self::assertSame(
            'https://player.bilibili.com/player.html?aid=170001',
            VideoEmbedResolver::bilibiliEmbedUrl('170001')
        );
        self::assertSame('', VideoEmbedResolver::bilibiliEmbedUrl('not-a-bili-id'));
    }
}
