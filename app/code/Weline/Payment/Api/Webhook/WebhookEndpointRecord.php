<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Webhook;

/**
 * Frozen endpoint record for verification（no plaintext secrets）.
 */
final class WebhookEndpointRecord
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_TOMBSTONE = 'tombstone';

    /**
     * @param list<array{secret_version:string,secret_ref:string,status:string,valid_from:int,valid_until:int}> $secretRefs
     * @param array<string, mixed> $scopeSnapshot
     */
    public function __construct(
        public readonly string $endpointCode,
        public readonly string $providerCode,
        public readonly string $methodCode,
        public readonly string $merchantAccount,
        public readonly string $environment,
        public readonly string $status,
        public readonly string $activeSecretVersion,
        public readonly string $contextVersion,
        public readonly array $scopeSnapshot = [],
        public readonly array $secretRefs = [],
        public readonly bool $allowNewCapture = true,
        public readonly int $retainUntil = 0,
    ) {
    }

    public function isReceivable(int $receivedAt = 0): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            return true;
        }
        if ($this->status !== self::STATUS_TOMBSTONE) {
            return false;
        }

        $receivedAt = $receivedAt > 0 ? $receivedAt : time();

        return $this->retainUntil === 0 || $receivedAt <= $this->retainUntil;
    }

    /**
     * @return list<array{secret_version:string,secret_ref:string,status:string,valid_from:int,valid_until:int}>
     */
    public function secretsForTime(int $receivedAt): array
    {
        $out = [];
        foreach ($this->secretRefs as $secret) {
            $status = (string) ($secret['status'] ?? '');
            if (!\in_array($status, ['active', 'grace'], true)) {
                continue;
            }
            $from = (int) ($secret['valid_from'] ?? 0);
            $until = (int) ($secret['valid_until'] ?? PHP_INT_MAX);
            if ($receivedAt < $from || $receivedAt > $until) {
                continue;
            }
            $out[] = $secret;
        }

        return $out;
    }
}
