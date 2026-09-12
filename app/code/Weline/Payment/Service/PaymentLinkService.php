<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Api\PaymentLinkServiceInterface;

/**
 * In-memory + optional array-backed payment_link / selection_share store for unit + runtime bootstrap.
 *
 * Production persists via PaymentLinkRecordRepository when wired; tests inject Array repository.
 */
final class PaymentLinkService implements PaymentLinkServiceInterface
{
    /** @var array<string, array<string,mixed>> token => record */
    private array $records = [];

    private PaymentLinkRecordRepository $repository;

    public function __construct(?PaymentLinkRecordRepository $repository = null)
    {
        $this->repository = $repository ?? new PaymentLinkRecordRepository();
    }

    public function create(array $input, string $publicOrigin = ''): array
    {
        $kind = (string) ($input['kind'] ?? '');
        if (!in_array($kind, [
            self::KIND_HELP_PAY,
            self::KIND_SELECTION_SHARE,
            self::KIND_QUICK_PAY,
        ], true)) {
            throw new \InvalidArgumentException('payment_link_kind_invalid');
        }

        $ttl = (int) ($input['ttl_seconds'] ?? 86400 * 7);
        if ($ttl < 60) {
            $ttl = 60;
        }
        $expiresAt = time() + $ttl;
        $token = $this->mintToken();
        $code = 'pl_' . bin2hex(random_bytes(8));
        $shippingLocked = $kind === self::KIND_HELP_PAY
            ? (bool) ($input['shipping_locked'] ?? true)
            : (bool) ($input['shipping_locked'] ?? false);

        $path = match ($kind) {
            self::KIND_HELP_PAY => 'h/' . $token,
            self::KIND_SELECTION_SHARE => 's/' . $token,
            default => 'q/' . $token,
        };

        $record = [
            'payment_link_code' => $code,
            'token' => $token,
            'kind' => $kind,
            'status' => self::STATUS_ACTIVE,
            'path' => $path,
            'payable_type' => (string) ($input['payable_type'] ?? ''),
            'payable_id' => (string) ($input['payable_id'] ?? ''),
            'owner_customer_id' => isset($input['owner_customer_id']) ? (int) $input['owner_customer_id'] : null,
            'amount_minor' => (int) ($input['amount_minor'] ?? 0),
            'currency_code' => strtoupper((string) ($input['currency_code'] ?? 'USD')),
            'shipping_locked' => $shippingLocked,
            'shipping_snapshot' => is_array($input['shipping_snapshot'] ?? null)
                ? $input['shipping_snapshot']
                : null,
            'selection_snapshot' => is_array($input['selection_snapshot'] ?? null)
                ? $input['selection_snapshot']
                : null,
            'meta' => is_array($input['meta'] ?? null) ? $input['meta'] : [],
            'expires_at' => $expiresAt,
            'created_at' => time(),
            'revoked_at' => null,
        ];

        $this->records[$this->key($kind, $token)] = $record;
        $this->repository->save($record);

        $origin = rtrim($publicOrigin, '/');
        $absolute = $origin === '' ? '/' . $path : $origin . '/' . $path;

        return [
            'payment_link_code' => $code,
            'token' => $token,
            'kind' => $kind,
            'path' => $path,
            'absolute_url' => $absolute,
            'expires_at' => $expiresAt,
            'shipping_locked' => $shippingLocked,
        ];
    }

    public function resolve(string $token, string $kind): ?array
    {
        $record = $this->load($kind, $token);
        if ($record === null) {
            return null;
        }
        if (($record['status'] ?? '') !== self::STATUS_ACTIVE) {
            return null;
        }
        if ((int) ($record['expires_at'] ?? 0) < time()) {
            $record['status'] = self::STATUS_EXPIRED;
            $this->persist($record);

            return null;
        }

        // Storefront projection: never expose shipping_snapshot.
        $out = $record;
        unset($out['shipping_snapshot']);
        $out['shipping_redacted'] = true;
        $out['has_shipping_locked'] = (bool) ($record['shipping_locked'] ?? false);

        return $out;
    }

    public function resolveShippingForFulfillment(string $token, string $kind): ?array
    {
        $record = $this->load($kind, $token);
        if ($record === null) {
            return null;
        }
        if (($record['status'] ?? '') !== self::STATUS_ACTIVE && ($record['status'] ?? '') !== self::STATUS_CONSUMED) {
            return null;
        }
        $shipping = $record['shipping_snapshot'] ?? null;

        return is_array($shipping) ? $shipping : null;
    }

    public function revoke(string $token, string $kind, ?int $actorCustomerId = null): bool
    {
        $record = $this->load($kind, $token);
        if ($record === null) {
            return false;
        }
        $owner = $record['owner_customer_id'] ?? null;
        if ($actorCustomerId !== null && $owner !== null && (int) $owner !== $actorCustomerId) {
            return false;
        }
        $record['status'] = self::STATUS_REVOKED;
        $record['revoked_at'] = time();
        $this->persist($record);

        return true;
    }

    private function load(string $kind, string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $token)) {
            return null;
        }
        $key = $this->key($kind, $token);
        // File-backed store is shared across WLS workers — always re-read repo
        // so revive/extend/revoke on disk is not masked by process-local cache.
        $fromRepo = $this->repository->findByToken($kind, $token);
        if (is_array($fromRepo)) {
            $this->records[$key] = $fromRepo;

            return $fromRepo;
        }

        return $this->records[$key] ?? null;
    }

    /** @param array<string,mixed> $record */
    private function persist(array $record): void
    {
        $kind = (string) ($record['kind'] ?? '');
        $token = (string) ($record['token'] ?? '');
        $this->records[$this->key($kind, $token)] = $record;
        $this->repository->save($record);
    }

    private function key(string $kind, string $token): string
    {
        return $kind . ':' . $token;
    }

    private function mintToken(): string
    {
        // Lowercase hex survives path case-normalization in some routers.
        return bin2hex(random_bytes(16));
    }
}
