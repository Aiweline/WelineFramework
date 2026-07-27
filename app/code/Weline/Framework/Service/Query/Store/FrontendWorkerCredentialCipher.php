<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

use Weline\Framework\App\Env;
use Weline\Framework\Service\Query\FrontendQueryException;

/** Encrypts database-backed Worker credential payloads with a rotating keyring. */
final class FrontendWorkerCredentialCipher
{
    private const MAX_KEYS = 8;
    private const MAX_PLAINTEXT_BYTES = 524288;
    private const KEY_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';
    private const ROOT_KEYS = [
        'active_key_id',
        'keys',
        'production_rpo_zero_attested',
        'production_topology_id',
        'version',
    ];
    private const REQUIRED_ROOT_KEYS = ['active_key_id', 'keys', 'version'];
    private const TOPOLOGY_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$/D';

    private string $activeKeyId;
    private int $version;
    private string $digest;
    private bool $loaded = false;

    /** @var array<string, mixed>|null */
    private ?array $configuration = null;

    /** @var array<string, array{status:string,key:string}> */
    private array $keys = [];

    public function __construct()
    {
    }

    /** @param array<string, mixed> $configuration */
    public static function forConfiguration(array $configuration): self
    {
        $cipher = new self();
        $cipher->configuration = $configuration;
        return $cipher;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        if (!\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential encryption is unavailable.',
                503,
            );
        }

        $config = $this->configuration;
        try {
            $config ??= Env::get('wls.frontend_worker_credential_store', []);
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential encryption configuration is unavailable.',
                503,
                $exception,
            );
        }
        if (!\is_array($config)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential encryption configuration is invalid.',
                503,
            );
        }
        $rootKeys = \array_keys($config);
        if (\array_is_list($config)
            || \array_diff(self::REQUIRED_ROOT_KEYS, $rootKeys) !== []
            || \array_diff($rootKeys, self::ROOT_KEYS) !== []) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential encryption configuration is invalid.',
                503,
            );
        }
        if (\array_key_exists('production_rpo_zero_attested', $config)
            && !\is_bool($config['production_rpo_zero_attested'])) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential durability attestation is invalid.',
                503,
            );
        }
        if (\array_key_exists('production_topology_id', $config)
            && (!\is_string($config['production_topology_id'])
                || \preg_match(self::TOPOLOGY_ID_PATTERN, $config['production_topology_id']) !== 1)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential durability topology is invalid.',
                503,
            );
        }

        $activeKeyId = \trim((string)($config['active_key_id'] ?? ''));
        $version = $config['version'] ?? null;
        $configuredKeys = $config['keys'] ?? null;
        if (\preg_match(self::KEY_ID_PATTERN, $activeKeyId) !== 1
            || !\is_int($version)
            || $version < 1
            || !\is_array($configuredKeys)
            || \array_is_list($configuredKeys)
            || $configuredKeys === []
            || \count($configuredKeys) > self::MAX_KEYS) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential encryption keyring is invalid.',
                503,
            );
        }

        $activeCount = 0;
        $fingerprints = [];
        foreach ($configuredKeys as $keyId => $keyConfig) {
            if (!\is_string($keyId)
                || \preg_match(self::KEY_ID_PATTERN, $keyId) !== 1
                || !\is_array($keyConfig)
                || \array_is_list($keyConfig)
                || !$this->hasExactKeys($keyConfig, ['key_base64url', 'status'])) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential encryption keyring is invalid.',
                    503,
                );
            }
            $status = (string)($keyConfig['status'] ?? '');
            $encodedKey = $keyConfig['key_base64url'] ?? null;
            if (!\in_array($status, ['active', 'decrypt_only'], true) || !\is_string($encodedKey)) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential encryption keyring is invalid.',
                    503,
                );
            }
            $key = $this->decodeBase64Url($encodedKey);
            if (\strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential encryption key length is invalid.',
                    503,
                );
            }
            $fingerprint = \hash('sha256', $key);
            if (isset($fingerprints[$fingerprint])) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential encryption key reuse is forbidden.',
                    503,
                );
            }
            $fingerprints[$fingerprint] = true;
            $activeCount += $status === 'active' ? 1 : 0;
            $this->keys[$keyId] = ['status' => $status, 'key' => $key];
        }
        \ksort($this->keys, SORT_STRING);
        if ($activeCount !== 1
            || !\array_key_exists($activeKeyId, $this->keys)
            || $this->keys[$activeKeyId]['status'] !== 'active') {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential active encryption key is unavailable.',
                503,
            );
        }
        $this->activeKeyId = $activeKeyId;
        $this->version = $version;
        $this->digest = $this->semanticDigest();
        $this->loaded = true;
    }

    public function version(): int
    {
        $this->load();
        return $this->version;
    }

    public function digest(): string
    {
        $this->load();
        return $this->digest;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{key_id:string,ciphertext:string,payload_bytes:int}
     */
    public function encrypt(array $payload, array $identity): array
    {
        $this->load();
        try {
            $plaintext = \json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential payload is not serializable.',
                503,
                $exception,
            );
        }
        if (\strlen($plaintext) > self::MAX_PLAINTEXT_BYTES) {
            throw new FrontendQueryException(
                'worker_capacity_exhausted',
                'Worker credential payload exceeds the storage limit.',
                503,
            );
        }

        $nonce = \random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $aad = $this->aad($identity, $this->activeKeyId);
        $ciphertext = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            $this->keys[$this->activeKeyId]['key'],
        );

        $encodedCiphertext = $this->encodeBase64Url($nonce . $ciphertext);
        return [
            'key_id' => $this->activeKeyId,
            'ciphertext' => $encodedCiphertext,
            'payload_bytes' => \strlen($encodedCiphertext),
        ];
    }

    /** @return array<string, mixed> */
    public function decrypt(string $keyId, string $encodedCiphertext, array $identity): array
    {
        $this->load();
        if (!\array_key_exists($keyId, $this->keys)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential encryption key is unavailable.',
                503,
            );
        }
        $sealed = $this->decodeBase64Url($encodedCiphertext);
        $minimumBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
        if (\strlen($sealed) < $minimumBytes) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential ciphertext is invalid.',
                503,
            );
        }

        $nonce = \substr($sealed, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = \substr($sealed, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plaintext = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $this->aad($identity, $keyId),
            $nonce,
            $this->keys[$keyId]['key'],
        );
        if (!\is_string($plaintext) || \strlen($plaintext) > self::MAX_PLAINTEXT_BYTES) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential authentication failed.',
                503,
            );
        }

        try {
            $payload = \json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential payload is invalid.',
                503,
                $exception,
            );
        }
        if (!\is_array($payload)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential payload is invalid.',
                503,
            );
        }
        return $payload;
    }

    private function aad(array $identity, string $keyId): string
    {
        $fields = [
            'v' => 1,
            'type' => (string)($identity['type'] ?? ''),
            'scope_hash' => (string)($identity['scope_hash'] ?? ''),
            'credential_hash' => (string)($identity['credential_hash'] ?? ''),
            'created_at' => (int)($identity['created_at'] ?? 0),
            'expires_at' => (int)($identity['expires_at'] ?? 0),
            'key_id' => $keyId,
        ];
        return \json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decodeBase64Url(string $encoded): string
    {
        $encoded = \trim($encoded);
        if ($encoded === '' || \preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $encoded) !== 1) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential key material is invalid.',
                503,
            );
        }
        $unpadded = \rtrim($encoded, '=');
        $padding = (4 - (\strlen($unpadded) % 4)) % 4;
        $decoded = \base64_decode(\strtr($unpadded, '-_', '+/') . \str_repeat('=', $padding), true);
        if (!\is_string($decoded)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential key material is invalid.',
                503,
            );
        }
        return $decoded;
    }

    private function encodeBase64Url(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private function semanticDigest(): string
    {
        $keys = [];
        foreach ($this->keys as $keyId => $key) {
            $keys[$keyId] = [
                'status' => $key['status'],
                'key_sha256' => \hash('sha256', $key['key']),
            ];
        }
        return \hash('sha256', \json_encode([
            'version' => $this->version,
            'active_key_id' => $this->activeKeyId,
            'keys' => $keys,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data @param list<string> $expected */
    private function hasExactKeys(array $data, array $expected): bool
    {
        $actual = \array_keys($data);
        \sort($actual, SORT_STRING);
        \sort($expected, SORT_STRING);
        return $actual === $expected;
    }
}
