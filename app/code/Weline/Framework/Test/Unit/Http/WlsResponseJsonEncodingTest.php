<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\WlsResponse;

final class WlsResponseJsonEncodingTest extends TestCase
{
    public function testJsonResponseKeepsBodyWhenPayloadContainsInvalidUtf8(): void
    {
        $response = WlsResponse::json([
            'error' => true,
            'message' => "bad\xB1utf8",
        ], 400);

        $body = $response->getBody();

        self::assertNotSame('', $body);
        self::assertStringContainsString('"error":true', \str_replace(' ', '', $body));
        self::assertStringContainsString('"message"', $body);
        self::assertStringContainsString('Content-Length: ' . \strlen($body), $response->toHttpString(false));
    }
}
