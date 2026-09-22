<?php

declare(strict_types=1);

namespace Weline\Newsletter\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Newsletter\Service\CheckoutAutoApplyService;

/**
 * T2: when Checkout identity/guest email is recognizable, auto-apply issued newsletter gift coupon.
 * Listens to existing Checkout events — no Checkout Model reads.
 */
final class CheckoutEmailMatchCouponObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            $payload = $this->extractPayload($event);
            $context = $this->buildContext($payload);
            if ($context === null) {
                return;
            }

            /** @var CheckoutAutoApplyService $service */
            $service = ObjectManager::getInstance(CheckoutAutoApplyService::class);
            $service->applyForRecognizedEmail($context);
        } catch (\Throwable) {
            // Best-effort; never block checkout.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPayload(Event &$event): array
    {
        $data = $event->getData();
        if (\is_array($data)) {
            // Prefer nested keys when Event wraps ['data' => &$payload].
            if (isset($data['identity']) || isset($data['checkout_data']) || isset($data['context'])) {
                return $data;
            }
            if (isset($data['data']) && \is_array($data['data'])) {
                return $data['data'];
            }

            return $data;
        }

        $identity = $event->getData('identity');
        $context = $event->getData('context');
        $checkoutData = $event->getData('checkout_data');

        return [
            'identity' => \is_array($identity) ? $identity : [],
            'context' => \is_array($context) ? $context : [],
            'checkout_data' => \is_array($checkoutData) ? $checkoutData : [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function buildContext(array $payload): ?array
    {
        $identity = \is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $context = \is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $checkoutData = \is_array($payload['checkout_data'] ?? null) ? $payload['checkout_data'] : [];

        $email = $this->firstEmail([
            $identity['guest_email'] ?? null,
            $context['guest_email'] ?? null,
            $context['email'] ?? null,
            $checkoutData['guest_email'] ?? null,
            $checkoutData['email'] ?? null,
            $checkoutData['customer_email'] ?? null,
            \is_array($checkoutData['shipping_address'] ?? null)
                ? ($checkoutData['shipping_address']['email'] ?? null)
                : null,
            \is_array($context['shipping_address'] ?? null)
                ? ($context['shipping_address']['email'] ?? null)
                : null,
        ]);

        $customerId = \max(
            0,
            (int)($identity['customer_id'] ?? 0),
            (int)($identity['authenticated_customer_id'] ?? 0),
            (int)($context['customer_id'] ?? 0),
            (int)($checkoutData['customer_id'] ?? 0),
        );

        if ($email === '' && $customerId <= 0) {
            return null;
        }

        $cartType = \strtolower(\trim((string)(
            $checkoutData['cart_type']
            ?? $checkoutData['selling_mode']
            ?? $context['cart_type']
            ?? $context['selling_mode']
            ?? 'toc'
        )));

        return [
            'email' => $email,
            'customer_id' => $customerId,
            'website_id' => \max(0, (int)($context['website_id'] ?? $checkoutData['website_id'] ?? 0)),
            'cart_type' => $cartType !== '' ? $cartType : 'toc',
            'selling_mode' => $cartType !== '' ? $cartType : 'toc',
        ];
    }

    /**
     * @param list<mixed> $candidates
     */
    private function firstEmail(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $email = \strtolower(\trim((string)$candidate));
            if ($email !== '' && \filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }
}
