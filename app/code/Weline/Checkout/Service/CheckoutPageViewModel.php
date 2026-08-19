<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

/**
 * Checkout page server view data.
 *
 * The browser never supplies item identity, name, quantity or price here.
 */
final class CheckoutPageViewModel
{
    /**
     * @return array{items:list<array<string,mixed>>,currency:string,is_empty:bool,item_count:int,subtotal:float,grand_total:float}
     */
    public function currentCart(?string $guestToken = null): array
    {
        $guestToken = trim((string)$guestToken);
        $v2Params = $guestToken !== '' ? ['guest_token' => $guestToken] : [];
        try {
            $v2Result = w_query('cart', 'getV2Cart', $v2Params);
        } catch (\Throwable) {
            $v2Result = null;
        }

        $v2Cart = $this->fromQueryResult($v2Result);
        if (!$v2Cart['is_empty']) {
            return $v2Cart;
        }

        try {
            $legacyResult = w_query('cart', 'summary');
        } catch (\Throwable) {
            $legacyResult = null;
        }

        return $this->fromPreferredQueryResults($v2Result, $legacyResult);
    }

    /**
     * Prefer the durable V2 cart, while preserving the storefront V1 cart
     * compatibility path until all callers issue an OfferIdentity/guest token.
     *
     * @return array{items:list<array<string,mixed>>,currency:string,is_empty:bool,item_count:int,subtotal:float,grand_total:float}
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
     * Normalize authoritative Cart V2 minor-unit rows for checkout presentation.
     *
     * @return array{items:list<array<string,mixed>>,currency:string,is_empty:bool,item_count:int,subtotal:float,grand_total:float}
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

        return [
            'items' => $items,
            'currency' => $currency,
            'is_empty' => $items === [],
            'item_count' => (int)($cart['item_count'] ?? \count($items)),
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * @return array{items:list<array<string,mixed>>,currency:string,is_empty:bool,item_count:int,subtotal:float,grand_total:float}
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
        ];
    }
}
