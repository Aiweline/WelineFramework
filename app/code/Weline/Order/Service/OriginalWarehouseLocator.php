<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\FulfillmentUnit;

/**
 * Resolve immutable original-Warehouse provenance for one Order Offer.
 */
final class OriginalWarehouseLocator
{
    public const ERROR_BLOCKED_AUTHORIZATION = 'BLOCKED_AUTHORIZATION';

    /** @var (\Closure(): FulfillmentUnit)|null */
    private readonly ?\Closure $unitFactory;

    /** @param (callable(): FulfillmentUnit)|null $unitFactory */
    public function __construct(?callable $unitFactory = null)
    {
        $this->unitFactory = $unitFactory !== null
            ? \Closure::fromCallable($unitFactory)
            : null;
    }

    /**
     * @return array{warehouse_id:int,warehouse_source:string}|null
     */
    public function forOffer(string $orderUuid, int $offerId): ?array
    {
        $rows = $this->newUnit()
            ->where(FulfillmentUnit::schema_fields_ORDER_UUID, trim($orderUuid))
            ->order(FulfillmentUnit::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();
        $allWarehouseUnits = [];
        $matches = [];
        foreach ($rows->getItems() as $unit) {
            if (!$unit instanceof FulfillmentUnit) {
                continue;
            }
            $warehouseId = (int) $unit->getData(
                FulfillmentUnit::schema_fields_WAREHOUSE_ID,
            );
            if ($warehouseId <= 0) {
                continue;
            }
            $candidate = [
                'warehouse_id' => $warehouseId,
                'warehouse_source' => (string) (
                    $unit->getData(FulfillmentUnit::schema_fields_WAREHOUSE_SOURCE)
                    ?: WarehouseFulfillmentService::SOURCE_WAREHOUSE
                ),
            ];
            $allWarehouseUnits[$warehouseId . ':' . $candidate['warehouse_source']] = $candidate;
            $allocations = $this->decode(
                $unit->getData(FulfillmentUnit::schema_fields_ALLOCATIONS_JSON),
            );
            foreach ($allocations as $allocation) {
                if (\is_array($allocation)
                    && (int) ($allocation['offer_id'] ?? 0) === $offerId
                ) {
                    $matches[$warehouseId . ':' . $candidate['warehouse_source']] = $candidate;
                }
            }
        }
        if (count($matches) === 1) {
            return array_values($matches)[0];
        }
        if ($matches === [] && count($allWarehouseUnits) === 1) {
            return array_values($allWarehouseUnits)[0];
        }
        if ($matches === [] && $allWarehouseUnits === []) {
            return null;
        }

        throw new WarehouseFulfillmentConflictException(
            self::ERROR_BLOCKED_AUTHORIZATION,
            __('无法唯一证明退款行的原 Warehouse'),
            ['order_uuid' => $orderUuid, 'offer_id' => $offerId],
        );
    }

    /** @return list<mixed> */
    private function decode(mixed $value): array
    {
        if (!\is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? array_values($decoded) : [];
    }

    private function newUnit(): FulfillmentUnit
    {
        return $this->unitFactory !== null
            ? ($this->unitFactory)()
            : ObjectManager::create(FulfillmentUnit::class, [], false);
    }
}
