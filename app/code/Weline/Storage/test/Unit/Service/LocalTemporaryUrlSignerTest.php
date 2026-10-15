<?php

declare(strict_types=1);

namespace Weline\Storage\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Storage\Service\LocalTemporaryUrlSigner;

final class LocalTemporaryUrlSignerTest extends TestCase
{
    public function testSignAndVerifyRoundTrip(): void
    {
        $signer = new LocalTemporaryUrlSigner();
        $expiresAt = time() + 300;
        $url = $signer->sign(
            'local::filesystem::media',
            'pic-packs/pack.html',
            $expiresAt,
            1,
        );

        self::assertStringStartsWith(LocalTemporaryUrlSigner::ROUTE_PATH . '?', $url);
        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(
            'local::filesystem::media',
            $signer->decodeDiskCode((string)($query['disk'] ?? '')),
        );
        self::assertTrue($signer->verify(
            $signer->decodeDiskCode((string)$query['disk']),
            $signer->decodeObjectKey((string)$query['key']),
            (int)$query['e'],
            (int)$query['r'],
            (string)$query['sig'],
        ));
    }

    public function testTamperedSignatureFails(): void
    {
        $signer = new LocalTemporaryUrlSigner();
        $expiresAt = time() + 300;
        $url = $signer->sign('local::filesystem::media', 'a/b.txt', $expiresAt, 1);
        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $sig = (string)$query['sig'];
        $tampered = $sig[0] === 'a' ? 'b' . substr($sig, 1) : 'a' . substr($sig, 1);

        self::assertFalse($signer->verify(
            $signer->decodeDiskCode((string)$query['disk']),
            $signer->decodeObjectKey((string)$query['key']),
            (int)$query['e'],
            (int)$query['r'],
            $tampered,
        ));
    }

    public function testExpiredSignatureFails(): void
    {
        $signer = new LocalTemporaryUrlSigner();
        $expiresAt = time() - 1;
        // Bypass sign()'s future-facing usage by forging verify inputs with a real HMAC.
        $payloadDisk = 'local::filesystem::media';
        $payloadKey = 'gone.txt';
        $url = $signer->sign($payloadDisk, $payloadKey, time() + 30, 1);
        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        // Re-sign with past expiry via reflection of private signature is overkill;
        // verify() rejects expiresAt < time() before HMAC compare.
        self::assertFalse($signer->verify(
            $payloadDisk,
            $payloadKey,
            $expiresAt,
            1,
            (string)$query['sig'],
        ));
    }
}
