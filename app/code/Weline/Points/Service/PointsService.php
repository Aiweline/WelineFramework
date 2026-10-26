<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderAssetAllocationSnapshotReaderInterface;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Points\Model\ClawbackRecord;
use Weline\Points\Model\EarnRecord;

/**
 * Earning and refund clawback orchestration.
 *
 * Spendable points live only in CustomerAsset asset_code=points. This service
 * owns rules, idempotency, growth, lifetime points and clawback liabilities.
 */
final class PointsService
{
    public const ASSET_CODE = 'points';
    public const REASON_MODE_OFF = 'customer_asset_mode_off';
    public const REASON_INSUFFICIENT = 'insufficient_points';
    public const REASON_RESERVE_FAILED = 'asset_reserve_failed';

    public function __construct(
        private readonly CustomerAssetFacadeInterface $assets,
        private readonly PointsConfig $config,
        private readonly EarnCalculator $calculator,
        private readonly VipLevelService $vip,
        private readonly OrderPointsContext $context = new OrderPointsContext(),
        private readonly PointsBaseCurrencyResolver $baseCurrency = new PointsBaseCurrencyResolver(),
        private readonly PointsProductEarnPolicy $productPolicy = new PointsProductEarnPolicy(),
        private readonly PointsItemEarnCalculator $itemCalculator = new PointsItemEarnCalculator(),
    ) {
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    public function awardForOrder(array $eventData): array
    {
        if (!$this->config->earnEnabled()) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'points_earn_disabled'];
        }

        $orderUuid = trim((string) ($eventData['order_uuid'] ?? ''));
        $orderRead = $this->readOrder($orderUuid);
        $assetTenderMinor = $this->assetTender($orderUuid, $orderRead);
        $context = $this->context->fromPaidEvent($eventData, $assetTenderMinor);

        if ((int) $context['order_id'] <= 0 || (int) $context['customer_id'] <= 0) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'guest_or_missing_customer'];
        }
        if ((string) $context['checkout_entry'] === 'helppay') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'helppay_does_not_earn_points'];
        }

        $base = $this->baseCurrency->convertMinorToBase(
            (int) $context['cash_minor'],
            (string) $context['currency'],
            (int) $context['website_id'],
            (int) $context['precision'],
        );
        if ($base === null) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'base_currency_fx_unavailable'];
        }

        $eventId = PointsEventId::earn(
            (int) $context['website_id'],
            (string) $context['namespace'],
            (int) $context['order_id'],
            (string) $context['payment_key'],
        );
        $existing = $this->findEarnByEventId($eventId);
        if ($existing !== null) {
            return ['ok' => true, 'idempotent' => true, 'event_id' => $eventId, 'record' => $existing];
        }

        $multiplier = 1.0;
        if ($this->config->vipEnabled() && $this->config->vipMultiplierEnabled()) {
            $benefits = $this->vip->benefitsFor(
                (int) $context['customer_id'],
                (int) $context['website_id'],
                (string) $context['namespace'],
            );
            $multiplier = (float) $benefits['points_multiplier'];
        }

        $orderLevel = $this->calculator->calculate(
            (int) $base['amount_minor'],
            (int) $base['precision'],
            $this->config->earnRate(),
            $this->config->growthRate(),
            $multiplier,
        );
        $growth = (int) $orderLevel['growth'];
        $points = (int) $orderLevel['points'];
        $itemsSnapshot = [];

        $items = \is_array($orderRead['items'] ?? null) ? $orderRead['items'] : [];
        if ($items !== []) {
            $lineResult = $this->itemCalculator->calculate(
                $items,
                (int) $context['total_minor'],
                (int) $context['cash_minor'],
                (string) $context['currency'],
                (int) $context['website_id'],
                (int) $context['precision'],
                (int) $base['precision'],
                $this->config->earnRate(),
                $multiplier,
                $this->config->productDefaultRatio(),
                $this->config->productDefaultFixedPoints(),
                fn (int $productId): array => $this->productPolicy->policyForProduct($productId),
            );
            if (($lineResult['ok'] ?? false) !== true) {
                return ['ok' => true, 'skipped' => true, 'reason' => (string) ($lineResult['reason'] ?? 'item_fx_unavailable')];
            }
            $points = (int) $lineResult['points'];
            $itemsSnapshot = $lineResult['items'];
        }

        if ($points <= 0 && $growth <= 0) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'below_one_major_unit'];
        }

        if ($points > 0) {
            try {
                $this->assets->credit([
                    'customer_id' => $context['customer_id'],
                    'website_id' => (int) $context['website_id'],
                    'asset_code' => self::ASSET_CODE,
                    'namespace' => (string) $context['namespace'],
                    'amount_minor' => $points,
                    'event_id' => $eventId,
                ]);
            } catch (\Throwable $exception) {
                $this->logFailure('points.credit_failed', $eventId, $exception);

                return [
                    'ok' => false,
                    'blocked' => true,
                    'reason' => 'customer_asset_credit_failed',
                    'error' => $exception->getMessage(),
                    'event_id' => $eventId,
                ];
            }
        }

        $record = $this->insertEarnRecord(
            $context,
            $base,
            $eventId,
            $multiplier,
            $points,
            $growth,
            $itemsSnapshot,
        );
        if ($growth > 0 || $points > 0) {
            $this->vip->applyGrowth(
                (int) $context['customer_id'],
                (int) $context['website_id'],
                (string) $context['namespace'],
                $growth,
                $this->config->vipEnabled(),
                $points,
            );
        }

        return [
            'ok' => true,
            'idempotent' => false,
            'event_id' => $eventId,
            'points' => $points,
            'growth' => $growth,
            'base_cash_minor' => (int) $base['amount_minor'],
            'base_currency' => (string) $base['currency'],
            'record' => $record,
            'calculation' => $orderLevel,
        ];
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    public function clawbackForRefund(array $eventData): array
    {
        $order = $eventData['order'] ?? null;
        $orderId = (int) ($eventData['order_id'] ?? $this->read($order, 'order_id', 0));
        if ($orderId <= 0) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'missing_order'];
        }

        $earn = $this->findLatestEarnByOrder($orderId);
        if ($earn === null) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'no_earn_record'];
        }

        $checkoutCash = (int) ($earn[EarnRecord::schema_fields_CHECKOUT_CASH_MINOR] ?? 0);
        if ($checkoutCash <= 0) {
            $checkoutCash = (int) $earn[EarnRecord::schema_fields_CASH_MINOR];
        }
        $checkoutCurrency = (string) ($earn[EarnRecord::schema_fields_CHECKOUT_CURRENCY]
            ?? $earn[EarnRecord::schema_fields_CURRENCY]);
        if ($checkoutCurrency === '') {
            $checkoutCurrency = (string) $earn[EarnRecord::schema_fields_CURRENCY];
        }

        $context = $this->context->fromRefundEvent($eventData, [
            'order_id' => $orderId,
            'customer_id' => (int) $earn[EarnRecord::schema_fields_CUSTOMER_ID],
            'website_id' => (int) $earn[EarnRecord::schema_fields_WEBSITE_ID],
            'namespace' => (string) $earn[EarnRecord::schema_fields_NAMESPACE],
            'precision' => (int) $earn[EarnRecord::schema_fields_PRECISION],
            'currency' => $checkoutCurrency,
            'cash_minor' => $checkoutCash,
            'payment_key' => (string) $earn[EarnRecord::schema_fields_PAYMENT_KEY],
        ]);

        $eventId = PointsEventId::clawback(
            (int) $context['website_id'],
            (string) $context['namespace'],
            $orderId,
            (string) $context['refund_key'],
        );
        $existing = $this->findClawbackByEventId($eventId);
        if ($existing !== null) {
            return ['ok' => true, 'idempotent' => true, 'event_id' => $eventId, 'record' => $existing];
        }

        $awarded = (int) $earn[EarnRecord::schema_fields_POINTS_AWARDED];
        $alreadyClawed = (int) $earn[EarnRecord::schema_fields_POINTS_CLAWED_BACK];
        $remaining = max(0, $awarded - $alreadyClawed);
        if ($remaining <= 0) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'nothing_left_to_clawback'];
        }

        $expected = $this->expectedClawback(
            $awarded,
            $checkoutCash,
            (int) $context['refund_amount_minor'],
            $remaining,
        );

        $actual = 0;
        $reason = '';
        if ($expected > 0) {
            [$actual, $reason] = $this->deductPoints(
                (int) $context['customer_id'],
                (int) $context['website_id'],
                (string) $context['namespace'],
                $expected,
                $eventId,
            );
        }

        $unrecovered = max(0, $expected - $actual);
        $record = $this->insertClawbackRecord(
            $earn,
            $context,
            $eventId,
            $expected,
            $actual,
            $unrecovered,
            $reason,
        );
        $this->updateEarnAfterClawback($earn, $actual);

        return [
            'ok' => true,
            'idempotent' => false,
            'event_id' => $eventId,
            'expected_points' => $expected,
            'actual_points' => $actual,
            'unrecovered_points' => $unrecovered,
            'record' => $record,
        ];
    }

    /**
     * @param array<string, mixed>|null $orderRead
     */
    private function assetTender(string $orderUuid, ?array $orderRead): int
    {
        $sum = 0;
        if ($orderUuid !== '') {
            try {
                $reader = ObjectManager::getInstance(OrderAssetAllocationSnapshotReaderInterface::class);
                if ($reader instanceof OrderAssetAllocationSnapshotReaderInterface) {
                    foreach ($reader->listCommittedAllocationsForOrder($orderUuid) as $row) {
                        if (\is_array($row)) {
                            $sum += max(0, (int) ($row['asset_amount_minor'] ?? 0));
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        $typePayload = \is_array($orderRead['type_payload'] ?? null) ? $orderRead['type_payload'] : [];
        $sum += max(0, (int) ($typePayload['points_discount_apply_checkout_minor'] ?? 0));

        return $sum;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readOrder(string $orderUuid): ?array
    {
        if ($orderUuid === '') {
            return null;
        }
        try {
            $facade = ObjectManager::getInstance(OrderFacadeInterface::class);
            if (!$facade instanceof OrderFacadeInterface) {
                return null;
            }

            return $facade->get($orderUuid)->toArray();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0:int, 1:string}
     */
    private function deductPoints(
        int $customerId,
        int $websiteId,
        string $namespace,
        int $expected,
        string $eventId,
    ): array {
        $balance = $this->assets->getBalance($customerId, $websiteId, self::ASSET_CODE, $namespace);
        $available = max(0, (int) ($balance['available_minor'] ?? 0));
        $actual = min($expected, $available);
        if ($actual <= 0) {
            return [0, self::REASON_INSUFFICIENT];
        }

        try {
            $reserve = $this->assets->reserve([
                'customer_id' => $customerId,
                'website_id' => $websiteId,
                'asset_code' => self::ASSET_CODE,
                'namespace' => $namespace,
                'amount_minor' => $actual,
                'event_id' => $eventId . ':reserve',
            ]);
            $reservationId = (string) ($reserve['reservation']['reservation_id'] ?? '');
            if ($reservationId === '') {
                return [0, self::REASON_RESERVE_FAILED];
            }
            $this->assets->commit($reservationId, $eventId . ':commit');

            return [$actual, ''];
        } catch (\Throwable $exception) {
            $this->logFailure('points.clawback_failed', $eventId, $exception);
            $message = $exception->getMessage();
            $reason = str_contains($message, 'mode_off')
                ? self::REASON_MODE_OFF
                : self::REASON_RESERVE_FAILED;

            return [0, $reason];
        }
    }

    private function expectedClawback(
        int $awarded,
        int $cashPaidMinor,
        int $refundCashMinor,
        int $remaining,
    ): int {
        if ($refundCashMinor <= 0) {
            return 0;
        }
        if ($cashPaidMinor <= 0 || $refundCashMinor >= $cashPaidMinor) {
            return $remaining;
        }

        return max(0, min($remaining, intdiv($awarded * $refundCashMinor, $cashPaidMinor)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEarnByEventId(string $eventId): ?array
    {
        $row = ObjectManager::getInstance(EarnRecord::class)
            ->clear()
            ->where(EarnRecord::schema_fields_EVENT_ID, $eventId)
            ->find()
            ->fetchArray();

        return \is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestEarnByOrder(int $orderId): ?array
    {
        $rows = ObjectManager::getInstance(EarnRecord::class)
            ->clear()
            ->where(EarnRecord::schema_fields_ORDER_ID, $orderId)
            ->order(EarnRecord::schema_fields_ID, 'desc')
            ->select()
            ->fetchArray();
        $row = \is_array($rows) ? ($rows[0] ?? null) : null;

        return \is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findClawbackByEventId(string $eventId): ?array
    {
        $row = ObjectManager::getInstance(ClawbackRecord::class)
            ->clear()
            ->where(ClawbackRecord::schema_fields_EVENT_ID, $eventId)
            ->find()
            ->fetchArray();

        return \is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $base
     * @param list<array<string, mixed>> $itemsSnapshot
     * @return array<string, mixed>
     */
    private function insertEarnRecord(
        array $context,
        array $base,
        string $eventId,
        float $multiplier,
        int $points,
        int $growth,
        array $itemsSnapshot,
    ): array {
        $now = date('Y-m-d H:i:s');
        try {
            ObjectManager::getInstance(EarnRecord::class)
                ->clearData()
                ->setData([
                    EarnRecord::schema_fields_EVENT_ID => $eventId,
                    EarnRecord::schema_fields_ORDER_ID => (int) $context['order_id'],
                    EarnRecord::schema_fields_ORDER_UUID => (string) $context['order_uuid'],
                    EarnRecord::schema_fields_PAYMENT_KEY => (string) $context['payment_key'],
                    EarnRecord::schema_fields_CUSTOMER_ID => (int) $context['customer_id'],
                    EarnRecord::schema_fields_WEBSITE_ID => (int) $context['website_id'],
                    EarnRecord::schema_fields_NAMESPACE => (string) $context['namespace'],
                    EarnRecord::schema_fields_CURRENCY => (string) $base['currency'],
                    EarnRecord::schema_fields_PRECISION => (int) $base['precision'],
                    EarnRecord::schema_fields_CASH_MINOR => (int) $base['amount_minor'],
                    EarnRecord::schema_fields_CHECKOUT_CURRENCY => (string) $context['currency'],
                    EarnRecord::schema_fields_CHECKOUT_CASH_MINOR => (int) $context['cash_minor'],
                    EarnRecord::schema_fields_BASE_CURRENCY => (string) $base['currency'],
                    EarnRecord::schema_fields_CASH_BASE_MINOR => (int) $base['amount_minor'],
                    EarnRecord::schema_fields_ITEMS_SNAPSHOT_JSON => json_encode(
                        $itemsSnapshot,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ) ?: '[]',
                    EarnRecord::schema_fields_POINTS_RATE => $this->config->earnRate(),
                    EarnRecord::schema_fields_POINTS_MULTIPLIER => $multiplier,
                    EarnRecord::schema_fields_GROWTH_RATE => $this->config->growthRate(),
                    EarnRecord::schema_fields_POINTS_AWARDED => $points,
                    EarnRecord::schema_fields_GROWTH_AWARDED => $growth,
                    EarnRecord::schema_fields_POINTS_CLAWED_BACK => 0,
                    EarnRecord::schema_fields_STATUS => EarnRecord::STATUS_AWARDED,
                    EarnRecord::schema_fields_CREATED_AT => $now,
                    EarnRecord::schema_fields_UPDATED_AT => $now,
                ])
                ->save();
        } catch (\Throwable $exception) {
            $existing = $this->findEarnByEventId($eventId);
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }

        return $this->findEarnByEventId($eventId) ?? [];
    }

    /**
     * @param array<string, mixed> $earn
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function insertClawbackRecord(
        array $earn,
        array $context,
        string $eventId,
        int $expected,
        int $actual,
        int $unrecovered,
        string $reason,
    ): array {
        try {
            ObjectManager::getInstance(ClawbackRecord::class)
                ->clearData()
                ->setData([
                    ClawbackRecord::schema_fields_EVENT_ID => $eventId,
                    ClawbackRecord::schema_fields_EARN_RECORD_ID => (int) $earn[EarnRecord::schema_fields_ID],
                    ClawbackRecord::schema_fields_ORDER_ID => (int) $context['order_id'],
                    ClawbackRecord::schema_fields_REFUND_KEY => (string) $context['refund_key'],
                    ClawbackRecord::schema_fields_CUSTOMER_ID => (int) $context['customer_id'],
                    ClawbackRecord::schema_fields_WEBSITE_ID => (int) $context['website_id'],
                    ClawbackRecord::schema_fields_NAMESPACE => (string) $context['namespace'],
                    ClawbackRecord::schema_fields_EXPECTED_POINTS => $expected,
                    ClawbackRecord::schema_fields_ACTUAL_POINTS => $actual,
                    ClawbackRecord::schema_fields_UNRECOVERED_POINTS => $unrecovered,
                    ClawbackRecord::schema_fields_REASON => $reason,
                    ClawbackRecord::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
                ])
                ->save();
        } catch (\Throwable $exception) {
            $existing = $this->findClawbackByEventId($eventId);
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }

        return $this->findClawbackByEventId($eventId) ?? [];
    }

    /**
     * @param array<string, mixed> $earn
     */
    private function updateEarnAfterClawback(array $earn, int $actual): void
    {
        $awarded = (int) $earn[EarnRecord::schema_fields_POINTS_AWARDED];
        $clawed = min($awarded, (int) $earn[EarnRecord::schema_fields_POINTS_CLAWED_BACK] + $actual);
        $status = $clawed <= 0
            ? EarnRecord::STATUS_AWARDED
            : ($clawed >= $awarded ? EarnRecord::STATUS_CLAWED_BACK : EarnRecord::STATUS_PARTIAL_CLAWBACK);

        ObjectManager::getInstance(EarnRecord::class)
            ->clear()
            ->load((int) $earn[EarnRecord::schema_fields_ID])
            ->setData([
                EarnRecord::schema_fields_POINTS_CLAWED_BACK => $clawed,
                EarnRecord::schema_fields_STATUS => $status,
                EarnRecord::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])
            ->save();
    }

    private function read(mixed $source, string $key, mixed $default): mixed
    {
        if (\is_array($source)) {
            return $source[$key] ?? $default;
        }
        if (\is_object($source) && method_exists($source, 'getData')) {
            return $source->getData($key) ?? $default;
        }

        return $default;
    }

    private function logFailure(string $code, string $eventId, \Throwable $exception): void
    {
        if (\function_exists('w_log_error')) {
            w_log_error($code . ': ' . $exception->getMessage(), [
                'event_id' => $eventId,
                'exception' => $exception::class,
            ], 'points');
        }
    }
}
