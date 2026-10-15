<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

/**
 * 企业邮箱外投 smarthost 密码密封（本机密钥，不明文落库）。
 */
final class MailOutboundRelaySecretService
{
    private const KEY_FILE = '/etc/weline/mail-admin.key';
    private const FALLBACK_KEY_REL = 'var/mail/outbound-relay.key';

    public function seal(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            throw new \InvalidArgumentException('外投密码不能为空。');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key());

        return base64_encode($sealed);
    }

    public function open(string $sealedB64): string
    {
        $sealed = base64_decode(trim($sealedB64), true);
        if (!is_string($sealed) || strlen($sealed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('外投密码密封无效。');
        }
        $nonce = substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if (!is_string($plain) || $plain === '') {
            throw new \RuntimeException('外投密码无法解密。');
        }

        return $plain;
    }

    private function key(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('PHP Sodium 扩展不可用。');
        }
        if (is_readable(self::KEY_FILE)) {
            $raw = file_get_contents(self::KEY_FILE);
            $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        }
        $path = rtrim((string)BP, '/\\') . '/' . self::FALLBACK_KEY_REL;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建外投密钥目录。');
        }
        if (!is_readable($path)) {
            $generated = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            if (@file_put_contents($path, base64_encode($generated)) === false) {
                throw new \RuntimeException('无法写入外投密钥文件。');
            }
            @chmod($path, 0600);

            return $generated;
        }
        $raw = file_get_contents($path);
        $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('外投密钥无效。');
        }

        return $key;
    }
}
