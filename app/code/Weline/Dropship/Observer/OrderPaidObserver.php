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
        if ($orderUuid === '') {
            $order = $data['order'] ?? null;
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

        $websiteId = 0;
        $storeId = 0;
        $order = $data['order'] ?? null;
        if (is_object($order) && method_exists($order, 'getData')) {
            $websiteId = (int)$order->getData('website_id');
            $storeId = (int)$order->getData('store_id');
        }

        $shipping = [];
        if (is_object($order) && method_exists($order, 'getData')) {
            $addr = $order->getData('shipping_address');
            $shipping = is_array($addr) ? $addr : (is_object($addr) && method_exists($addr, 'getData') ? (array)$addr->getData() : []);
        }
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
        $rawLines = [];
        if (is_object($order) && method_exists($order, 'getItems')) {
            $rawLines = (array)$order->getItems();
        } elseif (is_object($order) && method_exists($order, 'getData')) {
            $rawLines = (array)$order->getData('items');
        }

        $out = [];
        /** @var DropshipListing $listingModel */
        $listingModel = ObjectManager::getInstance(DropshipListing::class);
        foreach ($rawLines as $i => $line) {
            $offerId = 0;
            $qty = 1;
            if (is_object($line) && method_exists($line, 'getData')) {
                $offerId = (int)$line->getData('offer_id');
                $qty = (int)($line->getData('qty') ?: 1);
            } elseif (is_array($line)) {
                $offerId = (int)($line['offer_id'] ?? 0);
                $qty = (int)($line['qty'] ?? 1);
            }
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
                'local_offer_id' => $offerId,
                'qty' => $qty,
            ];
        }

        return $out;
    }
}
