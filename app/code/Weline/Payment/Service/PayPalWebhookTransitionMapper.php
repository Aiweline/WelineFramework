<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * Maps PayPal webhook event_type → Payment inbox status_transition.
 *
 * Express defer-capture: CHECKOUT.ORDER.APPROVED must stay `processing` (never `paid`).
 * When transaction metadata has express_awaiting_confirm, consumers must not treat APPROVED
 * as capture/success — capture only after express_confirm_capture (merchant review).
 */
final class PayPalWebhookTransitionMapper
{
    /**
     * @param array<string, mixed> $payload
     */
    public function mapStatusTransition(string $eventType, array $payload = []): string
    {
        $eventType = strtoupper(trim($eventType));

        return match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.COMPLETED' => 'paid',
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REVERSED' => 'failed',
            'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            // APPROVED ≠ captured; express awaiting_confirm must not auto-succeed from this event.
            'PAYMENT.CAPTURE.PENDING', 'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.PROCESSED' => 'processing',
            'PAYMENT.ORDER.CANCELLED', 'CHECKOUT.PAYMENT-APPROVAL.REVERSED' => 'failed',
            'CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.UPDATED', 'RISK.DISPUTE.CREATED' => 'disputed',
            'CUSTOMER.DISPUTE.RESOLVED' => $this->mapDisputeResolvedTransition($payload),
            'PAYMENT.FRAUDDETECTED', 'PAYMENT.CAPTURE.DECLINED' => 'failed',
            default => 'processing',
        };
    }

    /**
     * Whether a mapped transition should be forced to processing while express awaits confirm.
     * Blocks paid/success effects from APPROVED (and any paid transition without capture events).
     */
    public function shouldBlockPaidWhileAwaitingConfirm(string $eventType, string $statusTransition): bool
    {
        $eventType = strtoupper(trim($eventType));
        $transition = strtolower(trim($statusTransition));
        if (\in_array($eventType, ['CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.PROCESSED'], true)) {
            return true;
        }
        if (\in_array($transition, ['paid', 'succeeded', 'captured', 'success'], true)
            && !\in_array($eventType, [
                'PAYMENT.CAPTURE.COMPLETED',
                'CHECKOUT.ORDER.COMPLETED',
            ], true)
        ) {
            return true;
        }

        return false;
    }

    public function isSideEffectNotification(string $eventType): bool
    {
        $eventType = strtoupper(trim($eventType));

        return str_contains($eventType, 'DISPUTE')
            || str_contains($eventType, 'FRAUD')
            || str_contains($eventType, 'REFUND')
            || str_contains($eventType, 'REVERSED')
            || str_contains($eventType, 'DENIED')
            || str_contains($eventType, 'DECLINED');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mapDisputeResolvedTransition(array $payload): string
    {
        $resource = \is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $outcome = strtoupper(trim((string) (
            $resource['dispute_outcome']['outcome_code']
            ?? $resource['outcome_code']
            ?? $resource['status']
            ?? ''
        )));

        return match ($outcome) {
            'RESOLVED_BUYER_FAVOUR', 'RESOLVED_BUYER_FAVOR', 'LOST', 'ACCEPTED' => 'failed',
            'RESOLVED_SELLER_FAVOUR', 'RESOLVED_SELLER_FAVOR', 'WON', 'DENIED' => 'paid',
            default => 'disputed',
        };
    }
}
