<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Billing;

/**
 * Optional account storage used by Ai billing.
 *
 * Implementations own their account model and persistence. Ai only consumes
 * this data-only contract and never depends on an account module directly.
 */
interface BillingAccountProviderInterface
{
    public function debit(int $accountId, float $amount): BillingDebitResult;

    public function hasSufficientBalance(int $accountId, float $requiredAmount = 0.0): bool;
}
