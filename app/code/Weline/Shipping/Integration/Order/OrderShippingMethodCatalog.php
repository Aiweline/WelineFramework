<?php

declare(strict_types=1);

namespace Weline\Shipping\Integration\Order;

use Weline\Order\Api\OrderShippingMethodCatalogInterface;
use Weline\Shipping\Model\ShippingService;

/**
 * Shipping-side catalog for backend order shipping chrome.
 */
final class OrderShippingMethodCatalog implements OrderShippingMethodCatalogInterface
{
    public function __construct(
        private readonly ShippingService $services,
    ) {
    }

    public function listActiveOptions(int $websiteId = 0, int $storeId = 0): array
    {
        unset($storeId);
        $websiteId = max(0, $websiteId);
        try {
            $rows = $this->services->clear()
                ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
                ->where(ShippingService::schema_fields_SCOPE_TYPE, ShippingService::SCOPE_WEBSITE)
                ->where(ShippingService::schema_fields_SCOPE_ID, $websiteId)
                ->order(ShippingService::schema_fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($rows) || $rows === []) {
            try {
                $rows = $this->services->clear()
                    ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
                    ->order(ShippingService::schema_fields_SORT_ORDER, 'ASC')
                    ->select()
                    ->fetchArray();
            } catch (\Throwable) {
                return [];
            }
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = strtoupper(trim((string)($row[ShippingService::schema_fields_SERVICE_CODE] ?? '')));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $label = trim((string)($row[ShippingService::schema_fields_SERVICE_NAME] ?? ''));
            $out[] = [
                'code' => $code,
                'label' => $label !== '' ? $label : $code,
            ];
        }

        return $out;
    }

    public function resolveLabel(string $code, int $websiteId = 0, int $storeId = 0): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }
        foreach ($this->listActiveOptions($websiteId, $storeId) as $option) {
            if (strtoupper((string)($option['code'] ?? '')) === $code) {
                return (string)($option['label'] ?? $code);
            }
        }
        try {
            $row = $this->services->clear()
                ->where(ShippingService::schema_fields_SERVICE_CODE, $code)
                ->find()
                ->fetch();
            if ($row instanceof ShippingService && (int)$row->getId() > 0) {
                $label = trim((string)$row->getData(ShippingService::schema_fields_SERVICE_NAME));

                return $label !== '' ? $label : $code;
            }
        } catch (\Throwable) {
            // Soft fallthrough.
        }

        return $code;
    }
}
