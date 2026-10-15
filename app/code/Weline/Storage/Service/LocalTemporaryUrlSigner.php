<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

/**
 * HMAC signer for local-disk KIND_TEMPORARY URLs served by a protected route.
 *
 * Private FileAssets on local disks must not reuse the public /pub/media base URL;
 * callers receive a short-lived signed path that streams bytes through PHP.
 */
final class LocalTemporaryUrlSigner
{
    /** Frontend controller path: module router + area + controller. */
    public const ROUTE_PATH = '/storage/frontend/temporary';
    private const PAYLOAD_VERSION = 'v1';

    public function __construct(
        private readonly ?string $configuredSigningKey = null,
    ) {
    }

    public function signingKey(): string
    {
        $configured = trim((string)($this->configuredSigningKey ?? $this->envConfiguredKey()));
        if ($configured !== '' && strlen($configured) >= 16) {
            return hash('sha256', $configured, true);
        }
        $salt = defined('BP') ? (string)BP : __DIR__;

        return hash('sha256', $salt . '|weline.storage.local_temporary_url.v1', true);
    }

    private function envConfiguredKey(): string
    {
        // Env.php binds class constants to BP; never autoload it in unit contexts
        // where BP is undefined.
        if (!defined('BP') || !defined('DS') || !defined('APP_ETC_PATH')) {
            return '';
        }
        try {
            $envClass = 'Weline\\Framework\\App\\Env';
            if (!class_exists($envClass)) {
                return '';
            }

            return trim((string)$envClass::get('storage.local_temporary_signing_key', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    public function encodeObjectKey(string $objectKey): string
    {
        $objectKey = str_replace('\\', '/', trim($objectKey));
        if ($objectKey === '' || str_starts_with($objectKey, '/') || str_contains($objectKey, "\0")) {
            throw new \InvalidArgumentException((string)__('临时 URL 对象键无效。'));
        }

        return rtrim(strtr(base64_encode($objectKey), '+/', '-_'), '=');
    }

    public function decodeObjectKey(string $encoded): string
    {
        $encoded = trim($encoded);
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            throw new \InvalidArgumentException((string)__('临时 URL 对象键无效。'));
        }
        $padded = strtr($encoded, '-_', '+/');
        $padLen = (4 - (strlen($padded) % 4)) % 4;
        if ($padLen > 0) {
            $padded .= str_repeat('=', $padLen);
        }
        $decoded = base64_decode($padded, true);
        if (!is_string($decoded) || $decoded === '') {
            throw new \InvalidArgumentException((string)__('临时 URL 对象键无效。'));
        }
        $normalized = str_replace('\\', '/', $decoded);
        if (str_starts_with($normalized, '/')
            || str_contains($normalized, "\0")
            || str_contains($normalized, '../')
            || $normalized === '..'
            || str_ends_with($normalized, '/..')
        ) {
            throw new \InvalidArgumentException((string)__('临时 URL 对象键无效。'));
        }

        return $normalized;
    }

    public function encodeDiskCode(string $diskCode): string
    {
        $diskCode = strtolower(trim($diskCode));
        if ($diskCode === '' || preg_match('/[\x00-\x1F\x7F]/', $diskCode) === 1) {
            throw new \InvalidArgumentException((string)__('临时 URL 签名参数无效。'));
        }

        // URL-safe token so storefront redirect() re-encoding cannot corrupt :: etc.
        return rtrim(strtr(base64_encode($diskCode), '+/', '-_'), '=');
    }

    public function decodeDiskCode(string $encoded): string
    {
        $encoded = trim($encoded);
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            throw new \InvalidArgumentException((string)__('临时 URL 签名参数无效。'));
        }
        $padded = strtr($encoded, '-_', '+/');
        $padLen = (4 - (strlen($padded) % 4)) % 4;
        if ($padLen > 0) {
            $padded .= str_repeat('=', $padLen);
        }
        $decoded = base64_decode($padded, true);
        if (!is_string($decoded) || $decoded === '') {
            throw new \InvalidArgumentException((string)__('临时 URL 签名参数无效。'));
        }

        return strtolower(trim($decoded));
    }

    public function sign(
        string $diskCode,
        string $objectKey,
        int $expiresAt,
        int $configRevision = 1,
    ): string {
        $diskCode = strtolower(trim($diskCode));
        $objectKey = str_replace('\\', '/', trim($objectKey));
        if ($diskCode === '' || $objectKey === '' || $expiresAt < 1 || $configRevision < 1) {
            throw new \InvalidArgumentException((string)__('临时 URL 签名参数无效。'));
        }
        $sig = $this->signature($diskCode, $objectKey, $expiresAt, $configRevision);
        // All values are URL-safe (base64url / digits / hex) so a single
        // redirect() pass that re-encodes query values remains idempotent.
        $query = http_build_query([
            'disk' => $this->encodeDiskCode($diskCode),
            'key' => $this->encodeObjectKey($objectKey),
            'e' => $expiresAt,
            'r' => $configRevision,
            'sig' => $sig,
        ], '', '&', PHP_QUERY_RFC3986);

        return self::ROUTE_PATH . '?' . $query;
    }

    public function verify(
        string $diskCode,
        string $objectKey,
        int $expiresAt,
        int $configRevision,
        string $signature,
    ): bool {
        $diskCode = strtolower(trim($diskCode));
        $objectKey = str_replace('\\', '/', trim($objectKey));
        $signature = strtolower(trim($signature));
        if ($diskCode === ''
            || $objectKey === ''
            || $expiresAt < 1
            || $configRevision < 1
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1
        ) {
            return false;
        }
        if ($expiresAt < time()) {
            return false;
        }
        $expected = $this->signature($diskCode, $objectKey, $expiresAt, $configRevision);

        return hash_equals($expected, $signature);
    }

    private function signature(
        string $diskCode,
        string $objectKey,
        int $expiresAt,
        int $configRevision,
    ): string {
        $payload = implode("\0", [
            self::PAYLOAD_VERSION,
            $diskCode,
            $objectKey,
            (string)$expiresAt,
            (string)$configRevision,
        ]);

        return hash_hmac('sha256', $payload, $this->signingKey());
    }
}
