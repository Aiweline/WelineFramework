<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\App\Env;

/**
 * 账户/外投/Smtp 密码密封（项目级密钥优先；不明文落库）。
 *
 * seal 一律用项目钥；open 兼容链：项目钥 → /etc/weline/mail-admin.key → 旧 var/mail/outbound-relay.key；
 * 用旧钥解开时留下待重封密文（consumePendingReseal），不以 /etc 为首选 seal。
 */
final class MailOutboundRelaySecretService
{
    public const ENV_SECRETS_KEY = 'mail.secrets_key_base64';

    private const PROJECT_KEY_REL = 'var/mail/account-secrets.key';
    private const LEGACY_ETC_KEY = '/etc/weline/mail-admin.key';
    private const LEGACY_VAR_KEY_REL = 'var/mail/outbound-relay.key';

    private ?string $cachedProjectKey = null;

    private ?string $pendingReseal = null;

    public function seal(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            throw new \InvalidArgumentException('密码不能为空。');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = $nonce . sodium_crypto_secretbox($plain, $nonce, $this->projectKey());

        return base64_encode($sealed);
    }

    public function open(string $sealedB64): string
    {
        $this->pendingReseal = null;
        $sealed = base64_decode(trim($sealedB64), true);
        if (!is_string($sealed) || strlen($sealed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('密码密封无效。');
        }
        $nonce = substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $projectKey = $this->projectKey();
        $plain = $this->tryOpen($cipher, $nonce, $projectKey);
        if (is_string($plain) && $plain !== '') {
            return $plain;
        }

        foreach ($this->legacyKeys() as $legacyKey) {
            $plain = $this->tryOpen($cipher, $nonce, $legacyKey);
            if (!is_string($plain) || $plain === '') {
                continue;
            }
            try {
                $this->pendingReseal = $this->seal($plain);
            } catch (\Throwable) {
                $this->pendingReseal = null;
            }

            return $plain;
        }

        throw new \RuntimeException('密码无法解密。');
    }

    /**
     * open 用旧钥成功时的项目钥重封密文；调用方应写回库后清除。
     */
    public function consumePendingReseal(): ?string
    {
        $resealed = $this->pendingReseal;
        $this->pendingReseal = null;

        return $resealed !== null && $resealed !== '' ? $resealed : null;
    }

    private function tryOpen(string $cipher, string $nonce, string $key): ?string
    {
        try {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);

            return is_string($plain) && $plain !== '' ? $plain : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function legacyKeys(): array
    {
        $keys = [];
        if (is_readable(self::LEGACY_ETC_KEY)) {
            $decoded = $this->decodeKeyFile(self::LEGACY_ETC_KEY);
            if ($decoded !== null) {
                $keys[] = $decoded;
            }
        }
        $legacyVar = rtrim((string)BP, '/\\') . '/' . self::LEGACY_VAR_KEY_REL;
        if (is_readable($legacyVar)) {
            $decoded = $this->decodeKeyFile($legacyVar);
            if ($decoded !== null) {
                $keys[] = $decoded;
            }
        }

        return $keys;
    }

    private function projectKey(): string
    {
        if ($this->cachedProjectKey !== null) {
            return $this->cachedProjectKey;
        }
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('PHP Sodium 扩展不可用。');
        }

        $fromEnv = $this->decodeKeyMaterial((string)Env::get(self::ENV_SECRETS_KEY, ''));
        if ($fromEnv !== null) {
            return $this->cachedProjectKey = $fromEnv;
        }

        $projectPath = rtrim((string)BP, '/\\') . '/' . self::PROJECT_KEY_REL;
        if (is_readable($projectPath)) {
            $fromFile = $this->decodeKeyFile($projectPath);
            if ($fromFile !== null) {
                return $this->cachedProjectKey = $fromFile;
            }
        }

        $generated = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $encoded = base64_encode($generated);
        $persisted = false;
        try {
            $persisted = Env::set(self::ENV_SECRETS_KEY, $encoded);
        } catch (\Throwable) {
            $persisted = false;
        }
        if (!$persisted) {
            $dir = dirname($projectPath);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('无法创建账户密钥目录。');
            }
            if (@file_put_contents($projectPath, $encoded) === false) {
                throw new \RuntimeException('无法写入账户密钥文件。');
            }
            @chmod($projectPath, 0600);
        }

        return $this->cachedProjectKey = $generated;
    }

    private function decodeKeyFile(string $path): ?string
    {
        $raw = @file_get_contents($path);

        return is_string($raw) ? $this->decodeKeyMaterial($raw) : null;
    }

    private function decodeKeyMaterial(string $raw): ?string
    {
        $key = base64_decode(trim($raw), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        return $key;
    }
}
