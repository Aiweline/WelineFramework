<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

use Weline\Framework\App\Env;

/**
 * secret_ref 封装（TASK-P1D-002）：写路径密封，读路径仅服务端揭示；API 永不回传明文。
 *
 * 格式：`secret_ref:v1:{base64url(nonce||ciphertext)}`
 */
final class SecretRefCipher
{
    public const PREFIX = 'secret_ref:v1:';

    public static function isRef(string $value): bool
    {
        return \str_starts_with($value, self::PREFIX);
    }

    public static function seal(string $plaintext): string
    {
        $key = self::masterKey();
        $nonce = \random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = \sodium_crypto_secretbox($plaintext, $nonce, $key);

        return self::PREFIX . self::b64($nonce . $cipher);
    }

    public static function reveal(string $refOrPlain): string
    {
        if (!self::isRef($refOrPlain)) {
            return $refOrPlain;
        }
        $raw = self::ub64(\substr($refOrPlain, \strlen(self::PREFIX)));
        if ($raw === null || \strlen($raw) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
            throw new \RuntimeException('secret_ref_corrupt');
        }
        $nonce = \substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = \substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = \sodium_crypto_secretbox_open($cipher, $nonce, self::masterKey());
        if ($plain === false) {
            throw new \RuntimeException('secret_ref_decrypt_failed');
        }

        return $plain;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function sealJson(array $payload): string
    {
        return self::seal(\json_encode($payload, \JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * @return array<string, mixed>
     */
    public static function revealJson(string $refOrJson): array
    {
        $json = self::reveal($refOrJson);
        $data = \json_decode($json, true);

        return \is_array($data) ? $data : [];
    }

    private static function masterKey(): string
    {
        $configured = '';
        try {
            $configured = \trim((string)Env::get('security.secret_ref_key', ''));
        } catch (\Throwable) {
        }
        if ($configured === '') {
            if ((\defined('ENV_TEST') && ENV_TEST === true)
                || (\defined('DEV') && DEV === true)) {
                $configured = 'weline-secret-ref-dev-only';
            } else {
                throw new \RuntimeException('secret_ref_key_missing');
            }
        }
        // 32 bytes for secretbox
        return \hash('sha256', $configured, true);
    }

    private static function b64(string $bin): string
    {
        return \rtrim(\strtr(\base64_encode($bin), '+/', '-_'), '=');
    }

    private static function ub64(string $b64): ?string
    {
        $pad = 4 - (\strlen($b64) % 4);
        if ($pad < 4) {
            $b64 .= \str_repeat('=', $pad);
        }
        $bin = \base64_decode(\strtr($b64, '-_', '+/'), true);

        return $bin === false ? null : $bin;
    }
}
