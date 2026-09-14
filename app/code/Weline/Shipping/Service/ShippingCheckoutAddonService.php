<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\ShippingCheckoutAddon;

final class ShippingCheckoutAddonService
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @param array<string,mixed> $addonsRequest e.g. signature=>true, insurance_value_minor=>int
     * @param array{scope_type?:string,scope_id?:int,website_id?:int}|null $context
     * @return list<array{addon_code:string,addon_name:string,addon_type:string,amount_minor:int}>
     */
    public function matchRequested(
        array $addonsRequest,
        int $baseAmountMinor,
        int $currencyPrecision,
        ?array $context = null,
    ): array {
        $wantSignature = !empty($addonsRequest['signature'])
            || !empty($addonsRequest['signature_required']);
        $insuranceValue = max(0, (int)($addonsRequest['insurance_value_minor'] ?? 0));
        if (!$wantSignature && $insuranceValue <= 0) {
            return [];
        }

        $scopeType = (string)($context['scope_type'] ?? ShippingCheckoutAddon::SCOPE_WEBSITE);
        $scopeId = (int)($context['scope_id'] ?? $context['website_id'] ?? 0);
        /** @var ShippingCheckoutAddon $model */
        $model = $this->objectManager->getInstance(ShippingCheckoutAddon::class, [], false);
        $items = $model->reset()
            ->where(ShippingCheckoutAddon::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(ShippingCheckoutAddon::schema_fields_SCOPE_ID, $scopeId)
            ->where(ShippingCheckoutAddon::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingCheckoutAddon::schema_fields_PRIORITY, 'DESC')
            ->order(ShippingCheckoutAddon::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $hits = [];
        foreach (is_array($items) ? $items : [] as $addon) {
            if (!$addon instanceof ShippingCheckoutAddon) {
                continue;
            }
            $type = (string)$addon->getData(ShippingCheckoutAddon::schema_fields_ADDON_TYPE);
            if ($type === ShippingCheckoutAddon::TYPE_SIGNATURE && !$wantSignature) {
                continue;
            }
            if ($type === ShippingCheckoutAddon::TYPE_INSURANCE && $insuranceValue <= 0) {
                continue;
            }
            $amountType = (string)$addon->getData(ShippingCheckoutAddon::schema_fields_AMOUNT_TYPE);
            $amountValue = (float)$addon->getData(ShippingCheckoutAddon::schema_fields_AMOUNT_VALUE);
            $basis = $type === ShippingCheckoutAddon::TYPE_INSURANCE
                ? $insuranceValue
                : $baseAmountMinor;
            if ($amountType === ShippingCheckoutAddon::AMOUNT_PERCENT) {
                $extra = (int)round($basis * max(0, $amountValue) / 100);
            } else {
                $scale = 10 ** max(0, min(6, $currencyPrecision));
                $extra = (int)round(max(0, $amountValue) * $scale);
            }
            if ($extra <= 0) {
                continue;
            }
            $hits[] = [
                'addon_code' => (string)$addon->getData(ShippingCheckoutAddon::schema_fields_ADDON_CODE),
                'addon_name' => (string)$addon->getData(ShippingCheckoutAddon::schema_fields_ADDON_NAME),
                'addon_type' => $type,
                'amount_minor' => $extra,
            ];
        }

        return $hits;
    }

    /**
     * @param list<array{amount_minor:int}> $hits
     */
    public function sumMinor(array $hits): int
    {
        $sum = 0;
        foreach ($hits as $hit) {
            $sum += max(0, (int)($hit['amount_minor'] ?? 0));
        }

        return $sum;
    }
}
