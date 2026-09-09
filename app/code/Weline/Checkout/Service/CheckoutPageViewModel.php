<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Cart\Service\CartService;
use Weline\Framework\Http\Cookie;

/**
 * Checkout page server view data.
 *
 * The browser never supplies item identity, name, quantity or price here.
 */
final class CheckoutPageViewModel
{
    /**
     * @return array{items:list<array<string,mixed>>,currency:string,is_empty:bool,item_count:int,subtotal:float,grand_total:float,discount_preview:?array}
     */
    public function currentCart(?string $guestToken = null): array
    {
        $guestToken = $this->resolveGuestToken($guestToken);
        $v2Params = $guestToken !== '' ? ['guest_token' => $guestToken] : [];
        $mode = strtolower(trim((string)Cookie::get('weline_selling_mode')));
        if ($mode === 'toc' || $mode === 'tob') {
            $v2Params['cart_type'] = $mode;
            $v2Params['selling_mode'] = $mode;
        }
        try {
            $v2Result = w_query('cart', 'getCart', $v2Params);
        } catch (\Throwable) {
            $v2Result = null;
        }

        $v2Cart = $this->fromQueryResult($v2Result);
        if (!$v2Cart['is_empty']) {
            return $v2Cart;
        }

        try {
            $legacyResult = w_query('cart', 'summary', $v2Params);
        } catch (\Throwable) {
            $legacyResult = null;
        }

        return $this->fromPreferredQueryResults($v2Result, $legacyResult);
    }

    /**
     * Prefer the durable V2 cart, while preserving the storefront V1 cart
     * compatibility path until all callers issue an OfferIdentity/guest token.
     *
     * @return array{items:list<array<string,mixed>>,currency:string,is_empty:bool,item_count:int,subtotal:float,grand_total:float,discount_preview:?array}
     */
    public function fromPreferredQueryResults(mixed $v2Result, mixed $legacyResult): array
    {
        $v2Cart = $this->fromQueryResult($v2Result);
        if (!$v2Cart['is_empty']) {
            return $v2Cart;
        }

        return $this->fromQueryResult($legacyResult);
    }

    /**
     * Normalize authoritative Cart minor-unit rows for checkout presentation.
     *
     * @return array{items:list<array<string,mixed>>,currency:string,is_empty:bool,item_count:int,subtotal:float,grand_total:float,discount_preview:?array}
     */
    public function fromQueryResult(mixed $result): array
    {
        if (!\is_array($result)) {
            return $this->empty();
        }
        $cart = \is_array($result['data'] ?? null) ? $result['data'] : $result;
        $rawItems = \is_array($cart['items'] ?? null) ? $cart['items'] : [];
        $items = [];
        foreach ($rawItems as $item) {
            if (\is_array($item)) {
                $qty = (float)($item['qty'] ?? $item['quantity'] ?? 1);
                if (\array_key_exists('unit_price_minor', $item)) {
                    $item['price'] = ((int)$item['unit_price_minor']) / 100.0;
                }
                if (\array_key_exists('row_total_minor', $item)) {
                    $item['row_total'] = ((int)$item['row_total_minor']) / 100.0;
                } elseif (!\array_key_exists('row_total', $item)) {
                    $item['row_total'] = (float)($item['price'] ?? 0) * $qty;
                }
                $compareAtMinor = max(0, (int)($item['compare_at_minor'] ?? 0));
                $item['compare_at_minor'] = $compareAtMinor;
                if ($compareAtMinor > 0) {
                    $item['original_price'] = $compareAtMinor / 100.0;
                } else {
                    $item['original_price'] = (float)($item['original_price'] ?? 0);
                }
                $unitMinor = max(0, (int)($item['unit_price_minor'] ?? (int)round(((float)($item['price'] ?? 0)) * 100)));
                $item['has_deal'] = !empty($item['has_deal'])
                    || ($compareAtMinor > $unitMinor && $unitMinor > 0);
                $item['campaign_label'] = trim((string)($item['campaign_label'] ?? ''));
                $item['campaign_url'] = trim((string)($item['campaign_url'] ?? ''));
                $items[] = $item;
            }
        }
        $currency = strtoupper(trim((string)($cart['currency'] ?? 'CNY')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'CNY';
        }
        $subtotal = \array_key_exists('subtotal_minor', $cart)
            ? ((int)$cart['subtotal_minor']) / 100.0
            : (float)($cart['subtotal'] ?? 0);
        $grandTotal = \array_key_exists('grand_total_minor', $cart)
            ? ((int)$cart['grand_total_minor']) / 100.0
            : (float)($cart['grand_total'] ?? $subtotal);
        $discountPreview = \is_array($cart['discount_preview'] ?? null)
            ? $cart['discount_preview']
            : null;

        return [
            'items' => $items,
            'currency' => $currency,
            'is_empty' => $items === [],
            'item_count' => (int)($cart['item_count'] ?? \count($items)),
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            'discount_preview' => $discountPreview,
            'cart_type' => strtolower(trim((string)($cart['cart_type'] ?? 'toc'))) ?: 'toc',
            'checkout_blocked' => !empty($cart['checkout_blocked']) || $this->itemsHaveBlockingIssues($items),
            'line_issues' => \is_array($cart['line_issues'] ?? null) ? $cart['line_issues'] : [],
            'blocking_message' => trim((string)($cart['blocking_message'] ?? '')),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function itemsHaveBlockingIssues(array $items): bool
    {
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            if (\array_key_exists('found', $item) && empty($item['found'])) {
                return true;
            }
            if (\array_key_exists('sellable', $item) && empty($item['sellable'])) {
                return true;
            }
        }

        return false;
    }

    private function resolveGuestToken(?string $guestToken): string
    {
        $guestToken = trim((string)$guestToken);
        if ($guestToken !== '') {
            return $guestToken;
        }

        return trim((string)Cookie::get(CartService::GUEST_TOKEN_COOKIE));
    }

    /**
     * @return array{items:list<array<string,mixed>>,currency:string,is_empty:bool,item_count:int,subtotal:float,grand_total:float,discount_preview:?array}
     */
    private function empty(): array
    {
        return [
            'items' => [],
            'currency' => 'CNY',
            'is_empty' => true,
            'item_count' => 0,
            'subtotal' => 0.0,
            'grand_total' => 0.0,
            'discount_preview' => null,
            'cart_type' => 'toc',
            'checkout_blocked' => false,
            'line_issues' => [],
            'blocking_message' => '',
        ];
    }
}
