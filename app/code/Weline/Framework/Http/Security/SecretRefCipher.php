<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

use Weline\Framework\App\Env;

/**
 * secret_ref 封装（TASK-P1D-002）：写路径密封，读路径仅服务端揭示；API 永不回传明文。
 *
 * 格式：
 * - `secret_ref:v1:`  + base64url(nonce||ciphertext) —— libsodium secretbox
 * - `secret_ref:v1o:` + base64url(iv||tag||ciphertext) —— OpenSSL AES-256-GCM（无 sodium 时）
 */
final class SecretRefCipher
{
    public const PREFIX = 'secret_ref:v1:';
    public const PREFIX_OPENSSL = 'secret_ref:v1o:';

    /** @var string 仅 ENV_TEST/DEV 缺键时使用；生产禁止固定密钥 */
    private const DEV_FALLBACK_KEY = 'weline-secret-ref-dev-only';

    private const OPENSSL_IV_BYTES = 12;
    private const OPENSSL_TAG_BYTES = 16;

    public static function isRef(string $value): bool
    {
        return \str_starts_with($value, self::PREFIX)
            || \str_starts_with($value, self::PREFIX_OPENSSL);
    }

    public static function seal(string $plaintext): string
    {
        $key = self::masterKey();
        if (self::sodiumAvailable()) {
            $nonce = \random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = \sodium_crypto_secretbox($plaintext, $nonce, $key);

            return self::PREFIX . self::b64($nonce . $cipher);
        }

        $iv = \random_bytes(self::OPENSSL_IV_BYTES);
        $tag = '';
        $cipher = \openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::OPENSSL_TAG_BYTES
        );
        if ($cipher === false || \strlen($tag) !== self::OPENSSL_TAG_BYTES) {
            throw new \RuntimeException('secret_ref_encrypt_failed');
        }

        return self::PREFIX_OPENSSL . self::b64($iv . $tag . $cipher);
    }

    public static function reveal(string $refOrPlain): string
    {
        if (\str_starts_with($refOrPlain, self::PREFIX_OPENSSL)) {
            return self::revealOpenssl(\substr($refOrPlain, \strlen(self::PREFIX_OPENSSL)));
        }
        if (!\str_starts_with($refOrPlain, self::PREFIX)) {
            return $refOrPlain;
        }
        if (!self::sodiumAvailable()) {
            throw new \RuntimeException('secret_ref_decrypt_failed');
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

    private static function revealOpenssl(string $payload): string
    {
        $raw = self::ub64($payload);
        $min = self::OPENSSL_IV_BYTES + self::OPENSSL_TAG_BYTES + 1;
        if ($raw === null || \strlen($raw) < $min) {
            throw new \RuntimeException('secret_ref_corrupt');
        }
        $iv = \substr($raw, 0, self::OPENSSL_IV_BYTES);
        $tag = \substr($raw, self::OPENSSL_IV_BYTES, self::OPENSSL_TAG_BYTES);
        $cipher = \substr($raw, self::OPENSSL_IV_BYTES + self::OPENSSL_TAG_BYTES);
        $plain = \openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            self::masterKey(),
            \OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new \RuntimeException('secret_ref_decrypt_failed');
        }

        return $plain;
    }

    private static function sodiumAvailable(): bool
    {
        return \extension_loaded('sodium')
            && \defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
            && \function_exists('sodium_crypto_secretbox');
    }

    private static function masterKey(): string
    {
        $configured = self::readConfiguredKey();
        if ($configured === '') {
            if ((\defined('ENV_TEST') && ENV_TEST === true)
                || (\defined('DEV') && DEV === true)) {
                $configured = self::DEV_FALLBACK_KEY;
            } else {
                $configured = self::ensureProductionMasterKey();
            }
        }

        // 32 bytes for secretbox / AES-256
        return \hash('sha256', $configured, true);
    }

    private static function readConfiguredKey(): string
    {
        try {
            return \trim((string)Env::get('security.secret_ref_key', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 生产缺键时一次性写入 env.php（高强度随机串）；并发下以磁盘最终值为准。
     */
    private static function ensureProductionMasterKey(): string
    {
        $lockPath = \defined('APP_ETC_PATH')
            ? APP_ETC_PATH . '.secret_ref_key.lock'
            : (\sys_get_temp_dir() . '/weline-secret-ref-key.lock');

        $lock = @\fopen($lockPath, 'c+');
        if (!\is_resource($lock)) {
            throw new \RuntimeException('secret_ref_key_missing');
        }

        try {
            if (!@\flock($lock, \LOCK_EX)) {
                throw new \RuntimeException('secret_ref_key_missing');
            }

            try {
                Env::getInstance()->reloadPersistentConfigFromDisk();
            } catch (\Throwable) {
            }

            $existing = self::readConfiguredKey();
            if ($existing !== '') {
                return $existing;
            }

            $generated = \bin2hex(\random_bytes(32));
            $ok = false;
            try {
                $ok = Env::set('security.secret_ref_key', $generated);
            } catch (\Throwable) {
                $ok = false;
            }
            if (!$ok) {
                throw new \RuntimeException('secret_ref_key_missing');
            }

            try {
                Env::getInstance()->reloadPersistentConfigFromDisk();
            } catch (\Throwable) {
            }

            $persisted = self::readConfiguredKey();
            if ($persisted === '') {
                throw new \RuntimeException('secret_ref_key_missing');
            }

            return $persisted;
        } finally {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
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
