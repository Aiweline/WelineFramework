<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;

final class UrlMalformedParseTest extends TestCase
{
    public function testMalformedUrlDoesNotThrowValueError(): void
    {
        self::assertSame([], Url::parse_url('https://127.0.0.1:bad/catalog/category'));
        self::assertSame('fallback', Url::parse_url('https://127.0.0.1:bad/catalog/category', 'path', 'fallback'));
    }
}
