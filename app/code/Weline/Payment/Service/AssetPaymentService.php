<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader as SystemConfig;

final class AssetPaymentService
{
    /**
     * @var string[]
     */
    private const BUILT_IN_ASSETS = [
        AssetAllocationService::ASSET_CREDIT,
        AssetAllocationService::ASSET_POINTS,
        AssetAllocationService::ASSET_WCOIN,
    ];

    public function __construct(
        private readonly ?AssetAllocationService $allocationService = null,
        private readonly ?PaymentScopeConfigService $scopeConfigService = null,
        private ?SystemConfig $systemConfig = null
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getDefaultAssetPolicy(): array
    {
        $policy = [];
        foreach (self::BUILT_IN_ASSETS as $assetCode) {
            $policy[$assetCode] = [
                'enabled' => false,
                'roles' => [
                    AssetAllocationService::ROLE_PAYMENT => false,
                    AssetAllocationService::ROLE_DISCOUNT => false,
                ],
                'exchange_ratio' => 0.0,
                'max_discount_ratio' => 1.0,
                'allowed_payable_types' => [],
                'refund_strategy' => 'allocation',
            ];
        }

        return $policy;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array<string, mixed>>
     */
    public function buildAssetPolicy(array $config = []): array
    {
        $policy = $this->getDefaultAssetPolicy();
        $configuredAssets = \is_array($config['assets'] ?? null) ? $config['assets'] : $config;

        foreach ($configuredAssets as $assetCode => $assetConfig) {
            if (!\is_array($assetConfig)) {
                continue;
            }

            $assetCode = $this->getAllocationService()->normalizeAssetCode((string) $assetCode);
            $roles = \is_array($assetConfig['roles'] ?? null) ? $assetConfig['roles'] : [];
            $enabled = !empty($assetConfig['enabled']);
            $policy[$assetCode] = [
                'enabled' => $enabled,
                'roles' => [
                    AssetAllocationService::ROLE_PAYMENT => $enabled && !empty($roles[AssetAllocationService::ROLE_PAYMENT]),
                    AssetAllocationService::ROLE_DISCOUNT => $enabled && !empty($roles[AssetAllocationService::ROLE_DISCOUNT]),
                ],
                'exchange_ratio' => (float) ($assetConfig['exchange_ratio'] ?? 0),
                'max_discount_ratio' => max(0.0, min(1.0, (float) ($assetConfig['max_discount_ratio'] ?? 1))),
                'allowed_payable_types' => $this->normalizeList($assetConfig['allowed_payable_types'] ?? []),
                'refund_strategy' => (string) ($assetConfig['refund_strategy'] ?? 'allocation'),
            ];
        }

        return $policy;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, array<string, mixed>>
     */
    public function getPolicyForScope(array $context = []): array
    {
        $scope = $this->getScopeConfigService()->resolveScope($context);
        $map = $this->getSystemConfig()->getConfigMapByModule(
            PaymentScopeConfigService::MODULE_WELINE_PAYMENT,
            SystemConfig::area_BACKEND,
            $scope['scope']
        );
        $config = [];
        foreach (self::BUILT_IN_ASSETS as $assetCode) {
            $prefix = 'payment/asset/' . $assetCode . '/';
            foreach ($map as $key => $value) {
                $key = (string) $key;
                if (!str_starts_with($key, $prefix)) {
                    continue;
                }
                $config[$assetCode][substr($key, \strlen($prefix))] = $value;
            }
            if (!isset($config[$assetCode])) {
                continue;
            }
            $config[$assetCode]['roles'] = [
                AssetAllocationService::ROLE_PAYMENT => $this->toBool($config[$assetCode]['allow_payment'] ?? false),
                AssetAllocationService::ROLE_DISCOUNT => $this->toBool($config[$assetCode]['allow_discount'] ?? false),
            ];
            $config[$assetCode]['enabled'] = $this->toBool($config[$assetCode]['enabled'] ?? false)
                && (float) ($config[$assetCode]['exchange_ratio'] ?? 0) > 0;
            $config[$assetCode]['allowed_payable_types'] = $this->normalizeList($config[$assetCode]['allowed_payable_types'] ?? []);
            $config[$assetCode]['max_discount_ratio'] = max(0.0, min(1.0, (float) ($config[$assetCode]['max_discount_ratio'] ?? 1)));
        }

        return $this->buildAssetPolicy($config);
    }

    /**
     * @param array<string, mixed> $payable
     * @param array<int, array<string, mixed>> $assetRequests
     * @param array<string, mixed> $policy
     * @return array<int, array<string, mixed>>
     */
    public function reserveAllocations(array $payable, array $assetRequests, array $policy = []): array
    {
        $policy = $this->buildAssetPolicy($policy);
        $allocations = [];

        foreach ($assetRequests as $request) {
            $assetCode = $this->getAllocationService()->normalizeAssetCode((string) ($request['asset_code'] ?? ''));
            $role = $this->getAllocationService()->normalizeRole((string) ($request['role'] ?? ''));
            $this->assertAssetRoleAllowed($assetCode, $role, $policy);
            $this->assertAssetPayableAllowed($assetCode, $payable, $policy);
            $allocations[] = $this->getAllocationService()->reserve($request, $payable);
        }

        $this->getAllocationService()->assertNoDualRole($allocations);

        return $allocations;
    }

    /**
     * @param array<int, array<string, mixed>> $allocations
     * @param array<string, int> $amountsByAllocationCode
     * @return array<int, array<string, mixed>>
     */
    public function commitAllocations(array $allocations, array $amountsByAllocationCode = []): array
    {
        return $this->mapAllocations($allocations, $amountsByAllocationCode, 'commit');
    }

    /**
     * @param array<int, array<string, mixed>> $allocations
     * @param array<string, int> $amountsByAllocationCode
     * @return array<int, array<string, mixed>>
     */
    public function releaseAllocations(array $allocations, array $amountsByAllocationCode = []): array
    {
        return $this->mapAllocations($allocations, $amountsByAllocationCode, 'release');
    }

    /**
     * @param array<int, array<string, mixed>> $allocations
     * @param array<string, int> $amountsByAllocationCode
     * @return array<int, array<string, mixed>>
     */
    public function refundAllocations(array $allocations, array $amountsByAllocationCode = []): array
    {
        return $this->mapAllocations($allocations, $amountsByAllocationCode, 'refund');
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<int, array<string, mixed>> $allocations
     */
    public function calculateExternalPayableAmountMinor(array $snapshot, array $allocations): int
    {
        $amountMinor = (int) ($snapshot['amount_minor'] ?? $snapshot['amounts']['payable_amount_minor'] ?? 0);
        foreach ($allocations as $allocation) {
            $normalized = $this->getAllocationService()->normalizeAllocation($allocation, $allocation);
            $amountMinor -= $normalized['amount_minor'];
        }

        return max(0, $amountMinor);
    }

    /**
     * @param array<int, array<string, mixed>> $allocations
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function groupAllocationsByRole(array $allocations): array
    {
        $grouped = [
            AssetAllocationService::ROLE_PAYMENT => [],
            AssetAllocationService::ROLE_DISCOUNT => [],
        ];

        foreach ($allocations as $allocation) {
            $normalized = $this->getAllocationService()->normalizeAllocation($allocation, $allocation);
            $grouped[$normalized['role']][] = $normalized;
        }

        return $grouped;
    }

    /**
     * @param array<string, array<string, mixed>> $policy
     */
    public function assertAssetRoleAllowed(string $assetCode, string $role, array $policy): void
    {
        $assetCode = $this->getAllocationService()->normalizeAssetCode($assetCode);
        $role = $this->getAllocationService()->normalizeRole($role);
        $assetPolicy = $policy[$assetCode] ?? null;

        if (!\is_array($assetPolicy) || empty($assetPolicy['enabled']) || empty($assetPolicy['roles'][$role])) {
            throw new \LogicException('payment_asset_role_disabled:' . $assetCode . ':' . $role);
        }
    }

    /**
     * @param array<string, mixed> $payable
     * @param array<string, array<string, mixed>> $policy
     */
    public function assertAssetPayableAllowed(string $assetCode, array $payable, array $policy): void
    {
        $assetCode = $this->getAllocationService()->normalizeAssetCode($assetCode);
        $assetPolicy = $policy[$assetCode] ?? null;
        if (!\is_array($assetPolicy)) {
            throw new \LogicException('payment_asset_policy_missing:' . $assetCode);
        }

        $allowed = $this->normalizeList($assetPolicy['allowed_payable_types'] ?? []);
        if ($allowed === []) {
            return;
        }

        $payableType = strtolower(trim((string) ($payable['payable_type'] ?? '')));
        if (!\in_array($payableType, array_map('strtolower', $allowed), true)) {
            throw new \LogicException('payment_asset_payable_type_not_allowed:' . $assetCode . ':' . $payableType);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $allocations
     * @param array<string, int> $amountsByAllocationCode
     * @return array<int, array<string, mixed>>
     */
    private function mapAllocations(array $allocations, array $amountsByAllocationCode, string $operation): array
    {
        $mapped = [];
        foreach ($allocations as $allocation) {
            $normalized = $this->getAllocationService()->normalizeAllocation($allocation, $allocation);
            $amountMinor = $amountsByAllocationCode[$normalized['allocation_code']] ?? null;
            $mapped[] = match ($operation) {
                'commit' => $this->getAllocationService()->commit($normalized, $amountMinor),
                'release' => $this->getAllocationService()->release($normalized, $amountMinor),
                'refund' => $this->getAllocationService()->refund($normalized, $amountMinor),
                default => throw new \InvalidArgumentException('payment_asset_operation_invalid:' . $operation),
            };
        }

        return $mapped;
    }

    private function getAllocationService(): AssetAllocationService
    {
        return $this->allocationService ?? new AssetAllocationService();
    }

    private function getScopeConfigService(): PaymentScopeConfigService
    {
        return $this->scopeConfigService ?? new PaymentScopeConfigService();
    }

    private function getSystemConfig(): SystemConfig
    {
        if ($this->systemConfig === null) {
            $this->systemConfig = ObjectManager::getInstance(SystemConfig::class);
        }

        return $this->systemConfig;
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        $items = \is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value));
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }
}
