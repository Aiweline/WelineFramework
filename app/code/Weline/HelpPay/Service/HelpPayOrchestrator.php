<?php

declare(strict_types=1);

namespace Weline\HelpPay\Service;

use Weline\Payment\Api\PaymentLinkServiceInterface;

/**
 * Orchestrates help_pay / selection_share / quick_pay_self without owning Cart/Checkout kernels.
 */
final class HelpPayOrchestrator
{
    public const CART_TYPE_TOC = 'toc';

    public function __construct(
        private readonly PaymentLinkServiceInterface $links,
        private readonly ShippingRedactionService $redaction = new ShippingRedactionService(),
    ) {
    }

    /**
     * @param array{
     *   owner_customer_id?:int|null,
     *   payable_type?:string,
     *   payable_id?:string,
     *   amount_minor:int,
     *   currency_code?:string,
     *   shipping_address:array<string,mixed>,
     *   address_confirmed:bool,
     *   rules_accepted:bool,
     *   cart_type?:string,
     *   line_summary?:list<array<string,mixed>>,
     *   public_origin?:string,
     *   ttl_seconds?:int
     * } $input
     * @return array<string,mixed>
     */
    public function createHelpPay(array $input): array
    {
        $this->assertTocOnly((string) ($input['cart_type'] ?? self::CART_TYPE_TOC));
        if (empty($input['rules_accepted'])) {
            throw new \InvalidArgumentException('helppay_rules_not_accepted');
        }
        if (empty($input['address_confirmed'])) {
            throw new \InvalidArgumentException('helppay_address_not_confirmed');
        }
        $shipping = is_array($input['shipping_address'] ?? null) ? $input['shipping_address'] : [];
        $this->redaction->assertCompleteShipping($shipping);

        $created = $this->links->create([
            'kind' => PaymentLinkServiceInterface::KIND_HELP_PAY,
            'payable_type' => (string) ($input['payable_type'] ?? 'order'),
            'payable_id' => (string) ($input['payable_id'] ?? ''),
            'owner_customer_id' => isset($input['owner_customer_id']) ? (int) $input['owner_customer_id'] : null,
            'amount_minor' => (int) ($input['amount_minor'] ?? 0),
            'currency_code' => (string) ($input['currency_code'] ?? 'USD'),
            'shipping_locked' => true,
            'shipping_snapshot' => $shipping,
            'meta' => [
                'line_summary' => $input['line_summary'] ?? [],
                'discounts_disabled' => true,
                'mode' => 'help_pay',
            ],
            'ttl_seconds' => (int) ($input['ttl_seconds'] ?? 86400 * 7),
        ], (string) ($input['public_origin'] ?? ''));

        return $this->shareDeliveryPayload($created);
    }

    /**
     * @param array{
     *   selection_snapshot:array<string,mixed>,
     *   owner_customer_id?:int|null,
     *   public_origin?:string,
     *   ttl_seconds?:int,
     *   cart_type?:string
     * } $input
     * @return array<string,mixed>
     */
    public function createSelectionShare(array $input): array
    {
        $this->assertTocOnly((string) ($input['cart_type'] ?? self::CART_TYPE_TOC));
        $snapshot = is_array($input['selection_snapshot'] ?? null) ? $input['selection_snapshot'] : [];
        if ($snapshot === []) {
            throw new \InvalidArgumentException('helppay_selection_empty');
        }

        $created = $this->links->create([
            'kind' => PaymentLinkServiceInterface::KIND_SELECTION_SHARE,
            'owner_customer_id' => isset($input['owner_customer_id']) ? (int) $input['owner_customer_id'] : null,
            'selection_snapshot' => $snapshot,
            'shipping_locked' => false,
            'meta' => ['mode' => 'selection_share'],
            'ttl_seconds' => (int) ($input['ttl_seconds'] ?? 86400 * 14),
        ], (string) ($input['public_origin'] ?? ''));

        return $this->shareDeliveryPayload($created);
    }

    /**
     * @param array{
     *   owner_customer_id?:int|null,
     *   payable_type?:string,
     *   payable_id?:string,
     *   amount_minor:int,
     *   currency_code?:string,
     *   shipping_address:array<string,mixed>,
     *   public_origin?:string,
     *   ttl_seconds?:int,
     *   cart_type?:string
     * } $input
     * @return array<string,mixed>
     */
    public function createQuickPay(array $input): array
    {
        $this->assertTocOnly((string) ($input['cart_type'] ?? self::CART_TYPE_TOC));
        $shipping = is_array($input['shipping_address'] ?? null) ? $input['shipping_address'] : [];
        $this->redaction->assertCompleteShipping($shipping);

        $serviceCode = trim((string) ($input['service_code'] ?? $shipping['service_code'] ?? ''));
        $serviceLabel = trim((string) ($input['service_label'] ?? $shipping['service_label'] ?? $shipping['label'] ?? ''));
        $shippingMinor = max(0, (int) ($input['shipping_amount_minor'] ?? $shipping['shipping_amount_minor'] ?? 0));
        $amountMinor = max(0, (int) ($input['amount_minor'] ?? 0));
        if (array_key_exists('goods_amount_minor', $input) || array_key_exists('goods_amount_minor', $shipping)) {
            $goodsMinor = max(0, (int) ($input['goods_amount_minor'] ?? $shipping['goods_amount_minor'] ?? 0));
            if (!array_key_exists('amount_minor', $input)) {
                $amountMinor = $goodsMinor + $shippingMinor;
            }
        } else {
            $goodsMinor = max(0, $amountMinor - $shippingMinor);
        }

        $snapshot = $shipping;
        if ($serviceCode !== '') {
            $snapshot['service_code'] = $serviceCode;
        }
        if ($serviceLabel !== '') {
            $snapshot['service_label'] = $serviceLabel;
            $snapshot['label'] = $serviceLabel;
        }
        if ($shippingMinor > 0 || array_key_exists('shipping_amount_minor', $input) || array_key_exists('shipping_amount_minor', $shipping)) {
            $snapshot['shipping_amount_minor'] = $shippingMinor;
        }

        $created = $this->links->create([
            'kind' => PaymentLinkServiceInterface::KIND_QUICK_PAY,
            'payable_type' => (string) ($input['payable_type'] ?? 'order'),
            'payable_id' => (string) ($input['payable_id'] ?? ''),
            'owner_customer_id' => isset($input['owner_customer_id']) ? (int) $input['owner_customer_id'] : null,
            'amount_minor' => $amountMinor,
            'currency_code' => (string) ($input['currency_code'] ?? 'USD'),
            // Popup checkout locks address + selected lane; does not write universal checkout session.
            'shipping_locked' => true,
            'shipping_snapshot' => $snapshot,
            'meta' => [
                'mode' => 'quick_pay_self',
                'session_isolation' => true,
                'service_code' => $serviceCode,
                'service_label' => $serviceLabel,
                'goods_amount_minor' => $goodsMinor,
                'shipping_amount_minor' => $shippingMinor,
                'line_summary' => $input['line_summary'] ?? [],
            ],
            // Align with help_pay default (7d): 1h TTL made验收/跨设备短链过早「链接不可用」。
            'ttl_seconds' => (int) ($input['ttl_seconds'] ?? 86400 * 7),
        ], (string) ($input['public_origin'] ?? ''));

        $payload = $this->shareDeliveryPayload($created);
        $payload['session_isolation'] = true;
        $payload['amount_minor'] = $amountMinor;
        $payload['goods_amount_minor'] = $goodsMinor;
        $payload['shipping_amount_minor'] = $shippingMinor;
        $payload['service_code'] = $serviceCode;
        $payload['service_label'] = $serviceLabel;

        return $payload;
    }

    /**
     * Payer-facing resolve: no shipping, discounts disabled, billing allowed.
     *
     * @return array<string,mixed>|null
     */
    public function resolveHelpPayForPayer(string $token): ?array
    {
        $row = $this->links->resolve($token, PaymentLinkServiceInterface::KIND_HELP_PAY);
        if ($row === null) {
            return null;
        }

        return $this->redaction->redactStorefront([
            'token' => $token,
            'payment_link_code' => $row['payment_link_code'] ?? '',
            'amount_minor' => $row['amount_minor'] ?? 0,
            'currency_code' => $row['currency_code'] ?? 'USD',
            'line_summary' => $row['meta']['line_summary'] ?? [],
            'discounts_allowed' => false,
            'shipping_locked' => true,
            'billing_editable' => true,
            'payable_type' => $row['payable_type'] ?? '',
            'payable_id' => $row['payable_id'] ?? '',
            'owner_customer_id' => $row['owner_customer_id'] ?? null,
            'session_isolation' => true,
            'load_payer_cart' => false,
        ], true);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveSelectionShare(string $token): ?array
    {
        return $this->links->resolve($token, PaymentLinkServiceInterface::KIND_SELECTION_SHARE);
    }

    private function assertTocOnly(string $cartType): void
    {
        if (strtolower(trim($cartType)) !== self::CART_TYPE_TOC) {
            throw new \InvalidArgumentException('helppay_toc_only');
        }
    }

    /**
     * @param array<string,mixed> $created
     * @return array<string,mixed>
     */
    private function shareDeliveryPayload(array $created): array
    {
        return [
            'ok' => true,
            'kind' => $created['kind'],
            'token' => $created['token'],
            'payment_link_code' => $created['payment_link_code'],
            'url' => $created['absolute_url'],
            'path' => $created['path'],
            'expires_at' => $created['expires_at'],
            'share_delivery' => [
                'copy_url' => true,
                'copy_qr_image' => true,
                'show_url' => true,
                'show_qr' => true,
            ],
        ];
    }
}
