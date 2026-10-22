<?php

declare(strict_types=1);

namespace Weline\Shipping\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\BuyerAftersaleReturnInstruction;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\WarehouseShippingOrigin;

/**
 * 售后申请批准后写入一条寄回指令。
 * 地址以事件里的裁决为准；跟订单走时只格式化给定履约仓。
 */
class BuyerAftersaleReturnInstructionObserver implements ObserverInterface
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    public function execute(Event &$event): void
    {
        try {
            $this->record($event);
        } catch (\Throwable $exception) {
            if ($this->isUniqueConflict($exception)) {
                return;
            }
            $this->persistFailed($event, $exception);
        }
    }

    private function record(Event $event): void
    {
        $resolution = $this->readString($event, 'resolution');
        if ($resolution === 'refund_only' || !\in_array($resolution, ['return', 'exchange'], true)) {
            return;
        }

        $requestUuid = $this->readString($event, 'request_uuid');
        if ($requestUuid === '') {
            return;
        }
        if ($this->findByRequestUuid($requestUuid) !== null) {
            return;
        }

        $this->insert(
            $requestUuid,
            $this->readString($event, 'order_uuid'),
            BuyerAftersaleReturnInstruction::STATUS_PENDING,
            $this->resolveReturnAddress($event),
            '',
        );
    }

    private function persistFailed(Event $event, \Throwable $exception): void
    {
        try {
            $requestUuid = $this->readString($event, 'request_uuid');
            if ($requestUuid === '' || $this->findByRequestUuid($requestUuid) !== null) {
                return;
            }
            $this->insert(
                $requestUuid,
                $this->readString($event, 'order_uuid'),
                BuyerAftersaleReturnInstruction::STATUS_FAILED,
                '',
                $this->clip($exception->getMessage()),
            );
        } catch (\Throwable) {
        }
    }

    private function insert(
        string $requestUuid,
        string $orderUuid,
        string $status,
        string $returnAddress,
        string $errorMessage,
    ): void {
        /** @var BuyerAftersaleReturnInstruction $row */
        $row = $this->objectManager->getInstance(BuyerAftersaleReturnInstruction::class, [], false);
        $row->setData([
            BuyerAftersaleReturnInstruction::schema_fields_REQUEST_UUID => $requestUuid,
            BuyerAftersaleReturnInstruction::schema_fields_ORDER_UUID => $orderUuid,
            BuyerAftersaleReturnInstruction::schema_fields_STATUS => $status,
            BuyerAftersaleReturnInstruction::schema_fields_RETURN_ADDRESS => $returnAddress,
            BuyerAftersaleReturnInstruction::schema_fields_TRACKING_NO => '',
            BuyerAftersaleReturnInstruction::schema_fields_ERROR_MESSAGE => $errorMessage,
            BuyerAftersaleReturnInstruction::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
        ])->save();
    }

    private function findByRequestUuid(string $requestUuid): ?BuyerAftersaleReturnInstruction
    {
        /** @var BuyerAftersaleReturnInstruction $model */
        $model = $this->objectManager->getInstance(BuyerAftersaleReturnInstruction::class, [], false);
        $items = $model->clear()
            ->where(BuyerAftersaleReturnInstruction::schema_fields_REQUEST_UUID, $requestUuid)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $row = \is_array($items) ? ($items[0] ?? null) : null;
        if (!$row instanceof BuyerAftersaleReturnInstruction || !(int) $row->getId()) {
            return null;
        }

        return $row;
    }

    /**
     * 商品或自定义地址已在事件里。跟订单走时只格式化这些履约仓，不取任意发货源。
     */
    private function resolveReturnAddress(Event $event): string
    {
        $given = $this->readString($event, 'return_address');
        if ($given !== '') {
            return $given;
        }
        $source = $this->readString($event, 'return_address_source');
        if ($source !== '' && $source !== 'order') {
            return '';
        }

        return $this->snapshotForWarehouses($this->readString($event, 'warehouse_ids'));
    }

    private function snapshotForWarehouses(string $warehouseIds): string
    {
        $ids = [];
        foreach (explode(',', $warehouseIds) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return '';
        }
        ksort($ids);
        try {
            $formatted = $this->addressesByWarehouse(array_values($ids));
        } catch (\Throwable) {
            return '';
        }
        $parts = [];
        foreach ($ids as $id) {
            $text = $formatted[$id] ?? '';
            if ($text !== '' && !\in_array($text, $parts, true)) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param list<int> $warehouseIds
     * @return array<int, string>
     */
    private function addressesByWarehouse(array $warehouseIds): array
    {
        /** @var WarehouseShippingOrigin $model */
        $model = $this->objectManager->getInstance(WarehouseShippingOrigin::class, [], false);
        $items = $model->clear()
            ->where(WarehouseShippingOrigin::schema_fields_WAREHOUSE_ID, $warehouseIds, 'IN')
            ->select()
            ->fetch()
            ->getItems();
        $chosen = [];
        foreach (\is_array($items) ? $items : [] as $origin) {
            if (!$origin instanceof WarehouseShippingOrigin) {
                continue;
            }
            $warehouseId = (int) $origin->getData(WarehouseShippingOrigin::schema_fields_WAREHOUSE_ID);
            if ($warehouseId <= 0) {
                continue;
            }
            $active = (int) $origin->getData(WarehouseShippingOrigin::schema_fields_IS_ACTIVE) === 1;
            if (isset($chosen[$warehouseId]) && !$active) {
                continue;
            }
            $addressId = (int) $origin->getData(WarehouseShippingOrigin::schema_fields_SHIPPING_ADDRESS_ID);
            if ($addressId <= 0) {
                continue;
            }
            /** @var ShippingAddress $address */
            $address = $this->objectManager->getInstance(ShippingAddress::class, [], false)->load($addressId);
            if (!(int) $address->getId()) {
                continue;
            }
            $text = $this->formatAddress($address);
            if ($text === '') {
                continue;
            }
            if (!isset($chosen[$warehouseId]) || $active) {
                $chosen[$warehouseId] = $text;
            }
        }

        return $chosen;
    }

    private function formatAddress(ShippingAddress $address): string
    {
        $parts = [];
        foreach ([
            ShippingAddress::schema_fields_CONTACT_NAME,
            ShippingAddress::schema_fields_CONTACT_PHONE,
            ShippingAddress::schema_fields_COUNTRY,
            ShippingAddress::schema_fields_PROVINCE,
            ShippingAddress::schema_fields_CITY,
            ShippingAddress::schema_fields_DISTRICT,
            ShippingAddress::schema_fields_STREET,
            ShippingAddress::schema_fields_POSTAL_CODE,
        ] as $field) {
            $value = \trim((string) $address->getData($field));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return \implode(' ', $parts);
    }

    private function readString(Event $event, string $key): string
    {
        $bag = $event->getData();
        $found = $this->dig($bag, $key);
        if ($found !== '') {
            return $found;
        }

        return $this->dig($event->getData('data'), $key);
    }

    private function dig(mixed $bag, string $key, int $depth = 0): string
    {
        if ($depth > 3 || $bag === null) {
            return '';
        }
        if ($bag instanceof \Weline\Framework\DataObject\DataObject) {
            $direct = $bag->getData($key);
            if (\is_scalar($direct) && \trim((string) $direct) !== '') {
                return \trim((string) $direct);
            }
            $nested = $bag->getData('data');
            if ($nested !== null && $nested !== $bag) {
                return $this->dig($nested, $key, $depth + 1);
            }

            return '';
        }
        if (!\is_array($bag)) {
            return '';
        }
        if (\array_key_exists($key, $bag) && \is_scalar($bag[$key]) && \trim((string) $bag[$key]) !== '') {
            return \trim((string) $bag[$key]);
        }
        foreach (['data', 'request', 'after_sale', 'buyer_after_sale', 'payload'] as $child) {
            if (!\array_key_exists($child, $bag)) {
                continue;
            }
            $found = $this->dig($bag[$child], $key, $depth + 1);
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    private function isUniqueConflict(\Throwable $exception): bool
    {
        $current = $exception;
        while ($current !== null) {
            $message = $current->getMessage();
            if (\str_contains($message, '23000')
                || \str_contains($message, 'Duplicate')
                || \str_contains($message, 'UNIQUE')
                || \str_contains($message, 'unique')
            ) {
                return true;
            }
            $current = $current->getPrevious();
        }

        return false;
    }

    private function clip(string $message): string
    {
        if (\function_exists('mb_substr')) {
            return \mb_substr($message, 0, 2000);
        }

        return \substr($message, 0, 2000);
    }
}
