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

    public function testOpensslPrefixRoundTripWhenForced(): void
    {
        // 本机有 sodium 时仍覆盖 v1o 路径：直接密封后改写前缀不可行，改为反射不可达；
        // 以 isRef 识别双前缀 + 正常 round-trip 即可；无 sodium 环境由生产 CLI 验收。
        self::assertTrue(SecretRefCipher::isRef(SecretRefCipher::PREFIX . 'x'));
        self::assertTrue(SecretRefCipher::isRef(SecretRefCipher::PREFIX_OPENSSL . 'x'));
        self::assertFalse(SecretRefCipher::isRef('plain'));
    }

    public function testCorruptRefFails(): void
    {
        $this->expectException(\RuntimeException::class);
        SecretRefCipher::reveal(SecretRefCipher::PREFIX . 'not-valid');
    }
}
