<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\Shard\ProductSupplier;
use Weline\Product\Model\Shard\Supplier;
use Weline\Product\Repository\BrandRepository;
use Weline\Product\Repository\ProductSupplierRepository;
use Weline\Product\Repository\SupplierBrandRepository;
use Weline\Product\Repository\SupplierRepository;

/**
 * Backend CRUD for website-scoped suppliers and primary product-supplier offers.
 */
final class ProductSupplierAdminService
{
    public function __construct(
        private readonly SupplierRepository $suppliers,
        private readonly ProductSupplierRepository $productSuppliers,
        private readonly BrandRepository $brands,
        private readonly SupplierBrandRepository $supplierBrands,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $websiteId, ?string $status = null): array
    {
        $this->assertWebsite($websiteId);
        $rows = [];
        foreach ($this->suppliers->listAll($websiteId, $status) as $row) {
            $rows[] = $this->enrichSupplierRow($websiteId, $this->normalizeSupplierRow($row));
        }

        return $rows;
    }

    /**
     * Active suppliers for product create/edit selectors.
     *
     * @return list<array{supplier_id:int,code:string,name:string,image_url:string,store_url:string,default_currency:string,brand_ids:list<int>}>
     */
    public function catalogOptions(int $websiteId): array
    {
        $brandMap = $this->supplierBrands->mapBrandIdsBySupplier($websiteId);
        $options = [];
        foreach ($this->list($websiteId, Supplier::STATUS_ACTIVE) as $row) {
            $supplierId = (int)$row['supplier_id'];
            $options[] = [
                'supplier_id' => $supplierId,
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'image_url' => (string)($row['image_url'] ?? ''),
                'store_url' => (string)($row['store_url'] ?? ''),
                'default_currency' => (string)($row['default_currency'] ?? ''),
                'brand_ids' => $brandMap[$supplierId] ?? [],
            ];
        }

        return $options;
    }

    public function get(int $websiteId, int $supplierId): array
    {
        $this->assertWebsite($websiteId);
        $supplier = $this->suppliers->findById($websiteId, $supplierId)
            ?? throw new \InvalidArgumentException((string)__('供应商不存在：%{1}', [$supplierId]));

        return $this->enrichSupplierRow($websiteId, $this->normalizeSupplierRow($supplier->getData()));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(int $websiteId, array $input): array
    {
        $this->assertWebsite($websiteId);
        $supplierId = max(0, (int)($input['supplier_id'] ?? 0));
        $name = trim((string)($input['name'] ?? ''));
        $code = trim((string)($input['code'] ?? ''));
        if ($code === '' && $name !== '') {
            $code = $name;
        }
        $payload = [
            Supplier::schema_fields_NAME => $name,
            Supplier::schema_fields_CODE => $code,
            Supplier::schema_fields_STORE_URL => $this->nullableUrl((string)($input['store_url'] ?? '')),
            Supplier::schema_fields_IMAGE_URL => $this->nullableMediaRef((string)($input['image_url'] ?? '')),
            Supplier::schema_fields_IMAGE_ASSET_ID => $this->nullableTrim((string)($input['image_asset_id'] ?? ''), 128),
            Supplier::schema_fields_CONTACT_NAME => $this->nullableTrim((string)($input['contact_name'] ?? ''), 128),
            Supplier::schema_fields_CONTACT_PHONE => $this->nullableTrim((string)($input['contact_phone'] ?? ''), 64),
            Supplier::schema_fields_CONTACT_EMAIL => $this->nullableTrim((string)($input['contact_email'] ?? ''), 255),
            Supplier::schema_fields_DEFAULT_CURRENCY => $this->nullableCurrency((string)($input['default_currency'] ?? '')),
            Supplier::schema_fields_DEFAULT_PAYMENT_TERMS => $this->nullableTrim((string)($input['default_payment_terms'] ?? ''), 128),
            Supplier::schema_fields_DEFAULT_LEAD_TIME_DAYS => $this->nullableNonNegativeInt($input['default_lead_time_days'] ?? null),
            Supplier::schema_fields_DEFAULT_MOQ => $this->nullableNonNegativeInt($input['default_moq'] ?? null),
            Supplier::schema_fields_DESCRIPTION => $this->nullableTrim((string)($input['description'] ?? ''), 4000),
            Supplier::schema_fields_STATUS => $this->normalizeStatus((string)($input['status'] ?? Supplier::STATUS_ACTIVE)),
            Supplier::schema_fields_POSITION => max(0, (int)($input['position'] ?? 0)),
        ];
        if ($supplierId > 0) {
            if ($payload[Supplier::schema_fields_POSITION] <= 0) {
                unset($payload[Supplier::schema_fields_POSITION]);
            }
            $supplier = $this->suppliers->updateFields($websiteId, $supplierId, $payload);
        } else {
            if ($payload[Supplier::schema_fields_POSITION] <= 0) {
                unset($payload[Supplier::schema_fields_POSITION]);
            }
            $supplier = $this->suppliers->create($websiteId, $payload);
        }
        $supplierId = (int)$supplier->getId();
        if (array_key_exists('brand_ids', $input)) {
            $this->supplierBrands->replaceBrandsForSupplier(
                $websiteId,
                $supplierId,
                $this->parseIdList($input['brand_ids']),
            );
        }

        return $this->enrichSupplierRow($websiteId, $this->normalizeSupplierRow($supplier->getData()));
    }

    public function disable(int $websiteId, int $supplierId): array
    {
        $this->assertWebsite($websiteId);
        $supplier = $this->suppliers->disable($websiteId, $supplierId);

        return $this->enrichSupplierRow($websiteId, $this->normalizeSupplierRow($supplier->getData()));
    }

    /**
     * @return array{supplier_id:int,name:string,code:string,store_url:string,default_currency:string}|null
     */
    public function resolveForProduct(int $websiteId, int $supplierId): ?array
    {
        if ($supplierId <= 0) {
            return null;
        }
        try {
            $row = $this->get($websiteId, $supplierId);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ((string)($row['status'] ?? '') !== Supplier::STATUS_ACTIVE) {
            return null;
        }

        return [
            'supplier_id' => (int)$row['supplier_id'],
            'name' => (string)$row['name'],
            'code' => (string)$row['code'],
            'store_url' => (string)($row['store_url'] ?? ''),
            'default_currency' => (string)($row['default_currency'] ?? ''),
        ];
    }

    /**
     * Persist primary product↔supplier offer fields from create/edit payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function upsertPrimaryFromPayload(int $websiteId, int $productId, array $payload): ?array
    {
        $supplierId = max(0, (int)($payload['supplier_id'] ?? 0));
        if ($supplierId <= 0 || $productId <= 0) {
            return null;
        }
        $resolved = $this->resolveForProduct($websiteId, $supplierId);
        if ($resolved === null) {
            throw new \InvalidArgumentException('product_supplier_invalid');
        }

        $currency = $this->nullableCurrency((string)($payload['supplier_currency'] ?? ''));
        if ($currency === null && $resolved['default_currency'] !== '') {
            $currency = $resolved['default_currency'];
        }

        $link = $this->productSuppliers->upsertPrimary($websiteId, $productId, $supplierId, [
            ProductSupplier::schema_fields_SUPPLIER_PRODUCT_URL => $this->nullableUrl(
                (string)($payload['supplier_product_url'] ?? ''),
            ),
            ProductSupplier::schema_fields_SUPPLIER_SKU => $this->nullableTrim(
                (string)($payload['supplier_sku'] ?? ''),
                128,
            ),
            ProductSupplier::schema_fields_SUPPLIER_PRODUCT_NAME => $this->nullableTrim(
                (string)($payload['supplier_product_name'] ?? ''),
                255,
            ),
            ProductSupplier::schema_fields_CURRENCY => $currency,
            ProductSupplier::schema_fields_UNIT_PRICE_MINOR => $this->nullableNonNegativeInt(
                $payload['supplier_unit_price_minor'] ?? null,
            ),
            ProductSupplier::schema_fields_LIST_PRICE_MINOR => $this->nullableNonNegativeInt(
                $payload['supplier_list_price_minor'] ?? null,
            ),
            ProductSupplier::schema_fields_MOQ => $this->nullableNonNegativeInt($payload['supplier_moq'] ?? null),
            ProductSupplier::schema_fields_LEAD_TIME_DAYS => $this->nullableNonNegativeInt(
                $payload['supplier_lead_time_days'] ?? null,
            ),
            ProductSupplier::schema_fields_PACK_QTY => $this->nullableNonNegativeInt(
                $payload['supplier_pack_qty'] ?? null,
            ),
            ProductSupplier::schema_fields_NOTES => $this->nullableTrim((string)($payload['supplier_notes'] ?? ''), 4000),
            ProductSupplier::schema_fields_STATUS => ProductSupplier::STATUS_ACTIVE,
            ProductSupplier::schema_fields_LAST_QUOTED_AT => (
                isset($payload['supplier_unit_price_minor']) || isset($payload['supplier_list_price_minor'])
            ) ? date('Y-m-d H:i:s') : null,
        ]);

        return $this->normalizeLinkRow($link->getData());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSupplierRow(array $row): array
    {
        return [
            'supplier_id' => (int)($row[Supplier::schema_fields_ID] ?? $row['supplier_id'] ?? 0),
            'global_supplier_uuid' => (string)($row[Supplier::schema_fields_GLOBAL_SUPPLIER_UUID] ?? ''),
            'code' => (string)($row[Supplier::schema_fields_CODE] ?? ''),
            'name' => (string)($row[Supplier::schema_fields_NAME] ?? ''),
            'store_url' => (string)($row[Supplier::schema_fields_STORE_URL] ?? ''),
            'image_url' => (string)($row[Supplier::schema_fields_IMAGE_URL] ?? ''),
            'image_asset_id' => (string)($row[Supplier::schema_fields_IMAGE_ASSET_ID] ?? ''),
            'contact_name' => (string)($row[Supplier::schema_fields_CONTACT_NAME] ?? ''),
            'contact_phone' => (string)($row[Supplier::schema_fields_CONTACT_PHONE] ?? ''),
            'contact_email' => (string)($row[Supplier::schema_fields_CONTACT_EMAIL] ?? ''),
            'default_currency' => (string)($row[Supplier::schema_fields_DEFAULT_CURRENCY] ?? ''),
            'default_payment_terms' => (string)($row[Supplier::schema_fields_DEFAULT_PAYMENT_TERMS] ?? ''),
            'default_lead_time_days' => $row[Supplier::schema_fields_DEFAULT_LEAD_TIME_DAYS] ?? null,
            'default_moq' => $row[Supplier::schema_fields_DEFAULT_MOQ] ?? null,
            'description' => (string)($row[Supplier::schema_fields_DESCRIPTION] ?? ''),
            'status' => $this->normalizeStatus((string)($row[Supplier::schema_fields_STATUS] ?? Supplier::STATUS_ACTIVE)),
            'position' => (int)($row[Supplier::schema_fields_POSITION] ?? 0),
            'created_at' => (string)($row[Supplier::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[Supplier::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeLinkRow(array $row): array
    {
        return [
            'link_id' => (int)($row[ProductSupplier::schema_fields_ID] ?? $row['link_id'] ?? 0),
            'product_id' => (int)($row[ProductSupplier::schema_fields_PRODUCT_ID] ?? 0),
            'supplier_id' => (int)($row[ProductSupplier::schema_fields_SUPPLIER_ID] ?? 0),
            'is_primary' => (int)($row[ProductSupplier::schema_fields_IS_PRIMARY] ?? 0) === 1,
            'supplier_product_url' => (string)($row[ProductSupplier::schema_fields_SUPPLIER_PRODUCT_URL] ?? ''),
            'supplier_sku' => (string)($row[ProductSupplier::schema_fields_SUPPLIER_SKU] ?? ''),
            'supplier_product_name' => (string)($row[ProductSupplier::schema_fields_SUPPLIER_PRODUCT_NAME] ?? ''),
            'currency' => (string)($row[ProductSupplier::schema_fields_CURRENCY] ?? ''),
            'unit_price_minor' => $row[ProductSupplier::schema_fields_UNIT_PRICE_MINOR] ?? null,
            'list_price_minor' => $row[ProductSupplier::schema_fields_LIST_PRICE_MINOR] ?? null,
            'moq' => $row[ProductSupplier::schema_fields_MOQ] ?? null,
            'lead_time_days' => $row[ProductSupplier::schema_fields_LEAD_TIME_DAYS] ?? null,
            'pack_qty' => $row[ProductSupplier::schema_fields_PACK_QTY] ?? null,
            'last_quoted_at' => (string)($row[ProductSupplier::schema_fields_LAST_QUOTED_AT] ?? ''),
            'notes' => (string)($row[ProductSupplier::schema_fields_NOTES] ?? ''),
            'status' => $this->normalizeStatus((string)($row[ProductSupplier::schema_fields_STATUS] ?? ProductSupplier::STATUS_ACTIVE)),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichSupplierRow(int $websiteId, array $row): array
    {
        $supplierId = (int)($row['supplier_id'] ?? 0);
        $brandIds = $this->supplierBrands->listBrandIdsForSupplier($websiteId, $supplierId);
        $brands = [];
        foreach ($brandIds as $brandId) {
            $brand = $this->brands->findById($websiteId, $brandId);
            if ($brand === null) {
                continue;
            }
            $data = $brand->getData();
            $brands[] = [
                'brand_id' => $brandId,
                'code' => (string)($data['code'] ?? ''),
                'name' => (string)($data['name'] ?? ''),
            ];
        }
        $row['brand_ids'] = $brandIds;
        $row['brands'] = $brands;

        return $row;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function parseIdList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function nullableMediaRef(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 512) {
            throw new \InvalidArgumentException((string)__('URL 长度不能超过 512'));
        }

        return $value;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return $status === Supplier::STATUS_DISABLED
            ? Supplier::STATUS_DISABLED
            : Supplier::STATUS_ACTIVE;
    }

    private function nullableTrim(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException((string)__('字段长度不能超过 %{1}', [$maxLength]));
        }

        return $value;
    }

    private function nullableUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 512) {
            throw new \InvalidArgumentException((string)__('URL 长度不能超过 512'));
        }
        if (!preg_match('#^https?://#i', $value)) {
            throw new \InvalidArgumentException((string)__('店铺/产品地址须以 http:// 或 https:// 开头'));
        }

        return $value;
    }

    private function nullableCurrency(string $value): ?string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^[A-Z]{3}$/', $value)) {
            throw new \InvalidArgumentException((string)__('币种须为 3 位字母，如 CNY'));
        }

        return $value;
    }

    private function nullableNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && !ctype_digit(trim($value))) {
            throw new \InvalidArgumentException((string)__('须为非负整数'));
        }
        $int = (int)$value;
        if ($int < 0) {
            throw new \InvalidArgumentException((string)__('须为非负整数'));
        }

        return $int;
    }

    private function assertWebsite(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('website_id 不能为负数：%{1}', [$websiteId]));
        }
    }
}
