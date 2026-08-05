<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Console\Http\Request;

final class ConsoleConcurrentRequestStatusTest extends TestCase
{
    public function testOnlySuccessfulAndRedirectStatusesCountAsSuccess(): void
    {
        self::assertTrue(Request::isSuccessfulHttpStatusCode(200));
        self::assertTrue(Request::isSuccessfulHttpStatusCode(204));
        self::assertTrue(Request::isSuccessfulHttpStatusCode(302));
        self::assertFalse(Request::isSuccessfulHttpStatusCode(0));
        self::assertFalse(Request::isSuccessfulHttpStatusCode(400));
        self::assertFalse(Request::isSuccessfulHttpStatusCode(500));
    }
}
