<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Merchant;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * 商家信息（SystemConfig 组 seo_merchant）读取与归一化。
 *
 * 继承由 SystemConfig 负责：channel → store → website → global。
 * 所有值缺省即回落到主题默认政策页与通用退货口径，调用方不需要再判空。
 */
final class MerchantInfoConfigResolver
{
    public const CONFIG_MODULE = 'Weline_Seo';
    public const CONFIG_AREA = 'backend';
    public const GROUP = 'seo_merchant';

    public const KEY_NAME = 'seo/merchant/name';
    public const KEY_BRAND = 'seo/merchant/brand';
    public const KEY_EMAIL = 'seo/merchant/email';
    public const KEY_PHONE = 'seo/merchant/phone';
    public const KEY_ADDRESS = 'seo/merchant/address';
    public const KEY_RETURN_POLICY_URL = 'seo/merchant/return_policy_url';
    public const KEY_SHIPPING_POLICY_URL = 'seo/merchant/shipping_policy_url';
    public const KEY_RETURN_DAYS = 'seo/merchant/return_days';
    public const KEY_RETURN_FEES = 'seo/merchant/return_fees';
    public const KEY_RETURN_SHIPPING_FEE = 'seo/merchant/return_shipping_fee';
    public const KEY_RETURN_METHOD = 'seo/merchant/return_method';
    public const KEY_COUNTRY = 'seo/merchant/country';

    public const DEFAULT_RETURN_POLICY_URL = '/policy/refund';
    public const DEFAULT_SHIPPING_POLICY_URL = '/policy/shipping';
    public const DEFAULT_RETURN_DAYS = 30;
    public const DEFAULT_RETURN_FEES = 'buyer_pays';
    public const DEFAULT_RETURN_SHIPPING_FEE = 0.0;
    public const DEFAULT_RETURN_METHOD = 'mail';
    public const DEFAULT_COUNTRY = 'WW';

    public const RETURN_FEES_BUYER = 'buyer_pays';
    public const RETURN_FEES_FREE = 'free_return';
    public const RETURN_METHOD_MAIL = 'mail';
    public const RETURN_METHOD_IN_STORE = 'in_store';

    private bool $systemConfigResolved = false;
    private ?SystemConfig $systemConfig = null;

    /**
     * @return array{
     *     name:string,
     *     brand:string,
     *     email:string,
     *     phone:string,
     *     address:string,
     *     return_policy_url:string,
     *     shipping_policy_url:string,
     *     return_days:int,
     *     return_fees:string,
     *     return_shipping_fee:float,
     *     return_method:string,
     *     country:string,
     *     configured:array<string, bool>
     * }
     */
    public function resolve(?string $scope = null): array
    {
        return $this->resolveWithCandidates($this->candidateScopes($scope));
    }

    /**
     * 按调用方给定的「近→远」scope 链解析（后台编辑页展示生效值用）。
     *
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public function resolveScopes(array $scopes): array
    {
        $candidates = [];
        $hasGlobal = false;
        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if ($scope === '') {
                continue;
            }
            if ($scope === SystemConfig::SCOPE_GLOBAL) {
                $hasGlobal = true;
                continue;
            }
            if (!in_array($scope, $candidates, true)) {
                $candidates[] = $scope;
            }
        }
        if ($hasGlobal) {
            $candidates[] = SystemConfig::SCOPE_GLOBAL;
        }

        return $this->resolveWithCandidates($candidates !== [] ? $candidates : [null]);
    }

    /**
     * @param list<string|null> $candidates
     * @return array<string, mixed>
     */
    private function resolveWithCandidates(array $candidates): array
    {
        $configured = [];
        $strict = count($candidates) > 1;
        $read = function (string $key) use ($candidates, $strict, &$configured): string {
            $configured[$key] = false;
            $config = $this->systemConfig();
            if ($config === null) {
                return '';
            }
            foreach ($candidates as $candidate) {
                try {
                    $resolved = $config->resolveConfig(
                        $key,
                        self::CONFIG_MODULE,
                        self::CONFIG_AREA,
                        $candidate,
                        SystemConfig::LOCALE_DEFAULT,
                        null
                    );
                } catch (\Throwable) {
                    continue;
                }
                if (empty($resolved['found'])) {
                    continue;
                }
                // 多级候选时必须命中本级自身，避免 resolveConfig 的兜底把上级值当成下级值。
                if ($strict) {
                    $sourceScope = strtolower(trim((string) ($resolved['source']['scope'] ?? '')));
                    if ($sourceScope !== strtolower((string) $candidate)) {
                        continue;
                    }
                }
                $configured[$key] = true;
                $value = $resolved['value'] ?? null;

                return is_scalar($value) ? trim((string)$value) : '';
            }

            return '';
        };

        $country = strtoupper($read(self::KEY_COUNTRY));
        if ($country !== self::DEFAULT_COUNTRY && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $country = self::DEFAULT_COUNTRY;
        }

        $returnDaysRaw = $read(self::KEY_RETURN_DAYS);
        $returnDays = $returnDaysRaw === '' ? self::DEFAULT_RETURN_DAYS : (int)$returnDaysRaw;
        $returnDays = max(0, min(365, $returnDays));

        $returnFees = strtolower($read(self::KEY_RETURN_FEES));
        if (!in_array($returnFees, [self::RETURN_FEES_BUYER, self::RETURN_FEES_FREE], true)) {
            $returnFees = self::DEFAULT_RETURN_FEES;
        }

        $returnShippingFeeRaw = $read(self::KEY_RETURN_SHIPPING_FEE);
        $returnShippingFee = $returnShippingFeeRaw === ''
            ? self::DEFAULT_RETURN_SHIPPING_FEE
            : (float)$returnShippingFeeRaw;
        if (!is_finite($returnShippingFee) || $returnShippingFee < 0) {
            $returnShippingFee = self::DEFAULT_RETURN_SHIPPING_FEE;
        }
        $returnShippingFee = min(9999.0, $returnShippingFee);

        $returnMethod = strtolower($read(self::KEY_RETURN_METHOD));
        if (!in_array($returnMethod, [self::RETURN_METHOD_MAIL, self::RETURN_METHOD_IN_STORE], true)) {
            $returnMethod = self::DEFAULT_RETURN_METHOD;
        }

        return [
            'name' => $read(self::KEY_NAME),
            'brand' => $read(self::KEY_BRAND),
            'email' => $read(self::KEY_EMAIL),
            'phone' => $read(self::KEY_PHONE),
            'address' => $read(self::KEY_ADDRESS),
            'return_policy_url' => $this->normalizeUrl($read(self::KEY_RETURN_POLICY_URL), self::DEFAULT_RETURN_POLICY_URL),
            'shipping_policy_url' => $this->normalizeUrl($read(self::KEY_SHIPPING_POLICY_URL), self::DEFAULT_SHIPPING_POLICY_URL),
            'return_days' => $returnDays,
            'return_fees' => $returnFees,
            'return_shipping_fee' => $returnShippingFee,
            'return_method' => $returnMethod,
            'country' => $country,
            'configured' => $configured,
        ];
    }

    private function normalizeUrl(string $value, string $default): string
    {
        if ($value === '') {
            return $default;
        }
        if (str_starts_with($value, '/') || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        return $default;
    }

    /**
     * 读取优先级：显式 scope → 当前请求 ScopeIdentity 的精确存储 scope 链（近→远，Global 收尾）。
     *
     * 不能直接用 SystemConfig 的 legacy scope 字符串：默认站（website code=default）的
     * store/channel 存储串与 Global 相同（default.default.default），会把网站级配置吃掉。
     *
     * @return list<string|null>
     */
    private function candidateScopes(?string $scope): array
    {
        $scope = trim((string) $scope);
        if ($scope !== '') {
            return [$scope];
        }

        $identity = RequestContext::scopeIdentity();
        if (!$identity instanceof ScopeIdentity) {
            return [null];
        }

        try {
            /** @var SystemConfigScopeResolver $resolver */
            $resolver = ObjectManager::getInstance(SystemConfigScopeResolver::class);
            $chain = $resolver->chainFromIdentity($identity);
        } catch (\Throwable) {
            return [null];
        }

        $scopes = [];
        $hasGlobal = false;
        foreach ($chain as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate === '') {
                continue;
            }
            if ($candidate === SystemConfig::SCOPE_GLOBAL) {
                $hasGlobal = true;
                continue;
            }
            if (!in_array($candidate, $scopes, true)) {
                $scopes[] = $candidate;
            }
        }
        if ($hasGlobal) {
            $scopes[] = SystemConfig::SCOPE_GLOBAL;
        }

        return $scopes !== [] ? $scopes : [null];
    }

    private function systemConfig(): ?SystemConfig
    {
        if ($this->systemConfigResolved) {
            return $this->systemConfig;
        }
        $this->systemConfigResolved = true;
        try {
            $this->systemConfig = ObjectManager::getInstance(SystemConfig::class);
        } catch (\Throwable) {
            $this->systemConfig = null;
        }

        return $this->systemConfig;
    }
}
