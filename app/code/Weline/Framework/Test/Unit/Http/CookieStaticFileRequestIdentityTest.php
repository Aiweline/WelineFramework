<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Cookie;

final class CookieStaticFileRequestIdentityTest extends TestCase
{
    public function testStaticFileClearsInboundCookieSuperglobalAndServer(): void
    {
        $_COOKIE = ['session' => 'abc', 'consent' => '1'];
        WelineEnv::setServer('HTTP_COOKIE', 'session=abc; consent=1', 'test');
        WelineEnv::setServer('HTTP_AUTHORIZATION', 'Bearer secret', 'test');

        Cookie::static_file();

        self::assertSame([], $_COOKIE);
        self::assertSame('', (string)WelineEnv::server('HTTP_COOKIE', 'missing'));
        self::assertSame('', (string)WelineEnv::server('HTTP_AUTHORIZATION', 'missing'));
    }
}
