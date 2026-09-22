<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Extends\Module\Weline_Framework\Security\Csp\ThemeVideoEmbedCsp;

final class ThemeVideoEmbedCspContractTest extends TestCase
{
    public function testContributionDeclaresYoutubeAndVimeoHosts(): void
    {
        $directives = (new ThemeVideoEmbedCsp())->contribution()->directives;
        self::assertContains('https://www.youtube.com', $directives['frame-src'] ?? []);
        self::assertContains('https://www.youtube-nocookie.com', $directives['frame-src'] ?? []);
        self::assertContains('https://player.vimeo.com', $directives['frame-src'] ?? []);
        self::assertContains('https://i.ytimg.com', $directives['img-src'] ?? []);
    }

    public function testContributionDeclaresBilibiliHosts(): void
    {
        $directives = (new ThemeVideoEmbedCsp())->contribution()->directives;
        self::assertContains('https://player.bilibili.com', $directives['frame-src'] ?? []);
        self::assertContains('https://player.bilibili.com', $directives['connect-src'] ?? []);
        self::assertContains('https://i0.hdslb.com', $directives['img-src'] ?? []);
        self::assertContains('https://i1.hdslb.com', $directives['img-src'] ?? []);
        self::assertContains('https://i2.hdslb.com', $directives['img-src'] ?? []);
    }
}
