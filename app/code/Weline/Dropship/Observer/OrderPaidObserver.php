<?php

declare(strict_types=1);

namespace Weline\Dropship\Observer;

use Weline\Dropship\Model\DropshipListing;
use Weline\Dropship\Service\DropshipOutboxService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class OrderPaidObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $orderUuid = (string)($data['order_uuid'] ?? '');
        $order = $data['order'] ?? null;
        if ($orderUuid === '') {
            if (is_object($order) && method_exists($order, 'getData')) {
                $orderUuid = (string)$order->getData('uuid');
                if ($orderUuid === '') {
                    $orderUuid = (string)$order->getData('order_uuid');
                }
            }
        }
        if ($orderUuid === '') {
            return;
        }

        $lines = $this->extractDropshipLines($data);
        if ($lines === []) {
            return;
        }

        [$websiteId, $storeId] = $this->extractScopeIds($order);
        $shipping = $this->extractShippingAddress($order);

        /** @var DropshipOutboxService $outbox */
        $outbox = ObjectManager::getInstance(DropshipOutboxService::class);
        $outbox->admitCreateForOrder($orderUuid, $lines, $shipping, $websiteId, $storeId);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function extractDropshipLines(array $data): array
    {
        $order = $data['order'] ?? null;
        $orderUuid = (string)($data['order_uuid'] ?? '');
        if ($orderUuid === '' && is_object($order) && method_exists($order, 'getData')) {
            $orderUuid = (string)$order->getData('order_uuid');
        }

        $rawLines = $this->collectRawLines($order, $orderUuid);
        $out = [];
        /** @var DropshipListing $listingModel */
        $listingModel = ObjectManager::getInstance(DropshipListing::class);
        foreach ($rawLines as $i => $line) {
            $offerId = $this->lineOfferId($line);
            $qty = $this->lineQty($line);
            if ($offerId <= 0) {
                continue;
            }
            $listing = $listingModel->clear()
                ->where(DropshipListing::schema_fields_LOCAL_OFFER_ID, $offerId)
                ->find()
                ->fetch();
            if (!$listing || !$listing->getId()) {
                continue;
            }
            $out[] = [
                'line_key' => 'offer-' . $offerId . '-' . $i,
                'provider_code' => (string)$listing->getData(DropshipListing::schema_fields_PROVIDER_CODE),
                'external_sku' => (string)$listing->getData(DropshipListing::schema_fields_EXTERNAL_SKU),
                'external_spu' => (string)$listing->getData(DropshipListing::schema_fields_EXTERNAL_SPU),
                'local_offer_id' => $offerId,
                'qty' => $qty,
            ];
        }

        return $out;
    }

    /**
     * Facade Order 行真源在 catalog_snapshot_json / OrderItem；getItems() 常为空。
     *
     * @return list<mixed>
     */
    private function collectRawLines(mixed $order, string $orderUuid): array
    {
        if (is_object($order) && method_exists($order, 'getItems')) {
            $items = (array)$order->getItems();
            if ($items !== []) {
                return array_values($items);
            }
        }
        if (is_object($order) && method_exists($order, 'getData')) {
            $items = $order->getData('items');
            if (is_array($items) && $items !== []) {
                return array_values($items);
            }
            $catalog = $this->decodeJsonMap($order->getData('catalog_snapshot_json'));
            $lines = $catalog['lines'] ?? null;
            if (is_array($lines) && $lines !== []) {
                return array_values($lines);
            }
        }
        if ($orderUuid === '') {
            return [];
        }
        try {
            /** @var \Weline\Order\Model\OrderItem $itemModel */
            $itemModel = ObjectManager::getInstance(\Weline\Order\Model\OrderItem::class);
            $rows = $itemModel->clear()
                ->where('order_uuid', $orderUuid)
                ->select()
                ->fetch()
                ->getItems();
            $out = [];
            foreach ($rows as $row) {
                if (is_object($row) && method_exists($row, 'getData')) {
                    $out[] = $row->getData();
                } elseif (is_array($row)) {
                    $out[] = $row;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function lineOfferId(mixed $line): int
    {
        if (is_object($line) && method_exists($line, 'getData')) {
            return (int)$line->getData('offer_id');
        }
        if (is_array($line)) {
            return (int)($line['offer_id'] ?? 0);
        }

        return 0;
    }

    private function lineQty(mixed $line): int
    {
        $candidates = [];
        if (is_object($line) && method_exists($line, 'getData')) {
            $candidates = [
                $line->getData('qty'),
                $line->getData('qty_ordered'),
                $line->getData('qty_minor'),
            ];
        } elseif (is_array($line)) {
            $candidates = [
                $line['qty'] ?? null,
                $line['qty_ordered'] ?? null,
                $line['qty_minor'] ?? null,
            ];
        }
        foreach ($candidates as $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }
            $qty = (int)$raw;
            if ($qty > 0) {
                return $qty;
            }
        }

        return 1;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function extractScopeIds(mixed $order): array
    {
        $websiteId = 0;
        $storeId = 0;
        if (!is_object($order) || !method_exists($order, 'getData')) {
            return [$websiteId, $storeId];
        }
        $websiteId = (int)$order->getData('website_id');
        $storeId = (int)$order->getData('store_id');
        if ($websiteId > 0 || $storeId > 0) {
            return [$websiteId, $storeId];
        }
        $scope = $this->decodeJsonMap($order->getData('scope_snapshot_json'));

        return [
            (int)($scope['website_id'] ?? 0),
            (int)($scope['store_id'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractShippingAddress(mixed $order): array
    {
        if (!is_object($order) || !method_exists($order, 'getData')) {
            return [];
        }
        $addr = $this->normalizeAddress($order->getData('shipping_address'));
        if ($addr !== []) {
            return $addr;
        }
        $snap = $this->decodeJsonMap($order->getData('shipping_snapshot_json'));

        return $this->normalizeAddress($snap['address'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAddress(mixed $addr): array
    {
        if (is_array($addr)) {
            return $addr;
        }
        if (is_object($addr) && method_exists($addr, 'getData')) {
            $data = $addr->getData();

            return is_array($data) ? $data : [];
        }
        if (is_string($addr) && trim($addr) !== '') {
            return $this->decodeJsonMap($addr);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonMap(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
