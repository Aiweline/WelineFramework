<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Billing;

final readonly class BillingDebitResult
{
    private function __construct(
        public bool $accountExists,
        public bool $debited,
        public float $balanceBefore,
        public float $balanceAfter,
    ) {
    }

    public static function accountMissing(): self
    {
        return new self(false, false, 0.0, 0.0);
    }

    public static function insufficient(float $balance): self
    {
        return new self(true, false, $balance, $balance);
    }

    public static function debited(float $balanceBefore, float $balanceAfter): self
    {
        return new self(true, true, $balanceBefore, $balanceAfter);
    }
}
