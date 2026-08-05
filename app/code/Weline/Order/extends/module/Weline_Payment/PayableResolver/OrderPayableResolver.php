<?php

declare(strict_types=1);

namespace Weline\Order\Extends\Module\Weline_Payment\PayableResolver;

use Weline\Order\Api\Data\MoneySnapshot;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\Data\ScopeSnapshot;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Service\OrderFacade;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PayableContext;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Interface\PayableResolverInterface;
use Weline\Payment\Model\PaymentIntent;

/**
 * Order → Payment Payable（payable_type=weline_order / MOD-P2F-001）。
 * Money/scope 仅来自 Order 冻结快照；调用方不得覆盖金额。
 */
final class OrderPayableResolver implements PayableResolverInterface
{
    public const PAYABLE_TYPE = 'weline_order';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $memoryOrders = [];

    /** @var list<string> */
    private array $paidNotifications = [];

    private OrderFacadeInterface $orderFacade;

    /**
     * @param array<string, array<string, mixed>> $memoryOrders
     */
    public function __construct(
        OrderFacadeInterface $orderFacade,
        array $memoryOrders = [],
    ) {
        $this->orderFacade = $orderFacade;
        $this->memoryOrders = $memoryOrders;
    }

    /**
     * @param array<string, array<string, mixed>> $orders
     */
    public static function forTesting(array $orders = [], ?OrderFacadeInterface $orderFacade = null): self
    {
        return new self($orderFacade ?? OrderFacade::forTesting(), $orders);
    }

    public function getPayableType(): string
    {
        return self::PAYABLE_TYPE;
    }

    public function resolve(string $payableId, ?Actor $actor = null): PayableContext
    {
        $order = $this->loadOrder(trim($payableId));

        return PayableContext::fromArray([
            PayableContext::FIELD_PAYABLE_TYPE => self::PAYABLE_TYPE,
            PayableContext::FIELD_PAYABLE_ID => $order['order_uuid'],
            PayableContext::FIELD_ACTOR => $actor,
            PayableContext::FIELD_PAYLOAD => $order,
        ]);
    }

    public function snapshot(PayableContext $context): PayableSnapshot
    {
        $order = $context->getPayload();
        if ($order === []) {
            $order = $this->loadOrder($context->getPayableId());
        }

        $money = MoneySnapshot::fromArray(\is_array($order['money'] ?? null) ? $order['money'] : []);
        $scope = ScopeSnapshot::fromArray(\is_array($order['scope'] ?? null) ? $order['scope'] : [
            'website_id' => (int) ($order['website_id'] ?? 0),
            'store_id' => (int) ($order['store_id'] ?? 0),
            'currency' => (string) ($order['currency'] ?? $money->currency),
        ]);

        $currency = strtoupper($money->currency !== '' ? $money->currency : $scope->currency);
        $items = $this->normalizeItems(\is_array($order['items'] ?? null) ? $order['items'] : [], $currency);
        $paymentStatus = (string) ($order['payment_status'] ?? self::STATUS_PENDING);
        $status = (string) ($order['status'] ?? self::STATUS_PENDING);
        $version = (string) ($order['snapshot_version'] ?? hash('sha256', json_encode([
            'order_uuid' => $order['order_uuid'],
            'money' => $money->toArray(),
            'scope' => $scope->toArray(),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));

        $customerId = (string) ($order['customer_id'] ?? '');
        $owner = [
            'actor_type' => $customerId !== '' ? 'customer' : 'guest',
            'actor_id' => $customerId !== '' ? $customerId : (string) ($order['guest_token'] ?? 'anonymous'),
        ];

        return PayableSnapshot::fromArray([
            PayableSnapshot::FIELD_PAYABLE_TYPE => self::PAYABLE_TYPE,
            PayableSnapshot::FIELD_PAYABLE_ID => (string) $order['order_uuid'],
            'payable_code' => (string) ($order['display_number'] ?? $order['order_uuid']),
            PayableSnapshot::FIELD_VERSION => $version,
            'status' => $this->mapPayableStatus($status, $paymentStatus),
            'payment_status' => $paymentStatus,
            'order_status' => $status,
            PayableSnapshot::FIELD_OWNER => $owner,
            PayableSnapshot::FIELD_PAYER => $owner,
            PayableSnapshot::FIELD_ITEMS => $items,
            PayableSnapshot::FIELD_AMOUNT_MINOR => $money->grandTotalMinor,
            PayableSnapshot::FIELD_CURRENCY_CODE => $currency,
            PayableSnapshot::FIELD_PRECISION => 2,
            'amounts' => [
                'subtotal_amount_minor' => $money->subtotalMinor,
                'tax_amount_minor' => $money->taxAmountMinor,
                'shipping_amount_minor' => $money->shippingAmountMinor,
                'discount_amount_minor' => $money->discountAmountMinor,
                'asset_amount_minor' => 0,
                'payable_amount_minor' => $money->grandTotalMinor,
            ],
            PayableSnapshot::FIELD_COUNTRY_CODE => strtoupper((string) ($order['country_code'] ?? '')),
            PayableSnapshot::FIELD_LANGUAGE_CODE => (string) ($scope->locale !== '' ? $scope->locale : 'zh_Hans_CN'),
            PayableSnapshot::FIELD_TIMEZONE => (string) ($order['timezone'] ?? date_default_timezone_get()),
            'scope' => $scope->toArray(),
            'website_id' => $scope->websiteId,
            'store_id' => $scope->storeId,
            'refundable' => \in_array($paymentStatus, [self::STATUS_PAID, 'partial'], true),
            'business_tags' => ['weline_order', 'commerce'],
            'metadata' => [
                'checkout_group_uuid' => (string) ($order['checkout_group_uuid'] ?? ''),
                'is_shipping_charge_owner' => (bool) ($order['is_shipping_charge_owner'] ?? false),
                'number_kind' => (string) ($order['number_kind'] ?? 'order'),
                'display_number' => (string) ($order['display_number'] ?? ''),
            ],
        ]);
    }

    public function canPay(PayableSnapshot $snapshot, Actor $actor): bool
    {
        $status = (string) ($snapshot->getData('status') ?? '');
        if (!\in_array($status, ['open', 'pending', 'partially_paid'], true)) {
            return false;
        }
        if ($snapshot->getAmountMinor() < 0) {
            return false;
        }

        $policy = $this->getPayerPolicy($snapshot);
        if (!empty($policy['requires_authenticated_actor'])) {
            if (trim($actor->getActorType()) === '' || trim($actor->getActorId()) === '') {
                return false;
            }
        }

        $owner = $snapshot->getArray(PayableSnapshot::FIELD_OWNER);
        $ownerId = (string) ($owner['actor_id'] ?? '');
        if ($ownerId !== '' && $ownerId !== 'anonymous'
            && $actor->getActorType() === 'customer'
            && $actor->getActorId() !== ''
            && $actor->getActorId() !== $ownerId
        ) {
            return false;
        }

        return true;
    }

    public function canCancel(PayableSnapshot $snapshot): bool
    {
        return \in_array((string) ($snapshot->getData('status') ?? ''), ['open', 'pending'], true);
    }

    public function canRefund(RefundRequest $request): bool
    {
        return $request->getAmountMinor() > 0;
    }

    public function onPaid(PaymentIntent $intent): void
    {
        $payableId = (string) $intent->getData(PaymentIntent::schema_fields_PAYABLE_ID);
        $this->paidNotifications[] = $payableId;
        if (isset($this->memoryOrders[$payableId])) {
            $this->memoryOrders[$payableId]['payment_status'] = self::STATUS_PAID;
            $this->memoryOrders[$payableId]['status'] = self::STATUS_PAID;
        }
        if ($payableId !== '') {
            $this->orderFacade->notifyOrderPaid($payableId, [
                'intent_code' => (string) $intent->getData(PaymentIntent::schema_fields_INTENT_CODE),
                'amount_minor' => (int) $intent->getData(PaymentIntent::schema_fields_AMOUNT_MINOR),
                'currency_code' => (string) $intent->getData(PaymentIntent::schema_fields_CURRENCY_CODE),
            ]);
        }
    }

    public function onPartiallyPaid(PaymentIntent $intent): void
    {
        $payableId = (string) $intent->getData(PaymentIntent::schema_fields_PAYABLE_ID);
        if (isset($this->memoryOrders[$payableId])) {
            $this->memoryOrders[$payableId]['payment_status'] = 'partial';
        }
    }

    public function onRefunded(RefundResult $result): void
    {
    }

    public function onExpired(PaymentIntent $intent): void
    {
    }

    public function onRiskReview(PaymentIntent $intent): void
    {
    }

    public function releaseResources(PaymentIntent $intent, string $reason): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayerPolicy(PayableSnapshot $snapshot): array
    {
        $owner = $snapshot->getArray(PayableSnapshot::FIELD_OWNER);
        $requiresAuth = (($owner['actor_type'] ?? '') === 'customer');

        return [
            'allowed_actor_types' => ['customer', 'guest', 'admin'],
            'requires_authenticated_actor' => $requiresAuth,
            'frozen_scope' => $snapshot->getArray('scope'),
        ];
    }

    /**
     * @return string[]
     */
    public function getBusinessTags(PayableSnapshot $snapshot): array
    {
        $tags = $snapshot->getArray('business_tags');
        $tags[] = self::PAYABLE_TYPE;

        return array_values(array_unique(array_map('strval', $tags)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLineItems(PayableSnapshot $snapshot): array
    {
        return $snapshot->getItems();
    }

    /** @return list<string> */
    public function drainPaidNotifications(): array
    {
        $out = $this->paidNotifications;
        $this->paidNotifications = [];

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOrder(string $payableId): array
    {
        if ($payableId === '') {
            throw new \InvalidArgumentException('order_payable_id_required');
        }

        if (isset($this->memoryOrders[$payableId])) {
            return $this->normalizeOrderRow($this->memoryOrders[$payableId], $payableId);
        }

        $read = $this->orderFacade->get($payableId);

        return $this->fromReadResult($read);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeOrderRow(array $row, string $payableId): array
    {
        $row['order_uuid'] = (string) ($row['order_uuid'] ?? $payableId);
        $row['status'] = (string) ($row['status'] ?? self::STATUS_PENDING);
        $row['payment_status'] = (string) ($row['payment_status'] ?? self::STATUS_PENDING);
        $row['currency'] = (string) ($row['currency'] ?? 'CNY');
        $row['website_id'] = (int) ($row['website_id'] ?? 0);
        $row['store_id'] = (int) ($row['store_id'] ?? 0);
        if (!isset($row['money']) || !\is_array($row['money'])) {
            $row['money'] = (new MoneySnapshot(
                currency: $row['currency'],
                subtotalMinor: (int) ($row['subtotal_minor'] ?? 0),
                shippingAmountMinor: (int) ($row['shipping_amount_minor'] ?? 0),
                taxAmountMinor: (int) ($row['tax_amount_minor'] ?? 0),
                discountAmountMinor: (int) ($row['discount_amount_minor'] ?? 0),
                grandTotalMinor: (int) ($row['grand_total_minor'] ?? 0),
            ))->toArray();
        }
        if (!isset($row['scope']) || !\is_array($row['scope'])) {
            $row['scope'] = (new ScopeSnapshot(
                websiteId: $row['website_id'],
                storeId: $row['store_id'],
                currency: $row['currency'],
            ))->toArray();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromReadResult(OrderReadResult $read): array
    {
        return [
            'order_uuid' => $read->orderUuid,
            'checkout_group_uuid' => $read->checkoutGroupUuid,
            'status' => $read->status,
            'payment_status' => $read->status === self::STATUS_PAID ? self::STATUS_PAID : self::STATUS_PENDING,
            'currency' => $read->currency,
            'website_id' => $read->websiteId,
            'store_id' => $read->storeId,
            'customer_id' => $read->customerId,
            'items' => $read->items,
            'money' => $read->money,
            'scope' => $read->scope !== [] ? $read->scope : (new ScopeSnapshot(
                $read->websiteId,
                $read->storeId,
                $read->currency,
            ))->toArray(),
            'is_shipping_charge_owner' => $read->isShippingChargeOwner,
            'number_kind' => $read->numberKind,
            'display_number' => $read->displayNumber,
        ];
    }

    private function mapPayableStatus(string $orderStatus, string $paymentStatus): string
    {
        if ($paymentStatus === self::STATUS_PAID || $orderStatus === self::STATUS_PAID) {
            return 'paid';
        }
        if ($paymentStatus === 'partial') {
            return 'partially_paid';
        }
        if (\in_array($orderStatus, [self::STATUS_CANCELLED, self::STATUS_REFUNDED], true)) {
            return $orderStatus;
        }

        return 'open';
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(array $items, string $currency): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $qty = (int) ($item['qty_minor'] ?? $item['qty'] ?? 1);
            $amount = (int) ($item['row_total_minor'] ?? $item['amount_minor'] ?? $item['unit_price_minor'] ?? 0);
            $out[] = [
                'item_code' => (string) ($item['item_uuid'] ?? $item['offer_uuid'] ?? $item['sku'] ?? 'line'),
                'name' => (string) ($item['name'] ?? $item['product_name'] ?? 'item'),
                'quantity' => max(1, $qty),
                'amount_minor' => $amount,
                'currency_code' => $currency,
            ];
        }

        return $out;
    }
}
