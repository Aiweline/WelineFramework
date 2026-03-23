<?php

declare(strict_types=1);

namespace WeShop\ImportExport\Service;

use WeShop\Order\Model\Order;
use WeShop\Product\Model\Product;
use Weline\Eav\Model\EavAttribute\Set as AttributeSet;

class ImportExportService
{
    private const PRODUCT_COLUMNS = [
        Product::schema_fields_ID,
        Product::schema_fields_sku,
        Product::schema_fields_spu,
        Product::schema_fields_name,
        Product::schema_fields_price,
        Product::schema_fields_cost,
        Product::schema_fields_stock,
        Product::schema_fields_status,
        Product::schema_fields_weight,
        Product::schema_fields_short_description,
        Product::schema_fields_description,
        Product::schema_fields_image,
        Product::schema_fields_images,
        Product::schema_fields_parent_id,
        Product::schema_fields_set_id,
        Product::schema_fields_meta_name,
        Product::schema_fields_meta_description,
        Product::schema_fields_meta_keywords,
        Product::schema_fields_HANDLE,
    ];

    private const ORDER_COLUMNS = [
        Order::schema_fields_ID,
        Order::schema_fields_increment_id,
        Order::schema_fields_customer_id,
        Order::schema_fields_status,
        Order::schema_fields_total,
        Order::schema_fields_created_at,
        Order::schema_fields_updated_at,
    ];

    public function __construct(
        private readonly Product $product,
        private readonly Order $order,
        private readonly AttributeSet $attributeSet,
        private readonly ?string $exportDirectory = null
    ) {
    }

    public function exportProducts(array $filters = []): string
    {
        $query = $this->product->reset();

        if (array_key_exists('status', $filters) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where(Product::schema_fields_status, $filters['status']);
        }
        if (!empty($filters['sku'])) {
            $query->where(Product::schema_fields_sku, ['like', '%' . trim((string) $filters['sku']) . '%']);
        }
        if (!empty($filters['name'])) {
            $query->where(Product::schema_fields_name, ['like', '%' . trim((string) $filters['name']) . '%']);
        }

        $rows = $query->order(Product::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();

        return $this->writeCsvFile('products', self::PRODUCT_COLUMNS, $rows);
    }

    public function importProducts(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException((string) __('Import file does not exist or is not readable.'));
        }

        [$headers, $rows] = $this->readCsvFile($filePath);
        if ($headers === []) {
            throw new \InvalidArgumentException((string) __('Import file is empty.'));
        }

        $summary = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $row = $this->associateRow($headers, $row);

            if ($this->rowIsEmpty($row)) {
                continue;
            }

            try {
                $this->saveImportedProduct($row);
                $summary['success']++;
            } catch (\Throwable $throwable) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'line' => $line,
                    'sku' => trim((string) ($row[Product::schema_fields_sku] ?? '')),
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return $summary;
    }

    public function exportOrders(array $filters = []): string
    {
        $query = $this->order->reset();

        if (!empty($filters['status'])) {
            $query->where(Order::schema_fields_status, $filters['status']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where(Order::schema_fields_customer_id, (int) $filters['customer_id']);
        }
        if (!empty($filters['increment_id'])) {
            $query->where(Order::schema_fields_increment_id, ['like', '%' . trim((string) $filters['increment_id']) . '%']);
        }

        $rows = $query->order(Order::schema_fields_created_at, 'DESC')
            ->select()
            ->fetchArray();

        return $this->writeCsvFile('orders', self::ORDER_COLUMNS, $rows);
    }

    private function writeCsvFile(string $prefix, array $columns, array $rows): string
    {
        $directory = $this->getExportDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException((string) __('Unable to create export directory.'));
        }

        $path = $directory . DIRECTORY_SEPARATOR . sprintf(
            '%s-%s.csv',
            $prefix,
            date('Ymd-His')
        );

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException((string) __('Unable to open export file for writing.'));
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns);

        foreach ($rows as $row) {
            $normalized = [];
            foreach ($columns as $column) {
                $normalized[] = $this->extractRowValue($row, $column);
            }
            fputcsv($handle, $normalized);
        }

        fclose($handle);

        return $path;
    }

    private function readCsvFile(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException((string) __('Unable to open import file.'));
        }

        $headers = [];
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === []) {
                $headers = array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    $row
                );
                if (isset($headers[0])) {
                    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?: $headers[0];
                }
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return [$headers, $rows];
    }

    private function associateRow(array $headers, array $row): array
    {
        $associated = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $associated[$header] = trim((string) ($row[$index] ?? ''));
        }

        return $associated;
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function saveImportedProduct(array $row): void
    {
        $sku = trim((string) ($row[Product::schema_fields_sku] ?? ''));
        if ($sku === '') {
            throw new \InvalidArgumentException((string) __('SKU is required.'));
        }

        $name = trim((string) ($row[Product::schema_fields_name] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException((string) __('Product name is required.'));
        }

        $product = $this->product->reset();
        $product->load(Product::schema_fields_sku, $sku);
        if (!$product->getId()) {
            $product->clearData();
        }

        foreach ($this->normalizeProductImportData($row) as $field => $value) {
            $product->setData($field, $value);
        }

        $product->save();
    }

    private function normalizeProductImportData(array $row): array
    {
        $name = trim((string) ($row[Product::schema_fields_name] ?? ''));
        $sku = trim((string) ($row[Product::schema_fields_sku] ?? ''));
        $description = trim((string) ($row[Product::schema_fields_description] ?? ''));
        $shortDescription = trim((string) ($row[Product::schema_fields_short_description] ?? ''));
        $metaName = trim((string) ($row[Product::schema_fields_meta_name] ?? ''));
        $metaDescription = trim((string) ($row[Product::schema_fields_meta_description] ?? ''));
        $metaKeywords = trim((string) ($row[Product::schema_fields_meta_keywords] ?? ''));

        $setId = $this->normalizeInt($row[Product::schema_fields_set_id] ?? null, 0);
        if ($setId <= 0) {
            $setId = $this->resolveDefaultProductSetId();
        }

        return [
            Product::schema_fields_sku => $sku,
            Product::schema_fields_spu => trim((string) ($row[Product::schema_fields_spu] ?? '')) ?: $sku,
            Product::schema_fields_name => $name,
            Product::schema_fields_price => $this->normalizeFloat($row[Product::schema_fields_price] ?? null),
            Product::schema_fields_cost => $this->normalizeFloat($row[Product::schema_fields_cost] ?? null),
            Product::schema_fields_stock => $this->normalizeInt($row[Product::schema_fields_stock] ?? null),
            Product::schema_fields_status => $this->normalizeStatus($row[Product::schema_fields_status] ?? null),
            Product::schema_fields_weight => $this->normalizeFloat($row[Product::schema_fields_weight] ?? null),
            Product::schema_fields_short_description => $shortDescription ?: $name,
            Product::schema_fields_description => $description ?: $name,
            Product::schema_fields_meta_name => $metaName ?: $name,
            Product::schema_fields_meta_description => $metaDescription ?: ($description ?: $name),
            Product::schema_fields_meta_keywords => $metaKeywords,
            Product::schema_fields_image => trim((string) ($row[Product::schema_fields_image] ?? '')),
            Product::schema_fields_images => trim((string) ($row[Product::schema_fields_images] ?? '')),
            Product::schema_fields_parent_id => $this->normalizeInt($row[Product::schema_fields_parent_id] ?? null),
            Product::schema_fields_set_id => $setId,
            Product::schema_fields_HANDLE => trim((string) ($row[Product::schema_fields_HANDLE] ?? '')),
        ];
    }

    private function resolveDefaultProductSetId(): int
    {
        try {
            if (method_exists($this->product, 'getEavEntityId')) {
                $entityId = (int) $this->product->getEavEntityId();
                if ($entityId > 0) {
                    $result = $this->attributeSet->reset()
                        ->where(AttributeSet::schema_fields_code, 'default')
                        ->where(AttributeSet::schema_fields_eav_entity_id, $entityId)
                        ->find()
                        ->fetch();

                    $setId = (int) ($this->extractRowValue($result, AttributeSet::schema_fields_ID) ?? 0);
                    if ($setId > 0) {
                        return $setId;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return 1;
    }

    private function normalizeInt(mixed $value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private function normalizeFloat(mixed $value, float $default = 0.0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return round((float) $value, 4);
    }

    private function normalizeStatus(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 1;
        }

        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            'enabled', 'enable', 'active', '1' => 1,
            'disabled', 'disable', 'inactive', '0' => 0,
            default => (int) $value,
        };
    }

    private function extractRowValue(mixed $row, string $column): mixed
    {
        if (is_array($row)) {
            return $row[$column] ?? '';
        }

        if (is_object($row) && method_exists($row, 'getData')) {
            return $row->getData($column);
        }

        return '';
    }

    private function getExportDirectory(): string
    {
        if ($this->exportDirectory) {
            return $this->exportDirectory;
        }

        return dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'export' . DIRECTORY_SEPARATOR . 'weshop';
    }
}
