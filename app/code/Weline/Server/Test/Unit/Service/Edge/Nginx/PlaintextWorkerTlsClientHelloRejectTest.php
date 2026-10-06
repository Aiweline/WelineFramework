<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\PlaintextWorkerTlsClientHelloReject;

final class PlaintextWorkerTlsClientHelloRejectTest extends TestCase
{
    public function testDetectsTlsClientHelloFirstByte(): void
    {
        self::assertTrue(PlaintextWorkerTlsClientHelloReject::isTlsHandshakePrefix("\x16\x03\x01\x00\x01"));
    }

    public function testHttpRequestIsNotTls(): void
    {
        self::assertFalse(PlaintextWorkerTlsClientHelloReject::isTlsHandshakePrefix("GET / HTTP/1.1\r\n"));
        self::assertFalse(PlaintextWorkerTlsClientHelloReject::isTlsHandshakePrefix(''));
    }
}
