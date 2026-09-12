<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\SystemVipLadder;
use Weline\Payment\Api\PaymentAssetPolicyProviderInterface;
use Weline\Payment\Service\AssetAllocationService;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/** Contributes tob-only b2b_credit discount policy when B2B credit is enabled. */
final class B2BPaymentAssetPolicyProvider implements PaymentAssetPolicyProviderInterface
{
    public const CONFIG_CREDIT_ENABLED = 'b2b_credit_enabled';
    public const CONFIG_MIN_CASH_DEPOSIT_PERCENT = 'b2b_credit_min_cash_deposit_percent';
    /** Default: at least 20% of deposit must stay cash when credit is applied. */
    public const DEFAULT_MIN_CASH_DEPOSIT_PERCENT = 20;

    private const CONFIG_MODULE = 'Weline_B2B';
    private const CONFIG_AREA = ConfigReader::area_FRONTEND;

    /** @var bool|null Explicit test override */
    private ?bool $testingEnabled = null;
    /** @var int|null Explicit test override (0–100) */
    private ?int $testingMinCashPercent = null;

    public function __construct(private ?ConfigStore $config = null)
    {
    }

    public static function forTesting(bool $enabled = true, int $minCashDepositPercent = self::DEFAULT_MIN_CASH_DEPOSIT_PERCENT): self
    {
        $provider = new self();
        $provider->testingEnabled = $enabled;
        $provider->testingMinCashPercent = max(0, min(100, $minCashDepositPercent));

        return $provider;
    }

    public function getAssetPolicies(array $context = []): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $minCashPercent = $this->minCashDepositPercent();
        $minCashBps = $minCashPercent * 100;
        // Credit may cover at most the remainder after the cash floor.
        $maxDiscountRatio = number_format(max(0, 100 - $minCashPercent) / 100, 4, '.', '');

        return [
            SystemVipLadder::ASSET_CODE_B2B_CREDIT => [
                'enabled' => true,
                'roles' => [
                    AssetAllocationService::ROLE_PAYMENT => false,
                    AssetAllocationService::ROLE_DISCOUNT => true,
                ],
                'exchange_ratio' => '1',
                'max_discount_ratio' => $maxDiscountRatio,
                'min_cash_deposit_bps' => $minCashBps,
                'allowed_payable_types' => [],
                'required_order_types' => ['tob'],
                'refund_strategy' => 'allocation',
            ],
        ];
    }

    public function isEnabled(): bool
    {
        if ($this->testingEnabled !== null) {
            return $this->testingEnabled;
        }
        try {
            $resolved = $this->configStore()->resolveConfig(
                self::CONFIG_CREDIT_ENABLED,
                self::CONFIG_MODULE,
                self::CONFIG_AREA,
                ConfigStore::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
                false,
            );
            $raw = is_array($resolved) ? ($resolved['value'] ?? false) : false;
        } catch (\Throwable) {
            return false;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string)$raw));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    /** 0–100: minimum cash share of the deposit when wholesale credit is applied. */
    public function minCashDepositPercent(): int
    {
        if ($this->testingMinCashPercent !== null) {
            return $this->testingMinCashPercent;
        }
        try {
            $resolved = $this->configStore()->resolveConfig(
                self::CONFIG_MIN_CASH_DEPOSIT_PERCENT,
                self::CONFIG_MODULE,
                self::CONFIG_AREA,
                ConfigStore::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
                false,
            );
            $raw = is_array($resolved) ? ($resolved['value'] ?? self::DEFAULT_MIN_CASH_DEPOSIT_PERCENT) : self::DEFAULT_MIN_CASH_DEPOSIT_PERCENT;
        } catch (\Throwable) {
            return self::DEFAULT_MIN_CASH_DEPOSIT_PERCENT;
        }
        if (is_numeric($raw)) {
            return max(0, min(100, (int)$raw));
        }

        return self::DEFAULT_MIN_CASH_DEPOSIT_PERCENT;
    }

    private function configStore(): ConfigStore
    {
        return $this->config ??= new ConfigStore();
    }
}
