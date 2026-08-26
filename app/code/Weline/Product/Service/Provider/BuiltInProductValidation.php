<?php

declare(strict_types=1);

namespace Weline\Product\Service\Provider;

use Weline\Product\Api\Data\ProductTypeDefinition;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\Data\ProductValidationResult;

/**
 * Shared deterministic validation for the five built-in types.
 */
final class BuiltInProductValidation
{
    public static function validate(
        ProductTypeDefinition $definition,
        ProductValidationContext $context,
    ): ProductValidationResult {
        $errors = [];
        $warnings = [];
        $offers = $context->offers;
        $count = count($offers);
        $stores = $context->storeIds === [] ? [0] : $context->storeIds;

        if ($count < $definition->minimumOffers
            || ($definition->maximumOffers !== null && $count > $definition->maximumOffers)
        ) {
            $errors[] = self::issue('offer_cardinality_invalid', __('Offer 数量不符合商品类型要求'), 'offers');
        }

        foreach ($stores as $storeId) {
            $resolution = $context->attributeResolution('name', (int)$storeId);
            $name = $resolution['found'] ? $resolution['value'] : ($context->product['name'] ?? null);
            if (!is_string($name) || trim($name) === '') {
                $errors[] = self::issue(
                    'product_name_required',
                    __('商品名称不能为空'),
                    'attributes.name',
                    '',
                    (int)$storeId,
                    $context->localeCode(),
                    $context->currencyCode(),
                );
            }
        }

        $seenSku = [];
        $seenCombination = [];
        foreach ($offers as $index => $offer) {
            $sku = trim((string)($offer['sku'] ?? ''));
            $offerUuid = trim((string)($offer['global_offer_uuid'] ?? ''));
            if ($sku === '') {
                $errors[] = self::issue(
                    'offer_sku_required',
                    __('每个 Offer 都必须有 SKU'),
                    'offers.' . $index . '.sku',
                    $offerUuid,
                );
            } elseif (isset($seenSku[strtolower($sku)])) {
                $errors[] = self::issue(
                    'offer_sku_duplicate',
                    __('同一商品内 SKU 不能重复：%{1}', [$sku]),
                    'offers.' . $index . '.sku',
                    $offerUuid,
                );
            }
            $seenSku[strtolower($sku)] = true;

            if ($definition->supportsVariants) {
                $combination = self::combinationKey($offer);
                if ($combination === '') {
                    $errors[] = self::issue(
                        'variant_combination_required',
                        __('多规格 Offer 必须绑定明确规格组合'),
                        'offers.' . $index . '.combination',
                        $offerUuid,
                    );
                } elseif (isset($seenCombination[$combination])) {
                    $errors[] = self::issue(
                        'variant_combination_duplicate',
                        __('规格组合不能重复'),
                        'offers.' . $index . '.combination',
                        $offerUuid,
                    );
                }
                $seenCombination[$combination] = true;
            }

            if ($definition->supportsPricing) {
                self::validatePrice($context, $offer, $index, $errors);
            }
            if ($definition->tracksInventory) {
                self::validateInventory($context, $offer, $index, $warnings);
            }
            if (!$definition->requiresShipping && (bool)($offer['requires_shipping'] ?? false)) {
                $errors[] = self::issue(
                    'shipping_not_supported',
                    __('该商品类型不能要求配送'),
                    'offers.' . $index . '.requires_shipping',
                    $offerUuid,
                );
            }
        }

        if ($definition->tracksInventory
            && !empty($context->inventory['enabled'])
            && empty($context->inventory['capability_available'])
        ) {
            $warnings[] = self::issue(
                'product_inventory_capability_unavailable',
                __('库存模块当前不可用，商品可发布但前台不可购买'),
                'inventory',
            );
        }
        if ($definition->tracksInventory) {
            foreach ((array)($context->inventory['errors'] ?? []) as $inventoryError) {
                if (!is_array($inventoryError)) {
                    continue;
                }
                $offerId = (int)($inventoryError['offer_id'] ?? 0);
                $warnings[] = self::issue(
                    (string)($inventoryError['code'] ?? 'product_inventory_read_failed'),
                    (string)($inventoryError['message'] ?? __('库存读取失败，请稍后重试')),
                    'inventory',
                    $context->offerUuidForId($offerId),
                    isset($inventoryError['store_id']) ? (int)$inventoryError['store_id'] : null,
                    $context->localeCode(),
                    $context->currencyCode(),
                );
            }
        }

        if ($definition->supportsVariants) {
            $axes = $context->typeConfiguration['axes'] ?? [];
            if (!is_array($axes) || $axes === []) {
                $errors[] = self::issue(
                    'variant_axes_required',
                    __('多规格商品至少需要一个 EAV 规格轴'),
                    'type_configuration.axes',
                );
            }
        }

        if ($definition->supportsVariants) {
            $rawAxes = $context->typeConfiguration['axes'] ?? [];
            $axes = [];
            $seenAxes = [];
            foreach (is_array($rawAxes) ? $rawAxes : [] as $axisIndex => $axis) {
                $code = is_array($axis)
                    ? trim((string)($axis['code'] ?? ''))
                    : trim((string)$axis);
                $normalizedCode = strtolower($code);
                if ($code === '') {
                    $errors[] = self::issue(
                        'variant_axis_invalid',
                        __('规格轴必须使用有效的 EAV 属性代码'),
                        'type_configuration.axes.' . $axisIndex,
                    );
                    continue;
                }
                if (isset($seenAxes[$normalizedCode])) {
                    $errors[] = self::issue(
                        'variant_axis_duplicate',
                        __('规格轴不能重复：%{1}', [$code]),
                        'type_configuration.axes.' . $axisIndex,
                    );
                    continue;
                }
                $seenAxes[$normalizedCode] = true;
                $axes[] = $normalizedCode;
            }
            if ($axes === []) {
                $errors[] = self::issue(
                    'variant_axes_required',
                    __('多规格商品至少需要一个规格轴'),
                    'type_configuration.axes',
                );
            } else {
                sort($axes);
                foreach ($context->offers as $offerIndex => $offer) {
                    $offerUuid = trim((string)($offer['global_offer_uuid'] ?? ''));
                    $combination = $offer['combination'] ?? null;
                    $normalizedCombination = [];
                    if (is_array($combination)) {
                        foreach ($combination as $axisCode => $value) {
                            $normalizedCombination[strtolower(trim((string)$axisCode))] = $value;
                        }
                    }
                    $combinationAxes = array_keys($normalizedCombination);
                    sort($combinationAxes);
                    if ($combinationAxes !== $axes) {
                        $errors[] = self::issue(
                            'variant_combination_axes_mismatch',
                            __('每个规格组合必须且只能包含已选择的全部规格轴'),
                            'offers.' . $offerIndex . '.combination',
                            $offerUuid,
                        );
                    }
                    foreach ($axes as $axisCode) {
                        $value = $normalizedCombination[$axisCode] ?? null;
                        if ($value === null || is_array($value) || trim((string)$value) === '') {
                            $errors[] = self::issue(
                                'variant_combination_value_required',
                                __('规格组合中的每个规格轴都必须选择一个值'),
                                'offers.' . $offerIndex . '.combination.' . $axisCode,
                                $offerUuid,
                            );
                        }
                    }
                }
            }
        }

        if ($definition->code === 'virtual') {
            $plans = $context->typeConfiguration['service_plans'] ?? [];
            if (!is_array($plans) || $plans === []) {
                $errors[] = self::issue(
                    'virtual_service_plan_required',
                    __('虚拟/服务商品至少需要一个服务方案'),
                    'type_configuration.service_plans',
                );
            } else {
                $seenPlans = [];
                foreach ($plans as $planIndex => $plan) {
                    $code = is_array($plan) ? trim((string)($plan['code'] ?? '')) : '';
                    $name = is_array($plan) ? trim((string)($plan['name'] ?? '')) : '';
                    if ($code === '' || $name === '') {
                        $errors[] = self::issue(
                            'virtual_service_plan_invalid',
                            __('服务方案必须包含代码和名称'),
                            'type_configuration.service_plans.' . $planIndex,
                        );
                        continue;
                    }
                    $normalizedCode = strtolower($code);
                    if (isset($seenPlans[$normalizedCode])) {
                        $errors[] = self::issue(
                            'virtual_service_plan_duplicate',
                            __('服务方案代码不能重复：%{1}', [$code]),
                            'type_configuration.service_plans.' . $planIndex . '.code',
                        );
                    }
                    $seenPlans[$normalizedCode] = true;
                }
            }
        }

        if ($definition->supportsDigitalDelivery) {
            $assets = $context->typeConfiguration['download_assets'] ?? [];
            $assetRows = is_array($assets) ? $assets : [];
            $privateAssetCount = 0;
            $seenAssets = [];
            foreach ($assetRows as $assetIndex => $asset) {
                $assetId = is_array($asset) ? trim((string)($asset['asset_id'] ?? '')) : '';
                $isPrivate = is_array($asset) && ($asset['private'] ?? false) === true;
                if ($assetId === '' || !$isPrivate) {
                    $errors[] = self::issue(
                        'download_asset_invalid',
                        __('下载资产必须引用受保护的私有 FileManager 资产'),
                        'type_configuration.download_assets.' . $assetIndex,
                    );
                    continue;
                }
                ++$privateAssetCount;
                if (isset($seenAssets[$assetId])) {
                    $errors[] = self::issue(
                        'download_asset_duplicate',
                        __('同一下载资产不能重复配置'),
                        'type_configuration.download_assets.' . $assetIndex . '.asset_id',
                    );
                }
                $seenAssets[$assetId] = true;
            }
            if ($privateAssetCount === 0) {
                $errors[] = self::issue(
                    'download_private_asset_required',
                    __('下载商品至少需要一个受保护的私有下载资产'),
                    'type_configuration.download_assets',
                );
            }

            $policy = $context->typeConfiguration['entitlement_policy'] ?? [];
            if (!is_array($policy)) {
                $errors[] = self::issue(
                    'download_entitlement_policy_invalid',
                    __('下载权益策略格式无效'),
                    'type_configuration.entitlement_policy',
                );
            } else {
                $isPositiveIntegerOrNull = static function (mixed $value): bool {
                    if ($value === null) {
                        return true;
                    }
                    if (is_int($value)) {
                        return $value > 0;
                    }
                    return is_string($value) && ctype_digit($value) && (int)$value > 0;
                };
                if (!$isPositiveIntegerOrNull($policy['download_limit'] ?? null)) {
                    $errors[] = self::issue(
                        'download_limit_invalid',
                        __('下载次数必须为正整数或留空表示不限次数'),
                        'type_configuration.entitlement_policy.download_limit',
                    );
                }
                if (!$isPositiveIntegerOrNull($policy['expires_after_days'] ?? null)) {
                    $errors[] = self::issue(
                        'download_expiry_invalid',
                        __('下载有效天数必须为正整数或留空表示永久有效'),
                        'type_configuration.entitlement_policy.expires_after_days',
                    );
                }
            }
        }

        if ($definition->supportsComposition) {
            self::validateComposition($context, $errors);
        }
        if ($definition->mainImageRequired && $context->media === []) {
            $errors[] = self::issue('main_image_required', __('该 Provider 要求主图'), 'media');
        }

        return new ProductValidationResult($errors, $warnings);
    }

    /** @param list<array<string,mixed>> $errors */
    /** @param list<array<string,mixed>> $warnings */
    private static function validateInventory(
        ProductValidationContext $context,
        array $offer,
        int $index,
        array &$warnings,
    ): void {
        $offerUuid = trim((string)($offer['global_offer_uuid'] ?? ''));
        $offerId = (int)($offer['offer_id'] ?? 0);
        if ($context->inventory !== []) {
            if (empty($context->inventory['enabled'])
                || empty($context->inventory['capability_available'])
            ) {
                return;
            }
            $stores = $context->storeIds === [] ? [0] : $context->storeIds;
            foreach ($stores as $storeId) {
                $row = $context->inventoryRow((int)$storeId, $offerUuid, $offerId);
                if ($row === null) {
                    continue;
                }
                if ((int)($row['available_minor'] ?? 0) > 0 && !empty($row['sellable'])) {
                    continue;
                }
                $warnings[] = self::issue(
                    'offer_zero_stock',
                    __('零库存允许发布，但前台不可购买'),
                    'offers.' . $index . '.inventory',
                    $offerUuid,
                    (int)$storeId,
                    $context->localeCode(),
                    $context->currencyCode(),
                );
            }
            return;
        }

        $quantity = $offer['salable_quantity'] ?? $offer['quantity'] ?? $offer['qty'] ?? null;
        if ($quantity !== null && (float)$quantity <= 0) {
            $warnings[] = self::issue(
                'offer_zero_stock',
                __('零库存允许发布，但前台不可购买'),
                'offers.' . $index . '.quantity',
                $offerUuid,
            );
        }
    }

    private static function validatePrice(
        ProductValidationContext $context,
        array $offer,
        int $index,
        array &$errors,
    ): void {
        $offerUuid = trim((string)($offer['global_offer_uuid'] ?? ''));
        $offerId = (int)($offer['offer_id'] ?? 0);
        $currency = $context->currencyCode();
        $prices = array_values(array_filter(
            $context->prices,
            static function (array $price) use ($offerUuid, $offerId, $currency): bool {
                $matchesOffer = ($offerUuid !== ''
                        && (string)($price['global_offer_uuid'] ?? '') === $offerUuid)
                    || ($offerId > 0 && (int)($price['offer_id'] ?? 0) === $offerId);
                $priceCurrency = strtoupper(trim((string)($price['currency'] ?? ''))) ?: $currency;
                return $matchesOffer && $priceCurrency === $currency;
            },
        ));
        $stores = $context->storeIds === [] ? [0] : $context->storeIds;
        foreach ($stores as $storeId) {
            $storeId = (int)$storeId;
            $scoped = array_values(array_filter(
                $prices,
                static fn(array $price): bool => (int)($price['store_id'] ?? 0) === $storeId,
            ));
            if ($storeId > 0 && $scoped === []) {
                $scoped = array_values(array_filter(
                    $prices,
                    static fn(array $price): bool => (int)($price['store_id'] ?? 0) === 0,
                ));
            }

            $found = false;
            foreach ($scoped as $price) {
                if (($price['scope_state'] ?? '') === 'cleared'
                    || (int)($price['cleared'] ?? 0) === 1
                ) {
                    $found = false;
                    break;
                }
                if (array_key_exists('amount_minor', $price) && $price['amount_minor'] !== null) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $errors[] = self::issue(
                    'offer_price_required',
                    __('Offer 缺少基础价；显式零价有效'),
                    'offers.' . $index . '.prices',
                    $offerUuid,
                    $storeId,
                    $context->localeCode(),
                    $currency,
                );
            }
        }
    }

    /** @param list<array<string,mixed>> $errors */
    private static function validateComposition(
        ProductValidationContext $context,
        array &$errors,
    ): void {
        $groups = $context->typeConfiguration['component_groups'] ?? [];
        if (!is_array($groups) || $groups === []) {
            $errors[] = self::issue(
                'bundle_component_group_required',
                __('组合商品至少需要一个组件组'),
                'type_configuration.component_groups',
            );
            return;
        }

        $currentProductUuid = trim((string)($context->product['global_product_uuid'] ?? ''));
        $ancestry = $context->typeConfiguration['ancestry'] ?? [];
        $cycleDetected = ($context->typeConfiguration['cycle_detected'] ?? false) === true
            || ($currentProductUuid !== ''
                && is_array($ancestry)
                && in_array($currentProductUuid, array_map('strval', $ancestry), true));
        if ($cycleDetected) {
            $errors[] = self::issue(
                'bundle_cycle_detected',
                __('组合商品不能循环引用自身'),
                'type_configuration.component_groups',
            );
        }

        $seenGroups = [];
        foreach ($groups as $groupIndex => $group) {
            if (!is_array($group) || !is_array($group['components'] ?? null)
                || ($group['components'] ?? []) === []
            ) {
                $errors[] = self::issue(
                    'bundle_component_required',
                    __('每个组合组件组至少包含一个已发布 Offer'),
                    'type_configuration.component_groups.' . $groupIndex,
                );
                continue;
            }

            $groupCode = trim((string)($group['code'] ?? ''));
            if ($groupCode !== '') {
                $normalizedGroupCode = strtolower($groupCode);
                if (isset($seenGroups[$normalizedGroupCode])) {
                    $errors[] = self::issue(
                        'bundle_component_group_duplicate',
                        __('组合组件组代码不能重复：%{1}', [$groupCode]),
                        'type_configuration.component_groups.' . $groupIndex . '.code',
                    );
                }
                $seenGroups[$normalizedGroupCode] = true;
            }

            $components = $group['components'];
            $componentCount = count($components);
            $minimum = $group['min_selections'] ?? null;
            $maximum = $group['max_selections'] ?? null;
            $selectionInvalid = ($minimum !== null
                    && (!is_int($minimum) || $minimum < 0))
                || ($maximum !== null
                    && (!is_int($maximum) || $maximum < 1))
                || ($minimum !== null && $maximum !== null && $maximum < $minimum)
                || ($maximum !== null && is_int($maximum) && $maximum > $componentCount);
            if ($selectionInvalid) {
                $errors[] = self::issue(
                    'bundle_group_selection_invalid',
                    __('组件组最少/最多选择数必须与组件数量一致'),
                    'type_configuration.component_groups.' . $groupIndex,
                );
            }

            $seenOffers = [];
            foreach ($components as $componentIndex => $component) {
                $componentPath = 'type_configuration.component_groups.' . $groupIndex
                    . '.components.' . $componentIndex;
                $offerUuid = is_array($component)
                    ? trim((string)($component['global_offer_uuid'] ?? ''))
                    : '';
                if (!is_array($component)
                    || $offerUuid === ''
                    || ($component['published'] ?? false) !== true
                ) {
                    $errors[] = self::issue(
                        'bundle_component_unpublished',
                        __('组合组件必须引用已发布 Offer'),
                        $componentPath,
                    );
                    continue;
                }

                if (isset($seenOffers[$offerUuid])) {
                    $errors[] = self::issue(
                        'bundle_component_duplicate',
                        __('同一组件组不能重复引用同一个 Offer'),
                        $componentPath . '.global_offer_uuid',
                    );
                }
                $seenOffers[$offerUuid] = true;

                $quantity = $component['quantity'] ?? 1;
                if (!is_int($quantity) && !is_float($quantity)
                    || (float)$quantity <= 0
                ) {
                    $errors[] = self::issue(
                        'bundle_component_quantity_invalid',
                        __('组合组件数量必须大于零'),
                        $componentPath . '.quantity',
                    );
                }

                $componentProductUuid = trim((string)(
                    $component['global_product_uuid']
                    ?? $component['component_product_uuid']
                    ?? ''
                ));
                if (!$cycleDetected
                    && $currentProductUuid !== ''
                    && $componentProductUuid === $currentProductUuid
                ) {
                    $errors[] = self::issue(
                        'bundle_cycle_detected',
                        __('组合商品不能循环引用自身'),
                        $componentPath,
                    );
                    $cycleDetected = true;
                }
            }
        }

        $priceMode = (string)($context->typeConfiguration['price_mode'] ?? 'fixed');
        if (!in_array($priceMode, ['fixed', 'dynamic'], true)) {
            $errors[] = self::issue(
                'bundle_price_mode_invalid',
                __('组合商品价格模式只能是 fixed 或 dynamic'),
                'type_configuration.price_mode',
            );
        }
    }

    private static function combinationKey(array $offer): string
    {
        $raw = $offer['combination_key'] ?? $offer['combination'] ?? '';
        if (is_array($raw)) {
            ksort($raw);
            return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return trim((string)$raw);
    }

    /** @return array<string, mixed> */
    private static function issue(
        string $code,
        string $message,
        string $path,
        string $offerUuid = '',
        ?int $storeId = null,
        string $locale = '',
        string $currency = '',
    ): array {
        $issue = ['code' => $code, 'message' => $message, 'path' => $path];
        if ($offerUuid !== '') {
            $issue['offer_uuid'] = $offerUuid;
        }
        if ($storeId !== null) {
            $issue['store_id'] = $storeId;
        }
        if ($locale !== '') {
            $issue['locale'] = $locale;
        }
        if ($currency !== '') {
            $issue['currency'] = $currency;
        }
        return $issue;
    }
}
