<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class CheckoutIdentityService
{
    public const MODE_CUSTOMER = 'customer';
    public const MODE_GUEST = 'guest';

    public function __construct(
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolve(array $context = []): array
    {
        $eventData = ['context' => $context];
        $this->dispatch('Weline_Checkout::checkout::identity::resolve::before', $eventData);
        $context = \is_array($eventData['context'] ?? null) ? $eventData['context'] : $context;

        $authenticatedCustomerId = max(0, (int) ($context['authenticated_customer_id'] ?? 0));
        $cartCustomerId = max(0, (int) ($context['cart_customer_id'] ?? $context['customer_id'] ?? 0));
        $customerId = max(0, (int) ($context['customer_id'] ?? $cartCustomerId ?: $authenticatedCustomerId));
        $guestAllowed = $this->toBool($context['guest_allowed'] ?? true);
        $customerAllowed = $this->toBool($context['customer_allowed'] ?? ($authenticatedCustomerId > 0));

        $mode = $this->normalizeMode($context['checkout_mode'] ?? $context['mode'] ?? '');
        if ($mode === '') {
            $mode = $this->normalizeMode($context['default_checkout_mode'] ?? '');
        }
        if ($mode === '') {
            $mode = $customerAllowed ? self::MODE_CUSTOMER : self::MODE_GUEST;
        }
        if ($mode === self::MODE_CUSTOMER && !$customerAllowed && $guestAllowed) {
            $mode = self::MODE_GUEST;
        }
        if ($mode === self::MODE_GUEST && !$guestAllowed && $customerAllowed) {
            $mode = self::MODE_CUSTOMER;
        }

        $identity = [
            'checkout_mode' => $mode,
            'is_guest_checkout' => $mode === self::MODE_GUEST,
            'customer_id' => $customerId,
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
            'guest_email' => trim((string) ($context['guest_email'] ?? $context['email'] ?? '')),
            'guest_allowed' => $guestAllowed,
            'customer_allowed' => $customerAllowed,
            'requires_guest_email' => $this->toBool($context['requires_guest_email'] ?? true),
        ];

        $eventData = [
            'context' => $context,
            'identity' => $identity,
        ];
        $this->dispatch('Weline_Checkout::checkout::identity::resolve::after', $eventData);

        return $this->normalizeIdentity(\is_array($eventData['identity'] ?? null) ? $eventData['identity'] : $identity);
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $checkoutData
     */
    public function validateGuestCheckout(array $identity, array $checkoutData = []): void
    {
        $errors = [];
        $eventData = [
            'identity' => $identity,
            'checkout_data' => $checkoutData,
            'errors' => &$errors,
        ];
        $this->dispatch('Weline_Checkout::checkout::guest::validate::before', $eventData);
        $identity = \is_array($eventData['identity'] ?? null) ? $eventData['identity'] : $identity;

        if (!empty($identity['is_guest_checkout']) && !empty($identity['requires_guest_email'])) {
            $guestEmail = trim((string) (
                $identity['guest_email']
                ?? $checkoutData['guest_email']
                ?? $checkoutData['email']
                ?? ''
            ));
            if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = (string) __('匿名结账需要填写有效邮箱。');
            }
        }

        $eventData = [
            'identity' => $identity,
            'checkout_data' => $checkoutData,
            'errors' => &$errors,
        ];
        $this->dispatch('Weline_Checkout::checkout::guest::validate::after', $eventData);

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', array_map('strval', $errors)));
        }
    }

    public function normalizeMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return match ($mode) {
            'guest', 'anonymous', 'anon', 'visitor' => self::MODE_GUEST,
            'customer', 'account', 'member', 'login', 'logged_in' => self::MODE_CUSTOMER,
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private function normalizeIdentity(array $identity): array
    {
        $mode = $this->normalizeMode($identity['checkout_mode'] ?? '');
        if ($mode === '') {
            $mode = !empty($identity['is_guest_checkout']) ? self::MODE_GUEST : self::MODE_CUSTOMER;
        }

        $identity['checkout_mode'] = $mode;
        $identity['is_guest_checkout'] = $mode === self::MODE_GUEST;
        $identity['guest_email'] = trim((string) ($identity['guest_email'] ?? ''));
        $identity['customer_id'] = max(0, (int) ($identity['customer_id'] ?? 0));
        $identity['cart_customer_id'] = max(0, (int) ($identity['cart_customer_id'] ?? $identity['customer_id']));
        $identity['authenticated_customer_id'] = max(0, (int) ($identity['authenticated_customer_id'] ?? 0));
        $identity['guest_allowed'] = $this->toBool($identity['guest_allowed'] ?? true);
        $identity['customer_allowed'] = $this->toBool($identity['customer_allowed'] ?? false);
        $identity['requires_guest_email'] = $this->toBool($identity['requires_guest_email'] ?? true);

        return $identity;
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (int) $value !== 0;
        }

        return \in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function dispatch(string $eventName, array &$eventData): void
    {
        $this->getEventsManager()->dispatch($eventName, $eventData);
    }

    private function getEventsManager(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }
}
