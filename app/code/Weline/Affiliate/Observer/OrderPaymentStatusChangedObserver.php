<?php

declare(strict_types=1);

namespace Weline\Affiliate\Observer;

use Weline\Affiliate\Service\AffiliateOrderEventPayloadBuilder;
use Weline\Affiliate\Service\AffiliateService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderPaymentStatusChangedObserver implements ObserverInterface
{
    public function __construct(
        private readonly AffiliateService $affiliateService,
        private readonly AffiliateOrderEventPayloadBuilder $payloadBuilder,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }

        $eventName = (string) ($event->getName() ?? '');
        $paymentStatus = match (true) {
            str_contains($eventName, 'order_paid') => 'paid',
            str_contains($eventName, 'order_refunded') => 'refunded',
            default => strtolower((string) ($data['new_payment_status'] ?? $data['payment_status'] ?? '')),
        };

        if ($paymentStatus === '') {
            return;
        }

        try {
            $this->affiliateService->handlePaymentStatusChanged(
                $this->payloadBuilder->buildPaymentPayload($data, $paymentStatus)
            );
        } catch (\Throwable $e) {
            // Affiliate side effects must never roll back Order paid projection / mail.
            if (\function_exists('w_log_error')) {
                w_log_error('Affiliate order_paid side-effect failed: ' . $e->getMessage(), [
                    'event' => $eventName,
                    'payment_status' => $paymentStatus,
                    'exception' => $e::class,
                ], 'affiliate');
            }
        }
    }
}
