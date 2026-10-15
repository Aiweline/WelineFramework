<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcEngineIdentity;

final class FpcEngineIdentityTest extends TestCase
{
    public function testDetectsGuideControllerQueryIdentity(): void
    {
        self::assertTrue(FpcEngineIdentity::isGuideControllerUri('/guide?view=routes'));
        self::assertTrue(FpcEngineIdentity::isGuideControllerUri('https://x.test/guide?d=great-wall'));
        self::assertTrue(FpcEngineIdentity::isGuideControllerUri('/guide?rt=foo'));
        self::assertFalse(FpcEngineIdentity::isGuideControllerUri('/guide'));
        self::assertFalse(FpcEngineIdentity::isGuideControllerUri('/routes'));
        self::assertFalse(FpcEngineIdentity::isGuideControllerUri('/destinations/great-wall'));
    }

    public function testPreferVisitorUriSkipsGuideController(): void
    {
        self::assertSame(
            '/destinations/great-wall',
            FpcEngineIdentity::preferVisitorUri(
                '/guide?d=great-wall',
                '/destinations/great-wall',
                '/guide'
            )
        );
        self::assertSame(
            '/routes',
            FpcEngineIdentity::preferVisitorUri('/guide?view=routes', '/routes')
        );
    }
}
