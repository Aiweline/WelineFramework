<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ContentEncodingNegotiator;

final class WlsCompressFormattedHttpResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/bin/worker_http_message.php';
    }

    public function testPrefersBrotliWhenClientAcceptsIt(): void
    {
        $body = \str_repeat('function welineApiWorker(){return 1;}' . "\n", 80);
        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/javascript; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;

        $compressed = wlsCompressFormattedHttpResponse($response, 'gzip, deflate, br');
        $headerEnd = \strpos($compressed, "\r\n\r\n");
        self::assertNotFalse($headerEnd);
        $wire = \substr($compressed, $headerEnd + 4);
        self::assertLessThan(\strlen($body), \strlen($wire));
        self::assertStringContainsString('Vary:', $compressed);

        if (ContentEncodingNegotiator::brotliAvailable()) {
            self::assertStringContainsString("Content-Encoding: br\r\n", $compressed);
            self::assertSame($body, \brotli_uncompress($wire));
            return;
        }

        self::assertStringContainsString("Content-Encoding: gzip\r\n", $compressed);
        self::assertSame($body, \gzdecode($wire));
    }

    public function testGzipWhenBrotliNotAccepted(): void
    {
        $body = \str_repeat('function welineApiWorker(){return 1;}' . "\n", 80);
        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/javascript; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;

        $compressed = wlsCompressFormattedHttpResponse($response, 'gzip');
        self::assertStringContainsString("Content-Encoding: gzip\r\n", $compressed);
        $headerEnd = \strpos($compressed, "\r\n\r\n");
        self::assertNotFalse($headerEnd);
        self::assertSame($body, \gzdecode(\substr($compressed, $headerEnd + 4)));
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
