<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Repository\AttributeValueRepository;

/**
 * Backend application service for explicit product copy at Website/Store scope.
 *
 * Scope validation and persistence stay in AttributeValueRepository; this
 * facade only normalizes the bounded admin command and shapes list output.
 */
final class ProductSiteContentAdminService
{
    public function __construct(
        private readonly AttributeValueRepository $attributeValues,
    ) {
    }

    /**
     * @return array{rows:list<array<string,mixed>>,columns:list<string>}
     */
    public function load(int $websiteId, int $storeId, int $entityId): array
    {
        $this->assertScope($websiteId, $storeId);
        if ($entityId <= 0) {
            return ['rows' => [], 'columns' => []];
        }

        $rows = $this->attributeValues->listExplicitRows(
            $websiteId,
            'product',
            [$entityId],
            [$storeId],
        );

        return [
            'rows' => $rows,
            'columns' => $rows === [] ? [] : array_keys($rows[0]),
        ];
    }

    public function save(
        int $websiteId,
        int $storeId,
        int $entityId,
        string $attributeCode,
        string $locale,
        string $value,
        bool $isRequired,
    ): void {
        $this->assertScope($websiteId, $storeId);
        if ($entityId <= 0) {
            throw new \InvalidArgumentException(__('entity_id 必须是正整数'));
        }

        $attributeCode = strtolower(trim($attributeCode));
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $attributeCode)) {
            throw new \InvalidArgumentException(__('attribute_code 仅允许小写字母、数字和下划线'));
        }

        $locale = trim($locale);
        if ($locale !== '' && !preg_match('/^[a-z0-9_-]{2,32}$/iD', $locale)) {
            throw new \InvalidArgumentException(__('locale 格式无效'));
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > 65535) {
            throw new \InvalidArgumentException(__('文案不能为空且最多 65535 字节'));
        }

        $this->attributeValues->writeExplicit(
            $websiteId,
            $storeId,
            'product',
            $entityId,
            $attributeCode,
            $locale,
            $value,
            $isRequired,
        );
    }

    private function assertScope(int $websiteId, int $storeId): void
    {
        if ($websiteId < 0 || $storeId < 0) {
            throw new \InvalidArgumentException(__('website_id 与 store_id 不能为负'));
        }
    }
}
