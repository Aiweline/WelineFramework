<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\FileManager\Api\Image;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderCatalogImageResolverInterface;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;

/**
 * Backend-facing order line projection.
 *
 * Topology / facade orders keep truth in catalog_snapshot_json; weline_order_item
 * rows may be empty. Templates expect OrderItem-like keys (name/sku/qty/price)
 * plus checkout-time deal chrome (compare_at / campaign / line discount) and
 * compact product thumbnails (image / image_src).
 */
final class BackendOrderLinePresenter
{
    public function __construct(
        private readonly ?OrderCatalogImageResolverInterface $images = null,
    ) {
    }

    /**
     * @param Order|array<string, mixed> $order
     * @param list<OrderItem|array<string, mixed>> $persistedItems
     * @return list<array<string, mixed>>
     */
    public function present(Order|array $order, array $persistedItems = []): array
    {
        $orderData = $order instanceof Order ? $order->getData() : $order;
        if (!\is_array($orderData)) {
            $orderData = [];
        }
        $websiteId = (int)($orderData[Order::schema_fields_WEBSITE_ID] ?? $orderData['website_id'] ?? 0);
        $storeId = (int)($orderData[Order::schema_fields_STORE_ID] ?? $orderData['store_id'] ?? 0);

        $fromTable = [];
        foreach ($persistedItems as $item) {
            $data = $item instanceof OrderItem ? $item->getData() : $item;
            if (!\is_array($data)) {
                continue;
            }
            $mapped = $this->mapPersistedRow($data);
            if ($mapped !== null) {
                $fromTable[] = $mapped;
            }
        }
        $lines = $fromTable;
        if ($lines === []) {
            $catalog = $this->decodeMap($orderData[Order::schema_fields_CATALOG_SNAPSHOT_JSON] ?? null);
            $rawLines = $catalog['lines'] ?? null;
            if (\is_array($rawLines)) {
                foreach ($rawLines as $line) {
                    if (!\is_array($line)) {
                        continue;
                    }
                    $mapped = $this->mapSnapshotLine($line, 'catalog_snapshot');
                    if ($mapped !== null) {
                        $lines[] = $mapped;
                    }
                }
            }
        }

        return $this->hydrateImages($lines, $websiteId, $storeId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapPersistedRow(array $row): ?array
    {
        $lineSnap = $this->decodeMap($row[OrderItem::schema_fields_CATALOG_LINE_SNAPSHOT_JSON] ?? null);
        $merged = $lineSnap !== [] ? array_replace($lineSnap, $row) : $row;

        return $this->mapSnapshotLine($merged, 'order_item');
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>|null
     */
    private function mapSnapshotLine(array $line, string $source): ?array
    {
        $name = trim((string)($line[OrderItem::schema_fields_PRODUCT_NAME] ?? $line['name'] ?? $line['product_name'] ?? ''));
        $sku = trim((string)($line[OrderItem::schema_fields_PRODUCT_SKU] ?? $line['sku'] ?? $line['product_sku'] ?? ''));
        if ($name === '' && $sku === '') {
            return null;
        }

        $qty = $this->firstNumber(
            $line[OrderItem::schema_fields_QTY_ORDERED] ?? null,
            isset($line[OrderItem::schema_fields_QTY_MINOR]) ? (int)$line[OrderItem::schema_fields_QTY_MINOR] : null,
            $line['qty'] ?? null,
            $line['qty_ordered'] ?? null,
            isset($line['qty_minor']) ? (int)$line['qty_minor'] : null,
        );
        $unitMinor = $this->resolveUnitMinor($line);
        $price = $unitMinor / 100;
        $rowTotal = $this->firstMoney(
            $line[OrderItem::schema_fields_ROW_TOTAL] ?? null,
            isset($line['row_total_minor']) ? ((int)$line['row_total_minor']) / 100 : null,
            $qty * $price,
        );
        $compareAtMinor = max(0, (int)($line['compare_at_minor'] ?? 0));
        if ($compareAtMinor <= 0) {
            $compareAtMinor = (int)round($this->firstMoney(
                $line['original_price'] ?? null,
                $line['compare_at'] ?? null,
                $line['list_price'] ?? null,
                0,
            ) * 100);
        }
        $hasDeal = !empty($line['has_deal']) || ($compareAtMinor > $unitMinor && $unitMinor > 0);
        $campaignLabel = trim((string)($line['campaign_label'] ?? ''));
        $campaignUrl = trim((string)($line['campaign_url'] ?? ''));
        $lineDiscountMinor = max(0, (int)($line['line_discount_minor'] ?? 0));
        if ($lineDiscountMinor <= 0 && $hasDeal) {
            $lineDiscountMinor = max(0, ($compareAtMinor - $unitMinor) * (int)max(1, (int)round($qty)));
        }
        $persistedDiscount = $this->firstMoney(
            $line[OrderItem::schema_fields_DISCOUNT_AMOUNT] ?? null,
            $line['discount_amount'] ?? null,
            0,
        );
        if ($lineDiscountMinor <= 0 && $persistedDiscount > 0) {
            $lineDiscountMinor = (int)round($persistedDiscount * 100);
        }

        $options = [];
        if (\is_array($line['options'] ?? null)) {
            foreach ($line['options'] as $option) {
                if (!\is_array($option)) {
                    continue;
                }
                $label = trim((string)($option['label'] ?? $option['code'] ?? ''));
                $value = trim((string)($option['value_label'] ?? $option['value'] ?? ''));
                if ($label === '' && $value === '') {
                    continue;
                }
                $options[] = [
                    'label' => $label !== '' ? $label : (string)__('选项'),
                    'value' => $value,
                ];
            }
        }

        $image = trim((string)($line['image'] ?? $line['image_url'] ?? $line['image_src'] ?? $line['thumbnail'] ?? ''));
        $productId = (int)($line['product_id'] ?? $line[OrderItem::schema_fields_PRODUCT_ID] ?? 0);

        return [
            OrderItem::schema_fields_PRODUCT_NAME => $name,
            OrderItem::schema_fields_PRODUCT_SKU => $sku,
            OrderItem::schema_fields_QTY_ORDERED => $qty,
            OrderItem::schema_fields_PRICE => $price,
            OrderItem::schema_fields_ROW_TOTAL => $rowTotal,
            OrderItem::schema_fields_DISCOUNT_AMOUNT => $lineDiscountMinor / 100,
            'unit_price_minor' => $unitMinor,
            'compare_at_minor' => $compareAtMinor,
            'compare_at' => $compareAtMinor / 100,
            'has_deal' => $hasDeal,
            'campaign_label' => $campaignLabel,
            'campaign_url' => $campaignUrl,
            'line_discount_minor' => $lineDiscountMinor,
            'options' => $options,
            'image' => $image,
            'image_src' => '',
            'product_id' => $productId > 0 ? $productId : null,
            'source' => $source,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function hydrateImages(array $lines, int $websiteId, int $storeId): array
    {
        if ($lines === []) {
            return [];
        }

        $resolver = $this->images();
        $needProductIds = [];
        foreach ($lines as $i => $line) {
            $raw = trim((string)($line['image'] ?? ''));
            $src = '';
            if ($raw !== '') {
                $src = $resolver !== null
                    ? $resolver->resolveReference($raw, $websiteId, $storeId)
                    : $this->fallbackDisplayUrl($raw);
            }
            if ($src === '') {
                $productId = (int)($line['product_id'] ?? 0);
                if ($productId > 0) {
                    $needProductIds[$productId] = true;
                }
            }
            $lines[$i]['image_src'] = $src;
        }

        if ($needProductIds !== [] && $resolver !== null) {
            $resolved = $resolver->resolveProductMainImages(
                $websiteId,
                array_keys($needProductIds),
                $storeId,
            );
            foreach ($lines as $i => $line) {
                if (trim((string)($line['image_src'] ?? '')) !== '') {
                    continue;
                }
                $productId = (int)($line['product_id'] ?? 0);
                $url = trim((string)($resolved[$productId] ?? ''));
                if ($url !== '') {
                    $lines[$i]['image_src'] = $url;
                    if (trim((string)($lines[$i]['image'] ?? '')) === '') {
                        $lines[$i]['image'] = $url;
                    }
                }
            }
        }

        return $lines;
    }

    private function images(): ?OrderCatalogImageResolverInterface
    {
        if ($this->images instanceof OrderCatalogImageResolverInterface) {
            return $this->images;
        }
        try {
            $candidate = ObjectManager::getInstance(OrderCatalogImageResolverInterface::class);
            return $candidate instanceof OrderCatalogImageResolverInterface ? $candidate : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fallbackDisplayUrl(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        if (str_starts_with(strtolower($reference), 'asset://')) {
            return '';
        }

        return Image::pathToMediaUrl($reference, 48, 48);
    }

    /** @param array<string, mixed> $line */
    private function resolveUnitMinor(array $line): int
    {
        if (isset($line[OrderItem::schema_fields_UNIT_PRICE_MINOR]) && is_numeric($line[OrderItem::schema_fields_UNIT_PRICE_MINOR])) {
            return max(0, (int)$line[OrderItem::schema_fields_UNIT_PRICE_MINOR]);
        }
        if (isset($line['unit_price_minor']) && is_numeric($line['unit_price_minor'])) {
            return max(0, (int)$line['unit_price_minor']);
        }

        return (int)round($this->firstMoney(
            $line[OrderItem::schema_fields_PRICE] ?? null,
            $line['price'] ?? null,
            $line['unit_price'] ?? null,
            0,
        ) * 100);
    }

    private function firstNumber(mixed ...$candidates): float
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if (is_numeric($candidate)) {
                return (float)$candidate;
            }
        }

        return 0.0;
    }

    private function firstMoney(mixed ...$candidates): float
    {
        return $this->firstNumber(...$candidates);
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
