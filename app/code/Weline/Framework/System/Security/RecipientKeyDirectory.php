<?php

declare(strict_types=1);

namespace Weline\Framework\System\Security;

use Weline\Framework\App\Env;

/**
 * Recipient X25519 密钥目录（TASK-P1D-003）：active 可导出；decrypt_only 仅解密；revoked 拒绝。
 *
 * Env：`security.config_envelope.keys.{kid}` = {status, public_key_base64, secret_key_base64?}
 */
final class RecipientKeyDirectory
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DECRYPT_ONLY = 'decrypt_only';
    public const STATUS_REVOKED = 'revoked';

    /**
     * @param array<string, array{status?:string,public_key_base64?:string,secret_key_base64?:string}>|null $keys
     */
    public function __construct(
        private ?array $keys = null,
        private ?string $activeKid = null,
        private ?string $instanceId = null,
        private ?bool $enabled = null,
    ) {
    }

    public static function fromEnv(): self
    {
        $keys = [];
        try {
            $raw = Env::get('security.config_envelope.keys', []);
            if (\is_array($raw)) {
                $keys = $raw;
            }
        } catch (\Throwable) {
        }

        $active = '';
        try {
            $active = \trim((string)Env::get('security.config_envelope.active_kid', ''));
        } catch (\Throwable) {
        }
        $instance = '';
        try {
            $instance = \trim((string)Env::get('security.config_envelope.instance_id', ''));
        } catch (\Throwable) {
        }
        $enabled = false;
        try {
            $enabled = (bool)Env::get('security.config_envelope.enabled', false);
        } catch (\Throwable) {
        }

        return new self($keys, $active !== '' ? $active : null, $instance !== '' ? $instance : null, $enabled);
    }

    public function isEnabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        return false;
    }

    public function instanceId(): string
    {
        $instanceId = \trim((string)$this->instanceId);
        if ($instanceId === ''
            || \strlen($instanceId) > 120
            || \preg_match('/^[a-z0-9][a-z0-9._:-]{0,119}$/Di', $instanceId) !== 1) {
            throw new \RuntimeException('config_envelope_instance_id_invalid');
        }

        return $instanceId;
    }

    public function activeKid(): ?string
    {
        if ($this->activeKid !== null && $this->activeKid !== '') {
            return $this->activeKid;
        }
        foreach ($this->allKeys() as $kid => $row) {
            if (($row['status'] ?? '') === self::STATUS_ACTIVE) {
                return $kid;
            }
        }

        return null;
    }

    /**
     * @return array{status:string,public_key:string,secret_key:?string}
     */
    public function requireExportRecipient(string $kid): array
    {
        $row = $this->requireKey($kid);
        if ($row['status'] !== self::STATUS_ACTIVE) {
            throw new \RuntimeException('config_envelope_kid_not_exportable');
        }

        return $row;
    }

    /**
     * @return array{status:string,public_key:string,secret_key:?string}
     */
    public function requireDecryptRecipient(string $kid): array
    {
        $row = $this->requireKey($kid);
        if ($row['status'] === self::STATUS_REVOKED) {
            throw new \RuntimeException('config_envelope_kid_revoked');
        }
        if ($row['status'] !== self::STATUS_ACTIVE && $row['status'] !== self::STATUS_DECRYPT_ONLY) {
            throw new \RuntimeException('config_envelope_kid_not_decryptable');
        }
        if ($row['secret_key'] === null) {
            throw new \RuntimeException('config_envelope_kid_secret_missing');
        }

        return $row;
    }

    /**
     * @return array{status:string,public_key:string,secret_key:?string}
     */
    private function requireKey(string $kid): array
    {
        $kid = \trim($kid);
        if (\preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/Di', $kid) !== 1) {
            throw new \RuntimeException('config_envelope_kid_invalid');
        }
        $all = $this->allKeys();
        if (!isset($all[$kid]) || !\is_array($all[$kid])) {
            throw new \RuntimeException('config_envelope_kid_unknown');
        }
        $status = \strtolower(\trim((string)($all[$kid]['status'] ?? '')));
        $pubB64 = \trim((string)($all[$kid]['public_key_base64'] ?? ''));
        $secB64 = \trim((string)($all[$kid]['secret_key_base64'] ?? ''));
        $pub = SodiumCryptoEnvelope::ub64($pubB64);
        if ($pub === null || \strlen($pub) !== \SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \RuntimeException('config_envelope_kid_pubkey_invalid');
        }
        $sec = null;
        if ($secB64 !== '') {
            $secRaw = SodiumCryptoEnvelope::ub64($secB64);
            if ($secRaw === null || \strlen($secRaw) !== \SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
                throw new \RuntimeException('config_envelope_kid_secret_invalid');
            }
            $sec = $secRaw;
        }

        return [
            'status' => $status,
            'public_key' => $pub,
            'secret_key' => $sec,
        ];
    }

    public function keyPairFor(string $kid): string
    {
        $row = $this->requireDecryptRecipient($kid);
        /** @var string $secret */
        $secret = $row['secret_key'];

        return \sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $row['public_key']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function allKeys(): array
    {
        return \is_array($this->keys) ? $this->keys : [];
    }

    /**
     * 测试/运维：生成一对 X25519 并返回 base64url 字段。
     *
     * @return array{kid:string,status:string,public_key_base64:string,secret_key_base64:string}
     */
    public static function generateKeyRecord(string $kid, string $status = self::STATUS_ACTIVE): array
    {
        SodiumCryptoEnvelope::assertSodium();
        $kp = \sodium_crypto_box_keypair();

        return [
            'kid' => $kid,
            'status' => $status,
            'public_key_base64' => SodiumCryptoEnvelope::b64(\sodium_crypto_box_publickey($kp)),
            'secret_key_base64' => SodiumCryptoEnvelope::b64(\sodium_crypto_box_secretkey($kp)),
        ];
    }
}
