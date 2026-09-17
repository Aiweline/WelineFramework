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
        self::assertFalse(VideoEmbedResolver::isTrustedEmbedSrc('https://evil.example/embed/x'));
    }
}
