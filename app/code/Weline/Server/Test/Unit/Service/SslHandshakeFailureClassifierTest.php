<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SslHandshakeFailureClassifier;

final class SslHandshakeFailureClassifierTest extends TestCase
{
    public function testUnknownCaIsClassifiedAsBenignCertificateTrustAbort(): void
    {
        $classification = SslHandshakeFailureClassifier::classify(
            '127.0.0.1:62230',
            778,
            'stream_socket_enable_crypto(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000418:SSL routines::tlsv1 alert unknown ca'
        );

        self::assertTrue($classification['benign']);
        self::assertSame('info', $classification['level']);
        self::assertStringContainsString('SSL 握手已中止:', $classification['message']);
        self::assertStringContainsString('客户端不信任当前证书链/CA', $classification['message']);
        self::assertStringContainsString('[benign]', $classification['message']);
    }

    public function testConnectionResetIsClassifiedAsBenignPeerAbort(): void
    {
        $classification = SslHandshakeFailureClassifier::classify(
            '192.168.1.10:51888',
            12,
            'stream_socket_enable_crypto(): Connection reset by peer'
        );

        self::assertTrue($classification['benign']);
        self::assertSame('info', $classification['level']);
        self::assertStringContainsString('客户端在握手阶段重置了连接', $classification['message']);
    }

    public function testUnexpectedHandshakeFailureRemainsWarning(): void
    {
        $classification = SslHandshakeFailureClassifier::classify(
            '127.0.0.1:62230',
            778,
            'stream_socket_enable_crypto(): SSL: no suitable key share'
        );

        self::assertFalse($classification['benign']);
        self::assertSame('warning', $classification['level']);
        self::assertStringContainsString('SSL 握手失败:', $classification['message']);
        self::assertStringNotContainsString('[benign]', $classification['message']);
    }
}
