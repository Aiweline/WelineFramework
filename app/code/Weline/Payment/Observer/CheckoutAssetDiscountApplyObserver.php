<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\AssetCheckoutDiscountQuote;

/**
 * Checkout asset_discount::apply — Payment 钳制并写入 type_payload；B2B 不写额度。
 */
final class CheckoutAssetDiscountApplyObserver implements ObserverInterface
{
    public const STATUS_PLANNED = 'planned';

    public function execute(Event &$event): void
    {
        $session = $event->getData('session');
        if (!is_array($session)) {
            return;
        }
        $cartType = strtolower(trim((string)($session['cart_type'] ?? $session['order_type'] ?? 'toc'))) ?: 'toc';
        if ($cartType !== 'tob') {
            return;
        }
        $quote = is_array($session['b2b_credit'] ?? null) ? $session['b2b_credit'] : null;
        if ($quote === null || empty($quote['enabled'])) {
            return;
        }
        $applyMinor = $event->getData('apply_minor');
        $requested = max(0, (int)($applyMinor ?? 0));
        if ($requested <= 0) {
            $session['b2b_credit_apply'] = null;
            $session['b2b_credit_type_payload'] = [];
            $session['b2b_credit_by_split'] = [];
            if (is_array($session['deposit'] ?? null)) {
                $session['deposit']['b2b_credit_cash_deposit_minor'] = (int)($session['deposit']['deposit_amount_minor'] ?? 0);
                $session['deposit']['b2b_credit_apply_checkout_minor'] = 0;
                $session['deposit']['b2b_credit_apply_base_minor'] = 0;
            }
            $event->setData('session', $session);

            return;
        }

        try {
            $svc = ObjectManager::getInstance(AssetCheckoutDiscountQuote::class);
            if (!$svc instanceof AssetCheckoutDiscountQuote) {
                return;
            }
            $websiteId = (int)($session['scope']['website_id'] ?? $quote['website_id'] ?? 0);
            $quote['website_id'] = $websiteId;
            $clamped = $svc->clampApply($quote, $requested);
            $fragment = $this->typePayloadFragment([
                'apply_checkout_minor' => $clamped['apply_checkout_minor'],
                'apply_base_minor' => $clamped['apply_base_minor'],
                'cash_deposit_minor' => $clamped['cash_deposit_minor'],
                'base_currency' => (string)($quote['base_currency'] ?? ''),
                'checkout_currency' => (string)($quote['checkout_currency'] ?? ''),
                'fx' => $clamped['fx'],
            ], self::STATUS_PLANNED, '');

            $depositMinors = [];
            $splitKeys = [];
            $ratio = (int)($session['deposit']['deposit_ratio_bps'] ?? 3000);
            foreach (is_array($session['orders'] ?? null) ? $session['orders'] : [] as $order) {
                if (!is_array($order)) {
                    continue;
                }
                $split = (string)($order['split_key'] ?? 'default');
                $goods = 0;
                foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $goods += max(0, (int)($item['qty_minor'] ?? 0)) * max(0, (int)($item['unit_price_minor'] ?? 0));
                }
                $depositMinors[] = intdiv($goods * max(0, $ratio), 10000);
                $splitKeys[] = $split;
            }
            if ($depositMinors === []) {
                $depositMinors = [(int)($quote['deposit_amount_minor'] ?? 0)];
                $splitKeys = ['default'];
            }
            $parts = $svc->splitAcrossDeposits($quote, $clamped['apply_checkout_minor'], $depositMinors, $websiteId);
            $bySplit = [];
            foreach ($parts as $i => $part) {
                $key = $splitKeys[$i] ?? ('split_' . $i);
                $bySplit[$key] = $this->typePayloadFragment([
                    'apply_checkout_minor' => $part['apply_checkout_minor'],
                    'apply_base_minor' => $part['apply_base_minor'],
                    'cash_deposit_minor' => $part['cash_deposit_minor'],
                    'base_currency' => (string)($quote['base_currency'] ?? ''),
                    'checkout_currency' => (string)($quote['checkout_currency'] ?? ''),
                    'fx' => $clamped['fx'],
                ], self::STATUS_PLANNED, '');
            }

            $session['b2b_credit_apply'] = $clamped;
            $session['b2b_credit_type_payload'] = $fragment;
            $session['b2b_credit_by_split'] = $bySplit;
            if (is_array($session['deposit'] ?? null)) {
                $session['deposit']['b2b_credit_cash_deposit_minor'] = $clamped['cash_deposit_minor'];
                $session['deposit']['b2b_credit_apply_checkout_minor'] = $clamped['apply_checkout_minor'];
                $session['deposit']['b2b_credit_apply_base_minor'] = $clamped['apply_base_minor'];
            }
            $event->setData('session', $session);
        } catch (\Throwable) {
            // Soft-fail: checkout without credit apply.
        }
    }

    /**
     * @param array<string,mixed> $apply
     * @return array<string,mixed>
     */
    private function typePayloadFragment(array $apply, string $status, string $reservationId): array
    {
        $fx = is_array($apply['fx'] ?? null) ? $apply['fx'] : null;

        return [
            'discount_kind' => 'asset_b2b_credit',
            'b2b_credit_asset_code' => AssetCheckoutDiscountQuote::ASSET_B2B_CREDIT,
            'b2b_credit_apply_checkout_minor' => max(0, (int)($apply['apply_checkout_minor'] ?? 0)),
            'b2b_credit_apply_base_minor' => max(0, (int)($apply['apply_base_minor'] ?? 0)),
            'b2b_credit_cash_deposit_minor' => max(0, (int)($apply['cash_deposit_minor'] ?? 0)),
            'fx_base_currency' => (string)($apply['base_currency'] ?? ''),
            'fx_checkout_currency' => (string)($apply['checkout_currency'] ?? ''),
            'fx_rate' => is_array($fx) ? (string)($fx['rate'] ?? '') : '',
            'fx_rate_label' => is_array($fx) ? (string)($fx['label'] ?? '') : '',
            'b2b_credit_status' => $status,
            'b2b_credit_reservation_id' => $reservationId,
        ];
    }
}
