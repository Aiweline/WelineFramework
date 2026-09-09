<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class WlsCompressFormattedHttpResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/bin/worker_http_message.php';
    }

    public function testGzipCompressesJavascriptStaticBody(): void
    {
        $body = \str_repeat('function welineApiWorker(){return 1;}' . "\n", 80);
        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/javascript; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;

        $compressed = wlsCompressFormattedHttpResponse($response, 'gzip, deflate, br');

        self::assertStringContainsString("Content-Encoding: gzip\r\n", $compressed);
        self::assertStringContainsString('Vary:', $compressed);
        $headerEnd = \strpos($compressed, "\r\n\r\n");
        self::assertNotFalse($headerEnd);
        $wire = \substr($compressed, $headerEnd + 4);
        self::assertLessThan(\strlen($body), \strlen($wire));
        self::assertSame($body, \gzdecode($wire));
    }

    public function testGzipSkipsPartialContentRange(): void
    {
        $body = \str_repeat('abcdefghij', 200);
        $response = "HTTP/1.1 206 Partial Content\r\n"
            . "Content-Type: text/javascript; charset=utf-8\r\n"
            . "Content-Range: bytes 0-1999/4000\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        $out = wlsCompressFormattedHttpResponse($response, 'gzip');
        self::assertSame($response, $out);
    }

    public function testMaybeCompressReadsAcceptEncodingFromRawRequest(): void
    {
        $body = \str_repeat('console.log("product-reviews");' . "\n", 100);
        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/javascript; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "\r\n"
            . $body;
        $raw = "GET /x.js HTTP/1.1\r\nHost: example.test\r\nAccept-Encoding: gzip\r\n\r\n";

        $compressed = wlsMaybeCompressStaticHttpResponse($response, $raw);
        self::assertStringContainsString('Content-Encoding: gzip', $compressed);

        $plain = wlsMaybeCompressStaticHttpResponse($response, "GET /x.js HTTP/1.1\r\nHost: example.test\r\n\r\n");
        self::assertSame($response, $plain);
    }
}
