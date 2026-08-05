<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Api\PaymentEffectOutboxProcessorInterface;

/**
 * Durable Order-side handler for Payment outbox effects.
 */
final class PaymentEffectConsumer
{
    public const ERROR_DISABLED = 'payment_effect_consumer_disabled';
    public const ERROR_UNKNOWN_EFFECT = 'payment_effect_unknown';
    public const ERROR_STORE_DENIED = 'payment_effect_store_denied';

    public const EFFECT_INVOICE = InvoiceService::EFFECT_TYPE;
    public const EFFECT_FULFILLMENT = FulfillmentService::EFFECT_TYPE;

    private bool $automaticEnabled = true;

    /** @var array<string, true> test-only fail-after-write seams */
    private array $failAfterWrite = [];

    public function __construct(
        private readonly PaymentEffectOutboxProcessorInterface $outbox,
        private readonly InvoiceService $invoices,
        private readonly FulfillmentService $fulfillments,
        private readonly TombstoneHistoricalResourcePolicy $historicalResources,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * Disables automatic scans only. Explicit outbox processing remains
     * available so already-created historical obligations can continue.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->automaticEnabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->automaticEnabled;
    }

    /**
     * Test-only seam: create the downstream row, then throw before outbox done.
     * The owning Payment transaction must roll the downstream row back.
     */
    public function failNext(string $effectKey): void
    {
        $effectKey = trim($effectKey);
        if ($effectKey !== '') {
            $this->failAfterWrite[$effectKey] = true;
        }
    }

    /** @return array<string, mixed> */
    public function processOne(string $outboxCode): array
    {
        return $this->outbox->process(
            $outboxCode,
            function (PaymentEffectRecord $effect): array {
                $action = match ($effect->effectType) {
                    self::EFFECT_INVOICE
                        => TombstoneHistoricalResourcePolicy::ACTION_INVOICE,
                    self::EFFECT_FULFILLMENT
                        => TombstoneHistoricalResourcePolicy::ACTION_FULFILLMENT,
                    default => throw new \RuntimeException(self::ERROR_UNKNOWN_EFFECT),
                };
                $storeId = $this->orderStoreId($effect->payableId);
                $decision = $this->historicalResources->assertAllowed(
                    $storeId,
                    $action,
                    $effect->effectKey,
                );
                if (empty($decision['allowed'])) {
                    throw new \RuntimeException(
                        (string)($decision['error_code'] ?? self::ERROR_STORE_DENIED),
                    );
                }
                $resourceMode = (string)($decision['resource_mode']
                    ?? TombstoneHistoricalResourcePolicy::RESOURCE_MODE_NORMAL);

                $result = match ($effect->effectType) {
                    self::EFFECT_INVOICE
                        => $this->invoices->ensureFromPaymentEffect($effect, $resourceMode),
                    self::EFFECT_FULFILLMENT
                        => $this->fulfillments->ensureActionFromPaymentEffect(
                            $effect,
                            $resourceMode,
                        ),
                };

                if (isset($this->failAfterWrite[$effect->effectKey])) {
                    unset($this->failAfterWrite[$effect->effectKey]);
                    throw new \RuntimeException(
                        'payment_effect_controlled_failure_after_write:' . $effect->effectKey,
                    );
                }

                return $result + [
                    'historical_audit_code' => $decision['audit_code'] ?? null,
                ];
            },
        );
    }

    /** @return list<array<string, mixed>> */
    public function processPending(int $limit = 20): array
    {
        if (!$this->automaticEnabled) {
            throw new \RuntimeException(self::ERROR_DISABLED);
        }

        $results = [];
        foreach ($this->outbox->pendingCodes(
            [self::EFFECT_INVOICE, self::EFFECT_FULFILLMENT],
            max(1, min(100, $limit)),
        ) as $outboxCode) {
            $results[] = $this->processOne($outboxCode);
        }

        return $results;
    }

    private function orderStoreId(string $orderUuid): int
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            throw new \RuntimeException('payment_effect_order_not_found');
        }
        /** @var Order $order */
        $order = $this->objectManager->getInstance(Order::class, [], false);
        $order->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        if (!$order->getId()) {
            throw new \RuntimeException('payment_effect_order_not_found');
        }
        $storeId = (int)$order->getData(Order::schema_fields_STORE_ID);
        if ($storeId <= 0) {
            throw new \RuntimeException(TombstoneHistoricalResourcePolicy::ERROR_STORE_UNKNOWN);
        }

        return $storeId;
    }
}
