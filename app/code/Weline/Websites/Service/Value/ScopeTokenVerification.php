<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Value;

use Weline\Framework\Runtime\ScopeIdentity;

final readonly class ScopeTokenVerification
{
    public const STATUS_VALID = 'valid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_CONTEXT_CONFLICT = 'context_conflict';
    public const STATUS_SERVICE_UNAVAILABLE = 'service_unavailable';

    private function __construct(
        public string $status,
        public string $reason,
        public ?ScopeIdentity $scope,
        public ?string $kid,
        public ?int $issuedAt,
        public ?int $expiresAt,
        public ?string $host,
        public string $tokenFingerprint,
    ) {
    }

    public static function valid(
        ScopeIdentity $scope,
        string $kid,
        int $issuedAt,
        int $expiresAt,
        string $host,
        string $fingerprint,
    ): self {
        return new self(self::STATUS_VALID, 'valid', $scope, $kid, $issuedAt, $expiresAt, $host, $fingerprint);
    }

    public static function expired(
        ScopeIdentity $scope,
        string $kid,
        int $issuedAt,
        int $expiresAt,
        string $host,
        string $fingerprint,
    ): self {
        return new self(self::STATUS_EXPIRED, 'expired', $scope, $kid, $issuedAt, $expiresAt, $host, $fingerprint);
    }

    public static function rejected(string $status, string $reason, string $fingerprint, ?string $kid = null): self
    {
        if (!in_array($status, [self::STATUS_INVALID, self::STATUS_CONTEXT_CONFLICT, self::STATUS_SERVICE_UNAVAILABLE], true)) {
            throw new \InvalidArgumentException('Scope Token 拒绝状态无效');
        }
        return new self($status, $reason, null, $kid, null, null, null, $fingerprint);
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID && $this->scope !== null;
    }
}
