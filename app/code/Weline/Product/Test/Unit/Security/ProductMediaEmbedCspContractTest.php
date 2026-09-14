<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Product\Extends\Module\Weline_Framework\Security\Csp\ProductMediaEmbedCsp;

final class ProductMediaEmbedCspContractTest extends TestCase
{
    public function testContributionDeclaresYoutubeAndVimeoHosts(): void
    {
        $directives = (new ProductMediaEmbedCsp())->contribution()->directives;
        self::assertContains('https://www.youtube.com', $directives['frame-src'] ?? []);
        self::assertContains('https://www.youtube-nocookie.com', $directives['frame-src'] ?? []);
        self::assertContains('https://player.vimeo.com', $directives['frame-src'] ?? []);
        self::assertContains('https://i.ytimg.com', $directives['img-src'] ?? []);
    }
}
