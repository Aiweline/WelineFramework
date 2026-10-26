<?php

declare(strict_types=1);

namespace Weline\Points\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Points\Service\PointsCheckoutApplySession;
use Weline\Points\Service\PointsCheckoutDiscountQuote;

/**
 * Checkout asset_discount::apply — clamp points and persist the planned apply
 * into the order type_payload source.
 */
final class CheckoutPointsDiscountApplyObserver implements ObserverInterface
{
    public const STATUS_PLANNED = 'planned';

    public function execute(Event &$event): void
    {
        $session = $event->getData('session');
        if (!is_array($session)) {
            return;
        }
        try {
            $applySession = ObjectManager::getInstance(PointsCheckoutApplySession::class);
        } catch (\Throwable) {
            return;
        }

        if (!empty($session['discounts_banned'])) {
            $applySession->clear();
            $this->clearSession($session);

            return;
        }

        $requested = max(0, (int) ($event->getData('apply_minor') ?? 0));
        if ($requested <= 0) {
            $applySession->clear();
            $this->clearSession($session);
            $event->setData('session', $session);

            return;
        }

        $customerId = (int) ($event->getData('customer_id') ?? $session['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return;
        }
        $websiteId = (int) ($session['scope']['website_id'] ?? 0);
        $currency = strtoupper(trim((string) ($session['currency'] ?? 'CNY'))) ?: 'CNY';
        $cartMinor = $this->cartAmountMinor($session);
        $namespace = strtolower(trim((string) ($session['scope']['namespace'] ?? $session['scope']['environment'] ?? 'live')));
        if (!in_array($namespace, ['live', 'sandbox'], true)) {
            $namespace = 'live';
        }

        try {
            $quoteService = ObjectManager::getInstance(PointsCheckoutDiscountQuote::class);
            $quote = $quoteService->quote((string) $customerId, $websiteId, $currency, $cartMinor, $namespace);
            if (empty($quote['enabled'])) {
                return;
            }
            $clamped = $quoteService->clampApply($quote, $requested);
            if ((int) $clamped['points'] <= 0) {
                $applySession->clear();
                $this->clearSession($session);
                $event->setData('session', $session);

                return;
            }

            $apply = [
                'points' => (int) $clamped['points'],
                'apply_checkout_minor' => (int) $clamped['apply_checkout_minor'],
                'apply_base_minor' => (int) $clamped['apply_base_minor'],
                'checkout_currency' => $currency,
                'base_currency' => (string) $quote['base_currency'],
                'exchange_ratio' => (float) $quote['exchange_ratio'],
            ];
            $applySession->save($apply);

            $session['points_discount_apply'] = $apply;
            $fragment = $this->typePayloadFragment($apply);
            $existing = is_array($session['b2b_credit_type_payload'] ?? null)
                ? $session['b2b_credit_type_payload']
                : [];
            $session['b2b_credit_type_payload'] = array_merge($existing, $fragment);
            $event->setData('session', $session);
        } catch (\Throwable) {
            // Soft-fail: checkout without points apply.
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function cartAmountMinor(array $session): int
    {
        $cartType = strtolower(trim((string) ($session['cart_type'] ?? $session['order_type'] ?? 'toc')));
        if ($cartType === 'tob') {
            $deposit = (int) ($session['deposit']['deposit_amount_minor'] ?? 0);
            if ($deposit > 0) {
                return $deposit;
            }
        }
        // quote.amount_minor is the shipping amount in Checkout V2; the payable
        // base lives in totals.grand_total_minor. Never use shipping as the cap.
        $fromTotals = (int) ($session['totals']['grand_total_minor'] ?? 0);
        if ($fromTotals > 0) {
            return $fromTotals;
        }
        $sum = 0;
        foreach (is_array($session['orders'] ?? null) ? $session['orders'] : [] as $order) {
            if (!is_array($order)) {
                continue;
            }
            foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $sum += max(0, (int) ($item['qty_minor'] ?? 0)) * max(0, (int) ($item['unit_price_minor'] ?? 0));
            }
        }
        if ($sum > 0) {
            return $sum;
        }

        return (int) ($session['quote']['total_amount_minor'] ?? 0);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function clearSession(array &$session): void
    {
        $session['points_discount_apply'] = null;
        if (is_array($session['b2b_credit_type_payload'] ?? null)) {
            foreach (array_keys($session['b2b_credit_type_payload']) as $key) {
                if (str_starts_with((string) $key, 'points_discount_')) {
                    unset($session['b2b_credit_type_payload'][$key]);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $apply
     * @return array<string, mixed>
     */
    private function typePayloadFragment(array $apply): array
    {
        return [
            'discount_kind' => 'asset_points',
            'points_asset_code' => 'points',
            'points_discount_points' => max(0, (int) ($apply['points'] ?? 0)),
            'points_discount_apply_checkout_minor' => max(0, (int) ($apply['apply_checkout_minor'] ?? 0)),
            'points_discount_apply_base_minor' => max(0, (int) ($apply['apply_base_minor'] ?? 0)),
            'points_discount_base_currency' => (string) ($apply['base_currency'] ?? ''),
            'points_discount_checkout_currency' => (string) ($apply['checkout_currency'] ?? ''),
            'points_discount_exchange_ratio' => (float) ($apply['exchange_ratio'] ?? 0),
            'points_discount_status' => self::STATUS_PLANNED,
        ];
    }
}
