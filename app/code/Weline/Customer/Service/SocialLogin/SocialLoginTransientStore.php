<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

/**
 * Cookie-independent store for OAuth state / pending profile payloads.
 *
 * Google (and other IdPs) return via a cross-site top-level navigation. When the
 * storefront Session cookie is missing on that hop — common with non-standard
 * HTTPS ports + SameSite=Lax, or embedded browsers without CHIPS — session-only
 * state fails in ~milliseconds with "状态无效或已过期". Opaque state/token keys in
 * this store keep the callback resilient without relying on the browser jar.
 */
final class SocialLoginTransientStore
{
    private const DIR_NAME = 'social_login_oauth';

    public function put(string $kind, string $token, array $payload, int $ttlSeconds): void
    {
        $token = trim($token);
        $kind = trim($kind);
        if ($token === '' || $kind === '' || $ttlSeconds < 1) {
            throw new \InvalidArgumentException('Social login transient store input is invalid.');
        }

        $path = $this->pathFor($kind, $token);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create social login transient store directory.');
        }

        $envelope = [
            'kind' => $kind,
            'token' => $token,
            'expires_at' => time() + $ttlSeconds,
            'payload' => $payload,
        ];
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Unable to encode social login transient payload.');
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write social login transient payload.');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to commit social login transient payload.');
        }
        @chmod($path, 0600);
    }

    /** @return array<string, mixed>|null */
    public function get(string $kind, string $token): ?array
    {
        return $this->read($kind, $token, false);
    }

    /** @return array<string, mixed>|null */
    public function take(string $kind, string $token): ?array
    {
        return $this->read($kind, $token, true);
    }

    public function delete(string $kind, string $token): void
    {
        $path = $this->pathFor($kind, $token);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array<string, mixed>|null */
    private function read(string $kind, string $token, bool $consume): ?array
    {
        $token = trim($token);
        $kind = trim($kind);
        if ($token === '' || $kind === '') {
            return null;
        }

        $path = $this->pathFor($kind, $token);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($consume) {
            @unlink($path);
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['payload'] ?? null)) {
            return null;
        }
        if ((string) ($decoded['kind'] ?? '') !== $kind) {
            return null;
        }
        if ((int) ($decoded['expires_at'] ?? 0) < time()) {
            if (!$consume && is_file($path)) {
                @unlink($path);
            }

            return null;
        }

        return $decoded['payload'];
    }

    private function pathFor(string $kind, string $token): string
    {
        $safeKind = preg_replace('/[^a-z0-9_\-]+/i', '', $kind) ?: 'unknown';
        $hash = hash('sha256', $safeKind . "\0" . $token);
        $varDir = $this->varDir();

        return $varDir . DIRECTORY_SEPARATOR
            . self::DIR_NAME . DIRECTORY_SEPARATOR
            . $safeKind . DIRECTORY_SEPARATOR
            . $hash . '.json';
    }

    private function varDir(): string
    {
        if (\defined('BP') && \is_string(\constant('BP')) && \constant('BP') !== '') {
            return \rtrim((string) \constant('BP'), '/\\') . DIRECTORY_SEPARATOR . 'var';
        }

        // Service/SocialLogin → repo root (/app/code/Weline/Customer/Service/SocialLogin)
        return \dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . 'var';
    }
}