<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Security\SecretRefCipher;

final class SecretRefCipherTest extends TestCase
{
    public function testSealRevealRoundTrip(): void
    {
        $ref = SecretRefCipher::sealJson(['token' => 'super-secret', 'id' => 1]);
        self::assertTrue(SecretRefCipher::isRef($ref));
        self::assertStringNotContainsString('super-secret', $ref);
        $plain = SecretRefCipher::revealJson($ref);
        self::assertSame('super-secret', $plain['token']);
        self::assertSame(1, $plain['id']);
    }

    public function testCorruptRefFails(): void
    {
        $this->expectException(\RuntimeException::class);
        SecretRefCipher::reveal(SecretRefCipher::PREFIX . 'not-valid');
    }
}
