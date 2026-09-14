<?php

declare(strict_types=1);

namespace Weline\B2B\Plugin;

use Weline\B2B\Service\DefaultWholesalePolicy;
use Weline\SystemConfig\Service\SystemConfigCenterService;

/**
 * Reject / normalize default wholesale tier JSON that exceeds max_discount_bps on config save.
 */
class SystemConfigCenterSaveGuard
{
    /**
     * @param array<string, mixed> $values
     * @param list<string> $inheritKeys
     * @param array<string, int> $baseVersions
     * @param array<string, mixed> $options
     * @return array{0?:string,1?:string,2?:string,3?:array,4?:array,5?:array,6?:?string,7?:?string,8?:array}|null
     */
    public function beforeSaveTemplateConfig(
        SystemConfigCenterService $subject,
        string $module,
        string $area,
        string $code,
        array $values,
        array $inheritKeys = [],
        array $baseVersions = [],
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): ?array {
        if ($module !== DefaultWholesalePolicy::CONFIG_MODULE) {
            return null;
        }
        $touched = array_key_exists(DefaultWholesalePolicy::KEY_TIER_POLICY_JSON, $values)
            || array_key_exists(DefaultWholesalePolicy::KEY_MAX_DISCOUNT_BPS, $values);
        if (!$touched) {
            return null;
        }

        $max = (int) ($values[DefaultWholesalePolicy::KEY_MAX_DISCOUNT_BPS]
            ?? DefaultWholesalePolicy::DEFAULT_MAX_DISCOUNT_BPS);
        $max = max(0, min(10000, $max));

        $rawTiers = $values[DefaultWholesalePolicy::KEY_TIER_POLICY_JSON] ?? [];
        if (is_string($rawTiers)) {
            try {
                $decoded = json_decode($rawTiers, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new \InvalidArgumentException((string) __('默认批发模板 JSON 无效'));
            }
            $rawTiers = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($rawTiers)) {
            $rawTiers = [];
        }
        if ($rawTiers === []) {
            $rawTiers = DefaultWholesalePolicy::seedTierRows($max);
        }

        $normalized = DefaultWholesalePolicy::assertAndNormalizeForSave($rawTiers, $max);
        $values[DefaultWholesalePolicy::KEY_TIER_POLICY_JSON] = $normalized;
        $values[DefaultWholesalePolicy::KEY_MAX_DISCOUNT_BPS] = $max;

        return [$module, $area, $code, $values, $inheritKeys, $baseVersions, $scope, $locale, $options];
    }
}
