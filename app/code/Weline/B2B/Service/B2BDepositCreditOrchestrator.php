<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\SystemVipLadder;
use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\Framework\Manager\ObjectManager;

/**
 * Tob deposit credit: reserve on pay start, commit on onDepositPaid, release on fail.
 * Wallet amounts are always website-default (base) currency.
 */
final class B2BDepositCreditOrchestrator
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_RELEASED = 'released';

    /** @var array<string, array<string,mixed>> */
    private array $memoryReservations = [];

    public function __construct(
        private readonly ?CustomerAssetFacadeInterface $assets = null,
        private readonly bool $memory = false,
    ) {
    }

    public static function forTesting(?CustomerAssetFacadeInterface $assets = null): self
    {
        return new self($assets, true);
    }

    /**
     * @param array{
     *   apply_base_minor:int,
     *   apply_checkout_minor:int,
     *   cash_deposit_minor:int,
     *   base_currency:string,
     *   checkout_currency:string,
     *   fx?:array<string,mixed>|null
     * } $apply
     * @return array{ok:bool,status:string,reservation_id:string,error?:string,type_payload:array<string,mixed>}
     */
    public function reserveForDeposit(
        string $customerId,
        int $websiteId,
        string $orderUuid,
        string $idempotencyKey,
        array $apply,
    ): array {
        $applyBase = max(0, (int)($apply['apply_base_minor'] ?? 0));
        $payload = $this->typePayloadFragment($apply, self::STATUS_PLANNED, '');
        if ($applyBase <= 0) {
            return [
                'ok' => true,
                'status' => self::STATUS_PLANNED,
                'reservation_id' => '',
                'type_payload' => $payload,
            ];
        }

        $eventId = $this->eventId($orderUuid, $idempotencyKey, 'reserve');
        if (isset($this->memoryReservations[$eventId])) {
            $prev = $this->memoryReservations[$eventId];

            return [
                'ok' => true,
                'status' => self::STATUS_RESERVED,
                'reservation_id' => (string)($prev['reservation_id'] ?? ''),
                'type_payload' => $this->typePayloadFragment($apply, self::STATUS_RESERVED, (string)($prev['reservation_id'] ?? '')),
            ];
        }

        try {
            $assets = $this->assets();
            if ($assets === null) {
                return [
                    'ok' => false,
                    'status' => self::STATUS_PLANNED,
                    'reservation_id' => '',
                    'error' => 'customer_asset_unavailable',
                    'type_payload' => $payload,
                ];
            }
            $result = $assets->reserve([
                'customer_id' => trim($customerId),
                'website_id' => $websiteId,
                'asset_code' => SystemVipLadder::ASSET_CODE_B2B_CREDIT,
                'namespace' => AssetAccount::NS_LIVE,
                'amount_minor' => $applyBase,
                'event_id' => $eventId,
            ]);
            $reservationId = (string)($result['reservation']['reservation_id'] ?? '');
            if ($reservationId === '') {
                return [
                    'ok' => false,
                    'status' => self::STATUS_PLANNED,
                    'reservation_id' => '',
                    'error' => 'reservation_id_missing',
                    'type_payload' => $payload,
                ];
            }
            $this->memoryReservations[$eventId] = [
                'reservation_id' => $reservationId,
                'order_uuid' => $orderUuid,
                'apply_base_minor' => $applyBase,
            ];

            return [
                'ok' => true,
                'status' => self::STATUS_RESERVED,
                'reservation_id' => $reservationId,
                'type_payload' => $this->typePayloadFragment($apply, self::STATUS_RESERVED, $reservationId),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => self::STATUS_PLANNED,
                'reservation_id' => '',
                'error' => $e->getMessage(),
                'type_payload' => $payload,
            ];
        }
    }

    /**
     * @param array<string,mixed> $typePayload existing order/hang type_payload
     * @return array{ok:bool,status:string,type_payload:array<string,mixed>}
     */
    public function commitOnDepositPaid(array $typePayload, string $orderUuid, string $idempotencyKey = ''): array
    {
        $reservationId = trim((string)($typePayload['b2b_credit_reservation_id'] ?? ''));
        $applyBase = max(0, (int)($typePayload['b2b_credit_apply_base_minor'] ?? 0));
        if ($applyBase <= 0 || $reservationId === '') {
            $typePayload['b2b_credit_status'] = self::STATUS_COMMITTED;

            return ['ok' => true, 'status' => self::STATUS_COMMITTED, 'type_payload' => $typePayload];
        }
        if (($typePayload['b2b_credit_status'] ?? '') === self::STATUS_COMMITTED) {
            return ['ok' => true, 'status' => self::STATUS_COMMITTED, 'type_payload' => $typePayload];
        }

        try {
            $assets = $this->assets();
            if ($assets === null) {
                return ['ok' => false, 'status' => (string)($typePayload['b2b_credit_status'] ?? ''), 'type_payload' => $typePayload];
            }
            $eventId = $this->eventId($orderUuid, $idempotencyKey !== '' ? $idempotencyKey : $reservationId, 'commit');
            $assets->commit($reservationId, $eventId);
            $typePayload['b2b_credit_status'] = self::STATUS_COMMITTED;

            return ['ok' => true, 'status' => self::STATUS_COMMITTED, 'type_payload' => $typePayload];
        } catch (\Throwable) {
            return ['ok' => false, 'status' => (string)($typePayload['b2b_credit_status'] ?? self::STATUS_RESERVED), 'type_payload' => $typePayload];
        }
    }

    /**
     * @param array<string,mixed> $typePayload
     * @return array{ok:bool,status:string,type_payload:array<string,mixed>}
     */
    public function releaseOnFailure(array $typePayload, string $orderUuid, string $idempotencyKey = ''): array
    {
        $reservationId = trim((string)($typePayload['b2b_credit_reservation_id'] ?? ''));
        $status = (string)($typePayload['b2b_credit_status'] ?? '');
        if ($reservationId === '' || $status === self::STATUS_COMMITTED || $status === self::STATUS_RELEASED) {
            $typePayload['b2b_credit_status'] = $status === self::STATUS_COMMITTED ? self::STATUS_COMMITTED : self::STATUS_RELEASED;

            return ['ok' => true, 'status' => (string)$typePayload['b2b_credit_status'], 'type_payload' => $typePayload];
        }
        try {
            $assets = $this->assets();
            if ($assets !== null) {
                $eventId = $this->eventId($orderUuid, $idempotencyKey !== '' ? $idempotencyKey : $reservationId, 'release');
                $assets->release($reservationId, $eventId);
            }
            $typePayload['b2b_credit_status'] = self::STATUS_RELEASED;

            return ['ok' => true, 'status' => self::STATUS_RELEASED, 'type_payload' => $typePayload];
        } catch (\Throwable) {
            return ['ok' => false, 'status' => $status, 'type_payload' => $typePayload];
        }
    }

    /**
     * @param array<string,mixed> $apply
     * @return array<string,mixed>
     */
    public function typePayloadFragment(array $apply, string $status, string $reservationId): array
    {
        $fx = is_array($apply['fx'] ?? null) ? $apply['fx'] : null;

        return [
            'discount_kind' => 'asset_b2b_credit',
            'b2b_credit_asset_code' => SystemVipLadder::ASSET_CODE_B2B_CREDIT,
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

    private function eventId(string $orderUuid, string $idempotencyKey, string $op): string
    {
        return 'b2b_credit:' . $op . ':' . trim($orderUuid) . ':' . trim($idempotencyKey);
    }

    private function assets(): ?CustomerAssetFacadeInterface
    {
        if ($this->assets instanceof CustomerAssetFacadeInterface) {
            return $this->assets;
        }
        if ($this->memory) {
            return null;
        }
        try {
            $resolved = ObjectManager::getInstance(CustomerAssetFacadeInterface::class);

            return $resolved instanceof CustomerAssetFacadeInterface ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
