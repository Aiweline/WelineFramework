<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Points\Api\PointsDiscountSettlementInterface;
use Weline\Points\Model\DiscountApplyRecord;

/**
 * Owns the points-discount reservation lifecycle through CustomerAsset.
 */
final class PointsDiscountSettlementService implements PointsDiscountSettlementInterface
{
    public function __construct(
        private readonly CustomerAssetFacadeInterface $assets,
    ) {
    }

    public function reserveForOrder(
        string $orderUuid,
        int $customerId,
        int $websiteId,
        string $namespace,
        int $points,
        int $applyCheckoutMinor,
        int $applyBaseMinor,
        string $checkoutCurrency,
        string $idempotencyKey,
    ): array {
        $orderUuid = trim($orderUuid);
        $points = max(0, $points);
        if ($orderUuid === '' || $customerId <= 0 || $points <= 0) {
            return ['ok' => false, 'error' => 'points_discount_reserve_invalid'];
        }

        $existing = $this->findByOrder($orderUuid);
        if ($existing !== null) {
            $status = (string) $existing[DiscountApplyRecord::schema_fields_STATUS];
            if (in_array($status, [
                DiscountApplyRecord::STATUS_RESERVED,
                DiscountApplyRecord::STATUS_COMMITTED,
            ], true)) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'points' => (int) $existing[DiscountApplyRecord::schema_fields_POINTS],
                    'apply_checkout_minor' => (int) $existing[DiscountApplyRecord::schema_fields_APPLY_CHECKOUT_MINOR],
                ];
            }
            if ($status !== DiscountApplyRecord::STATUS_RELEASED) {
                return ['ok' => false, 'error' => 'points_discount_already_finalized'];
            }
        }

        $eventId = 'points:discount:reserve:' . $orderUuid
            . ($existing !== null ? ':retry:' . bin2hex(random_bytes(4)) : '');
        try {
            $reserved = $this->assets->reserve([
                'customer_id' => $customerId,
                'website_id' => $websiteId,
                'asset_code' => PointsService::ASSET_CODE,
                'namespace' => $namespace,
                'amount_minor' => $points,
                'event_id' => $eventId,
            ]);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => 'points_discount_reserve_failed:' . $exception->getMessage()];
        }

        $reservationId = (string) ($reserved['reservation']['reservation_id'] ?? '');
        if ($reservationId === '') {
            return ['ok' => false, 'error' => 'points_discount_reservation_missing'];
        }

        $now = date('Y-m-d H:i:s');
        try {
            $payload = [
                DiscountApplyRecord::schema_fields_ORDER_UUID => $orderUuid,
                DiscountApplyRecord::schema_fields_CUSTOMER_ID => $customerId,
                DiscountApplyRecord::schema_fields_WEBSITE_ID => max(0, $websiteId),
                DiscountApplyRecord::schema_fields_NAMESPACE => $namespace,
                DiscountApplyRecord::schema_fields_CHECKOUT_CURRENCY => strtoupper(trim($checkoutCurrency)),
                DiscountApplyRecord::schema_fields_POINTS => $points,
                DiscountApplyRecord::schema_fields_APPLY_CHECKOUT_MINOR => max(0, $applyCheckoutMinor),
                DiscountApplyRecord::schema_fields_APPLY_BASE_MINOR => max(0, $applyBaseMinor),
                DiscountApplyRecord::schema_fields_RESERVATION_ID => $reservationId,
                DiscountApplyRecord::schema_fields_RETURNED_POINTS => 0,
                DiscountApplyRecord::schema_fields_COMMITTED_POINTS => 0,
                DiscountApplyRecord::schema_fields_STATUS => DiscountApplyRecord::STATUS_RESERVED,
                DiscountApplyRecord::schema_fields_UPDATED_AT => $now,
            ];
            if ($existing !== null) {
                ObjectManager::getInstance(DiscountApplyRecord::class)
                    ->clear()
                    ->load((int) $existing[DiscountApplyRecord::schema_fields_ID])
                    ->setData($payload)
                    ->save();
            } else {
                ObjectManager::getInstance(DiscountApplyRecord::class)
                    ->clearData()
                    ->setData($payload + [DiscountApplyRecord::schema_fields_CREATED_AT => $now])
                    ->save();
            }
        } catch (\Throwable $exception) {
            try {
                $this->assets->release($reservationId, $eventId . ':compensate');
            } catch (\Throwable) {
            }

            return ['ok' => false, 'error' => 'points_discount_record_failed:' . $exception->getMessage()];
        }

        return [
            'ok' => true,
            'idempotent' => false,
            'points' => $points,
            'apply_checkout_minor' => max(0, $applyCheckoutMinor),
            'reservation_id' => $reservationId,
        ];
    }

    public function commitForOrder(string $orderUuid, string $idempotencyKey): array
    {
        $record = $this->findByOrder($orderUuid);
        if ($record === null) {
            return ['ok' => true, 'idempotent' => true];
        }
        $status = (string) $record[DiscountApplyRecord::schema_fields_STATUS];
        if ($status === DiscountApplyRecord::STATUS_COMMITTED) {
            return ['ok' => true, 'idempotent' => true];
        }
        if ($status !== DiscountApplyRecord::STATUS_RESERVED) {
            return ['ok' => false, 'error' => 'points_discount_not_reserved'];
        }

        $reservationId = (string) $record[DiscountApplyRecord::schema_fields_RESERVATION_ID];
        try {
            $this->assets->commit($reservationId, 'points:discount:commit:' . trim($orderUuid));
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => 'points_discount_commit_failed:' . $exception->getMessage()];
        }

        $this->updateRecord($record, [
            DiscountApplyRecord::schema_fields_STATUS => DiscountApplyRecord::STATUS_COMMITTED,
            DiscountApplyRecord::schema_fields_COMMITTED_POINTS =>
                (int) $record[DiscountApplyRecord::schema_fields_POINTS],
            DiscountApplyRecord::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'idempotent' => false];
    }

    public function releaseForOrder(string $orderUuid, string $idempotencyKey): array
    {
        $record = $this->findByOrder($orderUuid);
        if ($record === null) {
            return ['ok' => true, 'idempotent' => true];
        }
        $status = (string) $record[DiscountApplyRecord::schema_fields_STATUS];
        if ($status === DiscountApplyRecord::STATUS_RELEASED) {
            return ['ok' => true, 'idempotent' => true];
        }
        if ($status !== DiscountApplyRecord::STATUS_RESERVED) {
            return ['ok' => true, 'idempotent' => true];
        }

        try {
            $this->assets->release(
                (string) $record[DiscountApplyRecord::schema_fields_RESERVATION_ID],
                'points:discount:release:' . trim($orderUuid),
            );
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => 'points_discount_release_failed:' . $exception->getMessage()];
        }

        $this->updateRecord($record, [
            DiscountApplyRecord::schema_fields_STATUS => DiscountApplyRecord::STATUS_RELEASED,
            DiscountApplyRecord::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'idempotent' => false];
    }

    public function returnForRefund(
        string $orderUuid,
        int $refundCheckoutMinor,
        int $orderTotalCheckoutMinor,
        string $idempotencyKey,
    ): array {
        $record = $this->findByOrder($orderUuid);
        if ($record === null) {
            return ['ok' => true, 'returned_points' => 0];
        }
        $status = (string) $record[DiscountApplyRecord::schema_fields_STATUS];
        if (!in_array($status, [
            DiscountApplyRecord::STATUS_COMMITTED,
            DiscountApplyRecord::STATUS_RETURNED,
        ], true)) {
            return ['ok' => true, 'returned_points' => 0];
        }

        $points = (int) $record[DiscountApplyRecord::schema_fields_POINTS];
        $returned = (int) $record[DiscountApplyRecord::schema_fields_RETURNED_POINTS];
        $remaining = max(0, $points - $returned);
        $refundCheckoutMinor = max(0, $refundCheckoutMinor);
        $orderTotalCheckoutMinor = max(0, $orderTotalCheckoutMinor);
        if ($remaining <= 0 || $refundCheckoutMinor <= 0) {
            return ['ok' => true, 'returned_points' => 0];
        }

        $toReturn = ($orderTotalCheckoutMinor <= 0 || $refundCheckoutMinor >= $orderTotalCheckoutMinor)
            ? $remaining
            : min($remaining, intdiv($points * $refundCheckoutMinor, $orderTotalCheckoutMinor));
        if ($toReturn <= 0) {
            return ['ok' => true, 'returned_points' => 0];
        }

        try {
            $this->assets->returnCommitted(
                (string) $record[DiscountApplyRecord::schema_fields_RESERVATION_ID],
                $toReturn,
                'points:discount:return:' . trim($orderUuid) . ':' . trim($idempotencyKey),
            );
        } catch (\Throwable $exception) {
            return ['ok' => false, 'returned_points' => 0, 'error' => 'points_discount_return_failed:' . $exception->getMessage()];
        }

        $returned += $toReturn;
        $this->updateRecord($record, [
            DiscountApplyRecord::schema_fields_RETURNED_POINTS => $returned,
            DiscountApplyRecord::schema_fields_STATUS => $returned >= $points
                ? DiscountApplyRecord::STATUS_RETURNED
                : DiscountApplyRecord::STATUS_COMMITTED,
            DiscountApplyRecord::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'returned_points' => $toReturn];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrder(string $orderUuid): ?array
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return null;
        }
        $row = ObjectManager::getInstance(DiscountApplyRecord::class)
            ->clear()
            ->where(DiscountApplyRecord::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetchArray();

        return \is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $data
     */
    private function updateRecord(array $record, array $data): void
    {
        ObjectManager::getInstance(DiscountApplyRecord::class)
            ->clear()
            ->load((int) $record[DiscountApplyRecord::schema_fields_ID])
            ->setData($data)
            ->save();
    }
}
