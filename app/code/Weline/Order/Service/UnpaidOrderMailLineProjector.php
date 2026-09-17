<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;

/**
 * Projects unpaid-order mail line items (name/image/options/price) for Marketing signals.
 */
final class UnpaidOrderMailLineProjector
{
    public function __construct(
        private readonly ?BackendOrderLinePresenter $presenter = null,
    ) {
    }

    /**
     * @return list<array{
     *   name:string,sku:string,qty:float|int,unit_price:string,row_total:string,
     *   options_text:string,image_url:string
     * }>
     */
    public function project(Order $order): array
    {
        $persisted = [];
        try {
            /** @var OrderItem $model */
            $model = ObjectManager::getInstance(OrderItem::class);
            $orderId = (int)$order->getId();
            $orderUuid = \trim((string)$order->getData(Order::schema_fields_ORDER_UUID));
            $model->clear();
            if ($orderId > 0) {
                $model->where(OrderItem::schema_fields_ORDER_ID, $orderId);
            } elseif ($orderUuid !== '') {
                $model->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid);
            } else {
                return [];
            }
            $model->select()->fetch();
            foreach ($model->getItems() as $row) {
                if ($row instanceof OrderItem) {
                    $persisted[] = $row;
                }
            }
        } catch (\Throwable) {
            $persisted = [];
        }

        $presenter = $this->presenter;
        if ($presenter === null) {
            try {
                $presenter = ObjectManager::getInstance(BackendOrderLinePresenter::class);
            } catch (\Throwable) {
                $presenter = new BackendOrderLinePresenter();
            }
        }
        $presented = $presenter->present($order, $persisted);
        $currency = \strtoupper(\trim((string)$order->getData(Order::schema_fields_CURRENCY))) ?: 'CNY';
        $out = [];
        foreach ($presented as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $name = \trim((string)($line[OrderItem::schema_fields_PRODUCT_NAME] ?? $line['product_name'] ?? $line['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $optionsText = '';
            if (\is_array($line['options'] ?? null)) {
                $parts = [];
                foreach ($line['options'] as $opt) {
                    if (!\is_array($opt)) {
                        continue;
                    }
                    $label = \trim((string)($opt['label'] ?? ''));
                    $value = \trim((string)($opt['value'] ?? ''));
                    if ($label !== '' && $value !== '') {
                        $parts[] = $label . ': ' . $value;
                    } elseif ($value !== '') {
                        $parts[] = $value;
                    } elseif ($label !== '') {
                        $parts[] = $label;
                    }
                }
                $optionsText = \implode(' · ', $parts);
            }
            $qty = $line[OrderItem::schema_fields_QTY_ORDERED] ?? $line['qty'] ?? 1;
            $unit = (float)($line[OrderItem::schema_fields_PRICE] ?? $line['price'] ?? 0);
            $rowTotal = (float)($line[OrderItem::schema_fields_ROW_TOTAL] ?? $line['row_total'] ?? ($unit * (float)$qty));
            $image = \trim((string)($line['image_src'] ?? $line['image'] ?? ''));
            $out[] = [
                'name' => $name,
                'sku' => \trim((string)($line[OrderItem::schema_fields_PRODUCT_SKU] ?? $line['sku'] ?? '')),
                'qty' => \is_numeric($qty) ? (0 + $qty) : 1,
                'unit_price' => \number_format($unit, 2, '.', ''),
                'row_total' => \number_format($rowTotal, 2, '.', ''),
                'options_text' => $optionsText,
                'image_url' => $image,
                'currency' => $currency,
            ];
        }

        return $out;
    }
}
