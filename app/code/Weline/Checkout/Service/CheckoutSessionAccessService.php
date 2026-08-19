<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;

/**
 * Validates the short-lived quote-token capability used by checkout result pages.
 */
final class CheckoutSessionAccessService
{
    public function __construct(
        private readonly CheckoutSessionStoreInterface $sessions,
    ) {
    }

    public function canAccess(string $quoteToken, string $orderUuid, ?int $currentCustomerId): bool
    {
        $quoteToken = trim($quoteToken);
        $orderUuid = trim($orderUuid);
        if ($quoteToken === '' || $orderUuid === '') {
            return false;
        }

        $session = $this->sessions->get($quoteToken);
        if (!is_array($session)
            || (string)($session['state'] ?? '') !== CheckoutSession::STATE_SUBMITTED) {
            return false;
        }

        $submitted = is_array($session['submitted_result'] ?? null)
            ? $session['submitted_result']
            : [];
        $orderUuids = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($submitted['order_uuids'] ?? []),
        )));
        if (!in_array($orderUuid, $orderUuids, true)) {
            return false;
        }

        $frozenCustomerId = isset($session['customer_id']) && (int)$session['customer_id'] > 0
            ? (int)$session['customer_id']
            : null;
        if ($frozenCustomerId === null || $currentCustomerId === null) {
            // The short-lived high-entropy quote token is the success-page
            // capability. It must also work during the redirect boundary where
            // the frontend identity has not yet been restored by another WLS
            // worker. If an identity is present, a different customer is still
            // rejected below.
            return true;
        }

        return $currentCustomerId !== null && $currentCustomerId === $frozenCustomerId;
    }

    public function canReadOrder(
        ?int $orderCustomerId,
        ?int $currentCustomerId,
        bool $capabilityAllowed,
    ): bool {
        if ($capabilityAllowed) {
            return true;
        }
        if ($orderCustomerId === null || $currentCustomerId === null) {
            return false;
        }

        return $orderCustomerId === $currentCustomerId;
    }
}
