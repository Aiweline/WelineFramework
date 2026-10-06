<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\PlaintextWorkerTlsClientHelloReject;
use Weline\Server\Service\Edge\Nginx\PlaintextWorkerTlsPublicOriginBounce;

final class PlaintextWorkerTlsPublicOriginBounceTest extends TestCase
{
    public function testTlsPrefixDetection(): void
    {
        self::assertTrue(PlaintextWorkerTlsPublicOriginBounce::isTlsHandshakePrefix("\x16\x03\x01\x00\x01"));
        self::assertFalse(PlaintextWorkerTlsPublicOriginBounce::isTlsHandshakePrefix("GET / HTTP/1.1\r\n"));
        // Legacy helper stays aligned.
        self::assertTrue(PlaintextWorkerTlsClientHelloReject::isTlsHandshakePrefix("\x16\x03\x01"));
    }

    public function testPreferredHostFromPublicOrigin(): void
    {
        self::assertSame(
            'p05113ef3.test.weline.com',
            PlaintextWorkerTlsPublicOriginBounce::preferredHostFromPublicOrigin('https://p05113ef3.test.weline.com'),
        );
    }

    public function testResolveCertificatePairForLocalMapHost(): void
    {
        $pair = PlaintextWorkerTlsPublicOriginBounce::resolveCertificatePair('p05113ef3.test.weline.com');
        if ($pair === null) {
            self::markTestSkipped('ssl_certificate_map has no local p05113ef3.test.weline.com entry');
        }
        self::assertFileExists($pair['local_cert']);
        self::assertFileExists($pair['local_pk']);
    }
}
