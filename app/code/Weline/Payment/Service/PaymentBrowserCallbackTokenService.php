<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * Signed shell callback token — encodes method_code + transaction ref + scope for browser return/cancel.
 *
 * Format: v1.{base64url(payload_json)}.{base64url(hmac_sha256)}
 */
final class PaymentBrowserCallbackTokenService
{
    public const QUERY_SHELL_TOKEN = 'shell_token';

    public const VERSION = 'v1';

    private const TTL_SECONDS = 172800;

    /**
     * @return array{method_code:string,transaction_no:string,target_scope:string,expires_at:int}|null
     */
    public function decode(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token, 3);
        if (\count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);
        if ($payloadJson === null || $signature === null) {
            return null;
        }

        $expected = hash_hmac('sha256', self::VERSION . '.' . $parts[1], $this->signingKey(), true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!\is_array($payload)) {
            return null;
        }

        $methodCode = strtolower(trim((string) ($payload['m'] ?? '')));
        $transactionNo = trim((string) ($payload['t'] ?? ''));
        $targetScope = strtolower(trim((string) ($payload['s'] ?? '')));
        $expiresAt = (int) ($payload['e'] ?? 0);
        if ($methodCode === '' || $transactionNo === '' || !PaymentBrowserCallbackRoutes::isStorageScope($targetScope)) {
            return null;
        }
        if ($expiresAt > 0 && $expiresAt < time()) {
            return null;
        }

        return [
            'method_code' => $methodCode,
            'transaction_no' => $transactionNo,
            'target_scope' => $targetScope,
            'expires_at' => $expiresAt,
        ];
    }

    public function encode(string $methodCode, string $transactionNo, string $targetScope, ?int $ttlSeconds = null): string
    {
        $methodCode = strtolower(trim($methodCode));
        $transactionNo = trim($transactionNo);
        $targetScope = strtolower(trim($targetScope));
        if ($methodCode === '' || $transactionNo === '') {
            throw new \InvalidArgumentException('payment_shell_callback_token_payload_invalid');
        }
        if (!PaymentBrowserCallbackRoutes::isStorageScope($targetScope)) {
            throw new \InvalidArgumentException('payment_callback_target_scope_invalid');
        }

        $ttl = max(300, $ttlSeconds ?? self::TTL_SECONDS);
        $payload = json_encode([
            'm' => $methodCode,
            't' => $transactionNo,
            's' => $targetScope,
            'e' => time() + $ttl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('payment_shell_callback_token_encode_failed');
        }

        $payloadB64 = $this->base64UrlEncode($payload);
        $signatureB64 = $this->base64UrlEncode(
            hash_hmac('sha256', self::VERSION . '.' . $payloadB64, $this->signingKey(), true),
        );

        return self::VERSION . '.' . $payloadB64 . '.' . $signatureB64;
    }

    private function signingKey(): string
    {
        $configured = getenv('PAYMENT_SHELL_CALLBACK_TOKEN_SECRET');
        if (\is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        if (defined('BP')) {
            return hash('sha256', (string) BP . '|payment-shell-callback-v1');
        }

        return hash('sha256', 'weline-payment-shell-callback-dev-fallback-v1');
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): ?string
    {
        $encoded = strtr($encoded, '-_', '+/');
        $pad = strlen($encoded) % 4;
        if ($pad > 0) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }
}
