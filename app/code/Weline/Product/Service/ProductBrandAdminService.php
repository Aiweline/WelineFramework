<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\Shard\Brand;
use Weline\Product\Repository\BrandRepository;
use Weline\Product\Repository\SupplierBrandRepository;
use Weline\Product\Repository\SupplierRepository;

/**
 * Backend CRUD for website-scoped product brands (ecommerce catalog).
 */
final class ProductBrandAdminService
{
    public function __construct(
        private readonly BrandRepository $brands,
        private readonly SupplierRepository $suppliers,
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
        foreach ($this->brands->listAll($websiteId, $status) as $row) {
            $rows[] = $this->enrichBrandRow($websiteId, $this->normalizeRow($row));
        }

        return $rows;
    }

    /**
     * Active brands for product create/edit selectors.
     *
     * @return list<array{brand_id:int,code:string,name:string,logo_url:string}>
     */
    public function catalogOptions(int $websiteId): array
    {
        $options = [];
        foreach ($this->list($websiteId, Brand::STATUS_ACTIVE) as $row) {
            $options[] = [
                'brand_id' => (int)$row['brand_id'],
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'logo_url' => (string)($row['logo_url'] ?? ''),
            ];
        }

        return $options;
    }

    public function get(int $websiteId, int $brandId): array
    {
        $this->assertWebsite($websiteId);
        $brand = $this->brands->findById($websiteId, $brandId)
            ?? throw new \InvalidArgumentException((string)__('品牌不存在：%{1}', [$brandId]));

        return $this->enrichBrandRow($websiteId, $this->normalizeRow($brand->getData()));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(int $websiteId, array $input): array
    {
        $this->assertWebsite($websiteId);
        $brandId = max(0, (int)($input['brand_id'] ?? 0));
        $name = trim((string)($input['name'] ?? ''));
        $code = trim((string)($input['code'] ?? ''));
        if ($code === '' && $name !== '') {
            $code = $name;
        }
        $payload = [
            Brand::schema_fields_NAME => $name,
            Brand::schema_fields_CODE => $code,
            Brand::schema_fields_LOGO_URL => $this->nullableMediaRef((string)($input['logo_url'] ?? '')),
            Brand::schema_fields_LOGO_ASSET_ID => $this->nullableTrim((string)($input['logo_asset_id'] ?? ''), 128),
            Brand::schema_fields_DESCRIPTION => $this->nullableTrim((string)($input['description'] ?? ''), 4000),
            Brand::schema_fields_STATUS => $this->normalizeStatus((string)($input['status'] ?? Brand::STATUS_ACTIVE)),
            Brand::schema_fields_POSITION => max(0, (int)($input['position'] ?? 0)),
        ];
        if ($brandId > 0) {
            if ($payload[Brand::schema_fields_POSITION] <= 0) {
                unset($payload[Brand::schema_fields_POSITION]);
            }
            $brand = $this->brands->updateFields($websiteId, $brandId, $payload);
        } else {
            if ($payload[Brand::schema_fields_POSITION] <= 0) {
                unset($payload[Brand::schema_fields_POSITION]);
            }
            $brand = $this->brands->create($websiteId, $payload);
        }
        $brandId = (int)$brand->getId();
        if (array_key_exists('supplier_ids', $input)) {
            $this->supplierBrands->replaceSuppliersForBrand(
                $websiteId,
                $brandId,
                $this->parseIdList($input['supplier_ids']),
            );
        }

        return $this->enrichBrandRow($websiteId, $this->normalizeRow($brand->getData()));
    }

    public function disable(int $websiteId, int $brandId): array
    {
        $this->assertWebsite($websiteId);
        $brand = $this->brands->disable($websiteId, $brandId);

        return $this->enrichBrandRow($websiteId, $this->normalizeRow($brand->getData()));
    }

    /**
     * Resolve display name (+ optional code) for product attribute write.
     *
     * @return array{brand_id:int,name:string,code:string}|null
     */
    public function resolveForProduct(int $websiteId, int $brandId): ?array
    {
        if ($brandId <= 0) {
            return null;
        }
        try {
            $row = $this->get($websiteId, $brandId);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ((string)($row['status'] ?? '') !== Brand::STATUS_ACTIVE) {
            return null;
        }

        return [
            'brand_id' => (int)$row['brand_id'],
            'name' => (string)$row['name'],
            'code' => (string)$row['code'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichBrandRow(int $websiteId, array $row): array
    {
        $brandId = (int)($row['brand_id'] ?? 0);
        $supplierIds = $this->supplierBrands->listSupplierIdsForBrand($websiteId, $brandId);
        $suppliers = [];
        foreach ($supplierIds as $supplierId) {
            $supplier = $this->suppliers->findById($websiteId, $supplierId);
            if ($supplier === null) {
                continue;
            }
            $data = $supplier->getData();
            $suppliers[] = [
                'supplier_id' => $supplierId,
                'code' => (string)($data['code'] ?? ''),
                'name' => (string)($data['name'] ?? ''),
            ];
        }
        $row['supplier_ids'] = $supplierIds;
        $row['suppliers'] = $suppliers;

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'brand_id' => (int)($row[Brand::schema_fields_ID] ?? $row['brand_id'] ?? 0),
            'global_brand_uuid' => (string)($row[Brand::schema_fields_GLOBAL_BRAND_UUID] ?? ''),
            'code' => (string)($row[Brand::schema_fields_CODE] ?? ''),
            'name' => (string)($row[Brand::schema_fields_NAME] ?? ''),
            'logo_url' => (string)($row[Brand::schema_fields_LOGO_URL] ?? ''),
            'logo_asset_id' => (string)($row[Brand::schema_fields_LOGO_ASSET_ID] ?? ''),
            'description' => (string)($row[Brand::schema_fields_DESCRIPTION] ?? ''),
            'status' => $this->normalizeStatus((string)($row[Brand::schema_fields_STATUS] ?? Brand::STATUS_ACTIVE)),
            'position' => (int)($row[Brand::schema_fields_POSITION] ?? 0),
            'created_at' => (string)($row[Brand::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[Brand::schema_fields_UPDATED_AT] ?? ''),
        ];
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

        return $status === Brand::STATUS_DISABLED
            ? Brand::STATUS_DISABLED
            : Brand::STATUS_ACTIVE;
    }

    private function assertWebsite(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('website_id 不能为负数：%{1}', [$websiteId]));
        }
    }
}
