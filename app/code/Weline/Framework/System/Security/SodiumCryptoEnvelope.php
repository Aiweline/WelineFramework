<?php

declare(strict_types=1);

namespace Weline\Framework\System\Security;

/**
 * Sodium X25519 seal + XChaCha20-Poly1305 AEAD（TASK-P1D-003）。
 */
final class SodiumCryptoEnvelope implements CryptoEnvelopeInterface
{
    public function __construct()
    {
        self::assertSodium();
    }

    public static function assertSodium(): void
    {
        if (!\extension_loaded('sodium')
            || !\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            || !\function_exists('sodium_crypto_box_seal')
        ) {
            throw new \RuntimeException('config_envelope_sodium_unavailable');
        }
    }

    public function seal(
        string $plaintext,
        string $recipientPublicKeyBinary,
        string $recipientKid,
        array $aadFields,
    ): array {
        self::assertSodium();
        if (\strlen($recipientPublicKeyBinary) !== \SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('config_envelope_recipient_pubkey_invalid');
        }
        $packageUuid = self::uuidV4();
        $createdAt = (string)($aadFields['created_at'] ?? \gmdate('c'));
        $expiresAt = (string)($aadFields['expires_at'] ?? \gmdate('c', \time() + 3600));
        $aad = self::canonicalAad([
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'filename' => (string)($aadFields['filename'] ?? ''),
            'package_uuid' => $packageUuid,
            'recipient_kid' => $recipientKid,
            'schema_version' => self::SCHEMA_VERSION,
            'scope' => (string)($aadFields['scope'] ?? ''),
            'source_instance' => (string)($aadFields['source_instance'] ?? ''),
        ]);

        $dataKey = \random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $nonce = \random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            $dataKey,
        );
        $wrappedKey = \sodium_crypto_box_seal($dataKey, $recipientPublicKeyBinary);
        \sodium_memzero($dataKey);

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'package_uuid' => $packageUuid,
            'recipient_kid' => $recipientKid,
            'wrapped_key' => self::b64($wrappedKey),
            'nonce' => self::b64($nonce),
            'aad' => $aad,
            'ciphertext' => self::b64($ciphertext),
            'payload_hash' => \hash('sha256', $ciphertext),
        ];
        if (isset($aadFields['source_signature']) && \is_string($aadFields['source_signature']) && $aadFields['source_signature'] !== '') {
            $envelope['source_signature'] = $aadFields['source_signature'];
        }

        return $envelope;
    }

    public function open(
        array $envelope,
        string $recipientKeyPairBinary,
        ?string $expectedFilename = null,
    ): string {
        return $this->decrypt($envelope, $recipientKeyPairBinary, $expectedFilename, true);
    }

    public function preview(
        array $envelope,
        string $recipientKeyPairBinary,
        ?string $expectedFilename = null,
    ): string {
        return $this->decrypt($envelope, $recipientKeyPairBinary, $expectedFilename, false);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function decrypt(
        array $envelope,
        string $recipientKeyPairBinary,
        ?string $expectedFilename,
        bool $enforceExpiry,
    ): string {
        self::assertSodium();
        if (\strlen($recipientKeyPairBinary) !== \SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
            throw new \InvalidArgumentException('config_envelope_recipient_keypair_invalid');
        }
        $schema = (string)($envelope['schema_version'] ?? '');
        if ($schema !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('config_envelope_schema_mismatch');
        }
        $packageUuid = (string)($envelope['package_uuid'] ?? '');
        $recipientKid = (string)($envelope['recipient_kid'] ?? '');
        $aadRaw = (string)($envelope['aad'] ?? '');
        $nonceB64 = (string)($envelope['nonce'] ?? '');
        $wrappedB64 = (string)($envelope['wrapped_key'] ?? '');
        $cipherB64 = (string)($envelope['ciphertext'] ?? '');
        if ($packageUuid === '' || $recipientKid === '' || $aadRaw === '' || $nonceB64 === '' || $wrappedB64 === '' || $cipherB64 === '') {
            throw new \RuntimeException('config_envelope_incomplete');
        }
        if (\preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
            $packageUuid,
        ) !== 1) {
            throw new \RuntimeException('config_envelope_package_uuid_invalid');
        }

        $aadData = \json_decode($aadRaw, true);
        if (!\is_array($aadData)) {
            throw new \RuntimeException('config_envelope_aad_corrupt');
        }
        $expectedAad = self::canonicalAad([
            'created_at' => (string)($aadData['created_at'] ?? ''),
            'expires_at' => (string)($aadData['expires_at'] ?? ''),
            'filename' => (string)($aadData['filename'] ?? ''),
            'package_uuid' => (string)($aadData['package_uuid'] ?? ''),
            'recipient_kid' => (string)($aadData['recipient_kid'] ?? ''),
            'schema_version' => (string)($aadData['schema_version'] ?? ''),
            'scope' => (string)($aadData['scope'] ?? ''),
            'source_instance' => (string)($aadData['source_instance'] ?? ''),
        ]);
        if (!\hash_equals($expectedAad, $aadRaw)) {
            throw new \RuntimeException('config_envelope_aad_mismatch');
        }
        if ((string)($aadData['package_uuid'] ?? '') !== $packageUuid
            || (string)($aadData['recipient_kid'] ?? '') !== $recipientKid
            || (string)($aadData['schema_version'] ?? '') !== $schema
        ) {
            throw new \RuntimeException('config_envelope_aad_binding_failed');
        }
        if ($expectedFilename !== null && (string)($aadData['filename'] ?? '') !== $expectedFilename) {
            throw new \RuntimeException('config_envelope_filename_mismatch');
        }
        if ($enforceExpiry) {
            $expiresAt = (string)($aadData['expires_at'] ?? '');
            $expiresTs = $expiresAt !== '' ? \strtotime($expiresAt) : false;
            if ($expiresTs === false || $expiresTs < \time()) {
                throw new \RuntimeException('config_envelope_expired');
            }
        }

        $nonce = self::ub64($nonceB64);
        $wrapped = self::ub64($wrappedB64);
        $ciphertext = self::ub64($cipherB64);
        if ($nonce === null || $wrapped === null || $ciphertext === null) {
            throw new \RuntimeException('config_envelope_encoding_corrupt');
        }
        if (\strlen($nonce) !== \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new \RuntimeException('config_envelope_nonce_invalid');
        }
        if (isset($envelope['payload_hash']) && \is_string($envelope['payload_hash']) && $envelope['payload_hash'] !== '') {
            if (!\hash_equals($envelope['payload_hash'], \hash('sha256', $ciphertext))) {
                throw new \RuntimeException('config_envelope_payload_hash_mismatch');
            }
        }

        $dataKey = \sodium_crypto_box_seal_open($wrapped, $recipientKeyPairBinary);
        if ($dataKey === false) {
            throw new \RuntimeException('config_envelope_unwrap_failed');
        }
        $plain = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $aadRaw,
            $nonce,
            $dataKey,
        );
        \sodium_memzero($dataKey);
        if ($plain === false) {
            throw new \RuntimeException('config_envelope_aead_failed');
        }

        return $plain;
    }

    /**
     * @param array<string, string> $fields
     */
    public static function canonicalAad(array $fields): string
    {
        \ksort($fields);

        return \json_encode($fields, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public static function uuidV4(): string
    {
        $b = \random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0f) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3f) | 0x80);

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($b), 4));
    }

    public static function b64(string $bin): string
    {
        return \rtrim(\strtr(\base64_encode($bin), '+/', '-_'), '=');
    }

    public static function ub64(string $b64): ?string
    {
        $pad = 4 - (\strlen($b64) % 4);
        if ($pad < 4) {
            $b64 .= \str_repeat('=', $pad);
        }
        $bin = \base64_decode(\strtr($b64, '-_', '+/'), true);

        return $bin === false ? null : $bin;
    }
}
