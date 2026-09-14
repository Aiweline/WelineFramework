<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingService;

/**
 * P1：新仓绑定发货地址后，一键复制默认站九档航线（同模板引用，改 origin）。
 */
final class WarehouseLaneCopyService
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * Copy website-scoped lanes that use default origin onto target origin address.
     *
     * @return int number of services created/updated
     */
    public function copyDefaultLanesToOrigin(int $websiteId, int $targetShippingAddressId): int
    {
        $websiteId = max(0, $websiteId);
        $targetShippingAddressId = max(0, $targetShippingAddressId);
        if ($targetShippingAddressId <= 0) {
            return 0;
        }

        /** @var ShippingService $model */
        $model = $this->objectManager->getInstance(ShippingService::class, [], false);
        $sources = $model->reset()
            ->where(ShippingService::schema_fields_SCOPE_TYPE, ShippingService::SCOPE_WEBSITE)
            ->where(ShippingService::schema_fields_SCOPE_ID, $websiteId)
            ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingService::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        if (!is_array($sources) || $sources === []) {
            // fallback: website 0 seed layer
            $sources = $model->reset()
                ->where(ShippingService::schema_fields_SCOPE_TYPE, ShippingService::SCOPE_WEBSITE)
                ->where(ShippingService::schema_fields_SCOPE_ID, 0)
                ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
                ->order(ShippingService::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
        }
        if (!is_array($sources)) {
            return 0;
        }

        $copied = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($sources as $source) {
            if (!$source instanceof ShippingService || !(int)$source->getId()) {
                continue;
            }
            $sourceOrigin = (int)$source->getData(ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID);
            if ($sourceOrigin === $targetShippingAddressId) {
                continue;
            }
            $baseCode = trim((string)$source->getData(ShippingService::schema_fields_SERVICE_CODE));
            if ($baseCode === '') {
                continue;
            }
            $newCode = $baseCode . '_WH' . $targetShippingAddressId;
            if (strlen($newCode) > 50) {
                $newCode = substr($baseCode, 0, max(1, 50 - 12)) . '_WH' . $targetShippingAddressId;
            }

            /** @var ShippingService $existing */
            $existing = $this->objectManager->getInstance(ShippingService::class, [], false);
            $found = $existing->reset()
                ->where(ShippingService::schema_fields_SERVICE_CODE, $newCode)
                ->where(ShippingService::schema_fields_SCOPE_TYPE, ShippingService::SCOPE_WEBSITE)
                ->where(ShippingService::schema_fields_SCOPE_ID, $websiteId)
                ->select()
                ->fetch()
                ->getItems();
            $row = is_array($found) ? ($found[0] ?? null) : null;
            $payload = [
                ShippingService::schema_fields_SCOPE_TYPE => ShippingService::SCOPE_WEBSITE,
                ShippingService::schema_fields_SCOPE_ID => $websiteId,
                ShippingService::schema_fields_SERVICE_CODE => $newCode,
                ShippingService::schema_fields_SERVICE_NAME => (string)$source->getData(ShippingService::schema_fields_SERVICE_NAME),
                ShippingService::schema_fields_CARRIER_ID => (int)$source->getData(ShippingService::schema_fields_CARRIER_ID),
                ShippingService::schema_fields_RATE_TEMPLATE_ID => (int)$source->getData(ShippingService::schema_fields_RATE_TEMPLATE_ID),
                ShippingService::schema_fields_FREE_SHIPPING_RULE_ID => (int)$source->getData(ShippingService::schema_fields_FREE_SHIPPING_RULE_ID),
                ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID => $targetShippingAddressId,
                ShippingService::schema_fields_ESTIMATED_DAYS_MIN => (int)$source->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MIN),
                ShippingService::schema_fields_ESTIMATED_DAYS_MAX => (int)$source->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MAX),
                ShippingService::schema_fields_IS_FREE_SHIPPING => (int)$source->getData(ShippingService::schema_fields_IS_FREE_SHIPPING),
                ShippingService::schema_fields_IS_ACTIVE => 1,
                ShippingService::schema_fields_SORT_ORDER => (int)$source->getData(ShippingService::schema_fields_SORT_ORDER),
                ShippingService::schema_fields_UPDATED_AT => $now,
            ];
            if ($row instanceof ShippingService && (int)$row->getId() > 0) {
                $row->setData($payload)->save();
                $newId = (int)$row->getId();
            } else {
                /** @var ShippingService $create */
                $create = $this->objectManager->getInstance(ShippingService::class, [], false);
                $create->setData(array_merge($payload, [
                    ShippingService::schema_fields_CREATED_AT => $now,
                ]))->save();
                $newId = (int)$create->getId();
            }
            if ($newId > 0) {
                $this->copyServiceRegions((int)$source->getId(), $newId);
                ++$copied;
            }
        }

        return $copied;
    }

    private function copyServiceRegions(int $fromServiceId, int $toServiceId): void
    {
        if ($fromServiceId <= 0 || $toServiceId <= 0 || $fromServiceId === $toServiceId) {
            return;
        }
        /** @var ServiceRegion $regionModel */
        $regionModel = $this->objectManager->getInstance(ServiceRegion::class, [], false);
        $existing = $regionModel->reset()
            ->where(ServiceRegion::schema_fields_SERVICE_ID, $toServiceId)
            ->select()
            ->fetch()
            ->getItems();
        if (is_array($existing)) {
            foreach ($existing as $row) {
                if ($row instanceof ServiceRegion && (int)$row->getId() > 0) {
                    $row->delete();
                }
            }
        }
        $sources = $regionModel->reset()
            ->where(ServiceRegion::schema_fields_SERVICE_ID, $fromServiceId)
            ->select()
            ->fetch()
            ->getItems();
        if (!is_array($sources)) {
            return;
        }
        foreach ($sources as $src) {
            if (!$src instanceof ServiceRegion) {
                continue;
            }
            $data = $src->getData();
            unset($data[ServiceRegion::schema_fields_ID]);
            $data[ServiceRegion::schema_fields_SERVICE_ID] = $toServiceId;
            /** @var ServiceRegion $create */
            $create = $this->objectManager->getInstance(ServiceRegion::class, [], false);
            $create->setData($data)->save();
        }
    }
}
