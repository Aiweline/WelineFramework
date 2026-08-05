<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartSelectionHash;
use Weline\Cart\Api\CheckoutCartSnapshotInterface;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Converts the current Cart V2 state into server-authoritative Checkout lines.
 */
final class CheckoutCartSnapshotService implements CheckoutCartSnapshotInterface
{
    public const ERROR_EMPTY = 'checkout_cart_empty';
    public const ERROR_SCOPE = 'checkout_cart_scope_conflict';
    public const ERROR_IDENTITY = 'checkout_cart_identity_conflict';
    public const ERROR_LINE = 'checkout_cart_line_invalid';
    public const ERROR_SELLABILITY = 'checkout_cart_item_not_sellable';
    public const ERROR_QUANTITY = 'checkout_cart_quantity_conflict';
    public const ERROR_CURRENCY = 'checkout_cart_currency_conflict';

    public function __construct(
        private readonly CartV2Service $cart,
    ) {
    }

    public function freeze(
        ScopeIdentity $scope,
        ?string $guestToken = null,
        ?int $customerId = null,
    ): array {
        $customerId = $customerId !== null && $customerId > 0 ? $customerId : null;
        $summary = $this->cart->getCart($scope, $guestToken, $customerId);
        if ((string)($summary['scope_key'] ?? '') !== $scope->canonicalKey()) {
            throw new CartV2ConflictException(
                self::ERROR_SCOPE,
                __('当前购物车与结账 Scope 不一致'),
            );
        }
        $expectedOwner = $customerId === null ? (string)$guestToken : (string)$customerId;
        if (!hash_equals((string)($summary['owner_id'] ?? ''), $expectedOwner)) {
            throw new CartV2ConflictException(
                self::ERROR_IDENTITY,
                __('当前购物车身份与结账身份不一致'),
            );
        }

        $cartCurrency = strtoupper(trim((string)($summary['currency'] ?? '')));
        $cartLines = is_array($summary['items'] ?? null) ? $summary['items'] : [];
        if ($cartLines === []) {
            throw new CartV2ConflictException(
                self::ERROR_EMPTY,
                __('购物车为空，无法结账'),
            );
        }

        $lines = [];
        foreach ($cartLines as $index => $line) {
            if (!is_array($line) || !is_array($line['offer'] ?? null)) {
                throw new CartV2ConflictException(
                    self::ERROR_LINE,
                    __('购物车行缺少 Offer 身份：%{1}', [$index]),
                );
            }
            $offer = OfferIdentity::fromArray($line['offer']);
            $selection = is_array($line['selection'] ?? null) ? $line['selection'] : [];
            $serverSelectionHash = CartSelectionHash::compute(
                $offer->globalOfferUuid,
                $offer->selectionSchemaVersion,
                $selection,
            );
            if (!hash_equals($serverSelectionHash, (string)($line['selection_hash'] ?? ''))) {
                throw new CartV2ConflictException(
                    self::ERROR_LINE,
                    __('购物车 selection hash 已失效，请重新加入商品'),
                    ['line_index' => $index],
                );
            }

            $snapshot = $this->cart->registry()->resolve($offer, $scope, $selection);
            if (!$snapshot->found || !$snapshot->sellable) {
                throw new CartV2ConflictException(
                    self::ERROR_SELLABILITY,
                    $snapshot->message !== '' ? $snapshot->message : __('商品已不可售'),
                    ['global_offer_uuid' => $offer->globalOfferUuid],
                );
            }
            $quantity = (int)($line['qty'] ?? 0);
            if ($quantity <= 0 || ($snapshot->stock !== null && $quantity > $snapshot->stock)) {
                throw new CartV2ConflictException(
                    self::ERROR_QUANTITY,
                    __('购物车数量超过当前可售数量'),
                    [
                        'global_offer_uuid' => $offer->globalOfferUuid,
                        'quantity' => $quantity,
                        'available' => $snapshot->stock,
                    ],
                );
            }
            $lineCurrency = strtoupper(trim($snapshot->currency));
            if ($lineCurrency === '' || ($cartCurrency !== '' && $cartCurrency !== $lineCurrency)) {
                throw new CartV2ConflictException(
                    self::ERROR_CURRENCY,
                    __('购物车币种与当前商品币种不一致'),
                    [
                        'cart_currency' => $cartCurrency,
                        'line_currency' => $lineCurrency,
                    ],
                );
            }
            $cartCurrency = $lineCurrency;
            $requiresShipping = $snapshot->requiresShipping
                ?? !in_array(strtolower($snapshot->productType), ['virtual', 'digital', 'downloadable'], true);
            $lineUuid = trim((string)($line['item_id'] ?? ''));
            if ($lineUuid === '') {
                $lineUuid = 'line-' . substr($serverSelectionHash, 0, 24);
            }

            $lines[] = [
                'line_uuid' => $lineUuid,
                'provider_code' => $offer->providerCode,
                'global_offer_uuid' => $offer->globalOfferUuid,
                'offer_id' => $snapshot->offerId ?? $offer->legacyProductId,
                'product_id' => $snapshot->productId ?? $offer->legacyProductId,
                'name' => $snapshot->name,
                'sku' => $snapshot->sku,
                'qty_minor' => $quantity,
                'unit_price_minor' => $snapshot->unitPriceMinor,
                'row_total_minor' => $quantity * $snapshot->unitPriceMinor,
                'currency' => $lineCurrency,
                'split_key' => trim($snapshot->splitKey) ?: 'default',
                'legal_entity' => trim($snapshot->legalEntity) ?: 'default',
                'requires_shipping' => $requiresShipping,
                'weight_minor' => max(0, $snapshot->weightMinor) * $quantity,
                'volume_minor' => max(0, $snapshot->volumeMinor) * $quantity,
                'tax_class_code' => trim($snapshot->taxClassCode) ?: 'standard',
                'selection' => CartSelectionHash::normalizeSelection($selection),
                'selection_hash' => $serverSelectionHash,
            ];
        }

        $canonical = [
            'scope' => $scope->toArray(),
            'currency' => $cartCurrency,
            'customer_id' => $customerId,
            'owner_kind' => (string)($summary['owner_kind'] ?? ''),
            'owner_id' => (string)($summary['owner_id'] ?? ''),
            'lines' => $lines,
        ];

        return $canonical + [
            'cart_hash' => hash(
                'sha256',
                json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            ),
        ];
    }
}
