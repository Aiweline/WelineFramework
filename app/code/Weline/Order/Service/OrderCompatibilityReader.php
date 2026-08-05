<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\Data\TaxSnapshot;
use Weline\Order\Api\OrderFacadeInterface;

/**
 * Compatibility reader：新旧订单只读聚合（DEC-023）。
 *
 * 不写 DML，也不跨模块读取内部 Model/Service。调用方将 legacy 只读行和
 * 新 Order UUID 显式交给聚合器；测试可通过 seedLegacy() 注入旧行。
 */
final class OrderCompatibilityReader
{
    public const SOURCE_LEGACY = 'legacy_checkout';
    public const SOURCE_NEW = 'new_order';
    public const ERROR_SOURCE = 'order_compatibility_source_invalid';

    /**
     * @var array<string, array<string, mixed>> keyed by legacy order_number or id
     */
    private array $legacy = [];

    public function __construct(
        private readonly ?OrderFacadeInterface $facade = null,
    ) {
    }

    public static function forTesting(?OrderFacadeInterface $facade = null): self
    {
        return new self($facade ?? OrderFacade::forTesting());
    }

    /** @param array<string, mixed> $row */
    public function seedLegacy(string $key, array $row): void
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('order_compatibility_legacy_key_required');
        }
        $this->legacy[$key] = $row;
    }

    /**
     * @return array{source:string,order:array<string,mixed>}|null
     */
    public function readUnified(string $key, string $prefer = self::SOURCE_NEW): ?array
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('order_compatibility_key_required');
        }
        $this->assertSource($prefer);

        return $this->select(
            $this->tryNew($key),
            $this->legacy[$key] ?? null,
            $prefer,
        );
    }

    /**
     * After cutover rollback of UI：new Order remains readable（DEC-023）.
     *
     * @param list<array<string, mixed>> $legacyRows
     * @param list<string> $newOrderUuids
     * @return list<array{source:string,order:array<string,mixed>}>
     */
    public function listReadable(
        array $legacyRows = [],
        array $newOrderUuids = [],
        string $prefer = self::SOURCE_NEW,
    ): array {
        $this->assertSource($prefer);

        $legacyByKey = $this->legacy;
        foreach ($legacyRows as $row) {
            $key = trim((string) ($row['order_uuid'] ?? $row['order_number'] ?? $row['id'] ?? ''));
            if ($key === '') {
                throw new \InvalidArgumentException('order_compatibility_legacy_key_required');
            }
            $legacyByKey[$key] = $row;
        }

        $keys = array_keys($legacyByKey);
        foreach ($newOrderUuids as $orderUuid) {
            $orderUuid = trim($orderUuid);
            if ($orderUuid === '') {
                throw new \InvalidArgumentException('order_compatibility_new_uuid_required');
            }
            $keys[] = $orderUuid;
        }

        $out = [];
        foreach (array_values(array_unique($keys)) as $key) {
            $unified = $this->select(
                $this->tryNew((string) $key),
                $legacyByKey[(string) $key] ?? null,
                $prefer,
            );
            if ($unified !== null) {
                $out[] = $unified;
            }
        }
        return $out;
    }

    private function tryNew(string $orderUuid): ?OrderReadResult
    {
        if ($this->facade === null) {
            return null;
        }
        try {
            return $this->facade->get($orderUuid);
        } catch (OrderFacadeConflictException $e) {
            if ($e->errorCode() === OrderFacade::ERROR_NOT_FOUND) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed>|null $legacy
     * @return array{source:string,order:array<string,mixed>}|null
     */
    private function select(?OrderReadResult $new, ?array $legacy, string $prefer): ?array
    {
        if ($prefer === self::SOURCE_NEW && $new !== null) {
            return ['source' => self::SOURCE_NEW, 'order' => $new->toArray()];
        }
        if ($prefer === self::SOURCE_LEGACY && $legacy !== null) {
            return [
                'source' => self::SOURCE_LEGACY,
                'order' => $this->normalizeLegacy($legacy),
            ];
        }
        if ($new !== null) {
            return ['source' => self::SOURCE_NEW, 'order' => $new->toArray()];
        }
        if ($legacy !== null) {
            return [
                'source' => self::SOURCE_LEGACY,
                'order' => $this->normalizeLegacy($legacy),
            ];
        }
        return null;
    }

    private function assertSource(string $source): void
    {
        if (!in_array($source, [self::SOURCE_NEW, self::SOURCE_LEGACY], true)) {
            throw new OrderFacadeConflictException(
                self::ERROR_SOURCE,
                \__('订单兼容读取来源无效：%{1}', [$source]),
                ['source' => $source],
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeLegacy(array $row): array
    {
        $currency = (string)($row['currency'] ?? 'CNY');
        $websiteId = (int)($row['website_id'] ?? 0);
        $storeId = (int)($row['store_id'] ?? 0);
        $money = is_array($row['money'] ?? null) ? $row['money'] : [
            'currency' => $currency,
            'subtotal_minor' => $this->decimalToMinor($row['subtotal'] ?? 0),
            'shipping_amount_minor' => $this->decimalToMinor($row['shipping_amount'] ?? 0),
            'tax_amount_minor' => $this->decimalToMinor($row['tax_amount'] ?? 0),
            'grand_total_minor' => $this->decimalToMinor($row['total_amount'] ?? 0),
        ];
        $tax = is_array($row['tax'] ?? null)
            ? TaxSnapshot::fromArray($row['tax'])
            : TaxSnapshot::legacyFrozen(
                (int) ($money['tax_amount_minor'] ?? 0),
                $currency,
                $websiteId,
                $storeId,
            );

        return [
            'order_uuid' => (string)($row['order_uuid'] ?? $row['order_number'] ?? ''),
            'checkout_group_uuid' => (string)($row['checkout_group_uuid'] ?? ''),
            'status' => (string)($row['status'] ?? 'pending'),
            'currency' => $currency,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'items' => is_array($row['items'] ?? null) ? $row['items'] : [],
            'money' => $money,
            'scope' => $row['scope'] ?? [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'currency' => $currency,
            ],
            'tax' => $tax->toArray(),
            'is_shipping_charge_owner' => (bool)($row['is_shipping_charge_owner'] ?? true),
            'number_kind' => (string)($row['number_kind'] ?? 'order'),
            'display_number' => $row['display_number'] ?? ($row['order_number'] ?? null),
            'source' => self::SOURCE_LEGACY,
        ];
    }

    private function decimalToMinor(mixed $amount): int
    {
        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException('order_compatibility_amount_invalid');
        }
        return (int) round(((float) $amount) * 100);
    }
}
