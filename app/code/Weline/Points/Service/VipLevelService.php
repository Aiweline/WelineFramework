<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Points\Model\CustomerState;
use Weline\Points\Model\VipLevel;

/**
 * Growth and VIP state are Points-owned. They are never spendable balance.
 */
final class VipLevelService
{
    public function __construct(
        private readonly VipLevelResolver $resolver = new VipLevelResolver(),
        private readonly ?PointsConfig $config = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLevels(): array
    {
        $rows = ObjectManager::getInstance(VipLevel::class)
            ->clear()
            ->order(VipLevel::schema_fields_MIN_GROWTH, 'asc')
            ->order(VipLevel::schema_fields_SORT_ORDER, 'asc')
            ->select()
            ->fetchArray();

        $levels = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row)) {
                $levels[] = $this->normalizeRow($row);
            }
        }

        return $levels;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeLevels(): array
    {
        return array_values(array_filter(
            $this->listLevels(),
            static fn (array $level): bool => ($level['is_active'] ?? true) === true,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function stateFor(int $customerId, int $websiteId = 0, string $namespace = 'live'): array
    {
        if ($customerId <= 0) {
            return $this->emptyState($customerId, $websiteId, $namespace);
        }

        $row = ObjectManager::getInstance(CustomerState::class)
            ->clear()
            ->where(CustomerState::schema_fields_CUSTOMER_ID, $customerId)
            ->where(CustomerState::schema_fields_WEBSITE_ID, max(0, $websiteId))
            ->where(CustomerState::schema_fields_NAMESPACE, $namespace)
            ->find()
            ->fetchArray();

        return \is_array($row) && $row !== []
            ? $this->normalizeState($row)
            : $this->emptyState($customerId, $websiteId, $namespace);
    }

    /**
     * Add growth and re-resolve the current level. Growth never decreases.
     *
     * @return array<string, mixed>
     */
    public function applyGrowth(
        int $customerId,
        int $websiteId,
        string $namespace,
        int $growthDelta,
        bool $levelResolutionEnabled,
        int $lifetimePointsDelta = 0,
    ): array {
        if ($customerId <= 0 || ($growthDelta <= 0 && $lifetimePointsDelta <= 0)) {
            return $this->stateFor($customerId, $websiteId, $namespace);
        }

        $state = $this->stateFor($customerId, $websiteId, $namespace);
        $growth = max(0, (int) $state['growth_value']) + max(0, $growthDelta);
        $lifetimePoints = max(0, (int) $state['lifetime_points']) + max(0, $lifetimePointsDelta);
        $basis = $this->config?->vipBasis() ?? PointsConfig::VIP_BASIS_LIFETIME;
        $levelBasisValue = $basis === PointsConfig::VIP_BASIS_GROWTH ? $growth : $lifetimePoints;
        $level = $levelResolutionEnabled
            ? $this->resolver->resolve($this->activeLevels(), $levelBasisValue)
            : $this->resolver->standardLevel();

        $now = date('Y-m-d H:i:s');
        $payload = [
            CustomerState::schema_fields_CUSTOMER_ID => $customerId,
            CustomerState::schema_fields_WEBSITE_ID => max(0, $websiteId),
            CustomerState::schema_fields_NAMESPACE => $namespace,
            CustomerState::schema_fields_GROWTH_VALUE => $growth,
            CustomerState::schema_fields_LIFETIME_POINTS => $lifetimePoints,
            CustomerState::schema_fields_LEVEL_ID => (int) $level['level_id'],
            CustomerState::schema_fields_LEVEL_CODE => (string) $level['code'],
            CustomerState::schema_fields_UPDATED_AT => $now,
        ];

        if ((int) $state['state_id'] > 0) {
            ObjectManager::getInstance(CustomerState::class)
                ->clear()
                ->load((int) $state['state_id'])
                ->setData($payload)
                ->save();
        } else {
            ObjectManager::getInstance(CustomerState::class)
                ->clearData()
                ->setData($payload + [CustomerState::schema_fields_CREATED_AT => $now])
                ->save();
        }

        return $this->stateFor($customerId, $websiteId, $namespace);
    }

    /**
     * @return array<string, mixed>
     */
    public function benefitsFor(int $customerId, int $websiteId = 0, string $namespace = 'live'): array
    {
        $state = $this->stateFor($customerId, $websiteId, $namespace);
        $basis = $this->config?->vipBasis() ?? PointsConfig::VIP_BASIS_LIFETIME;
        $levelBasisValue = $basis === PointsConfig::VIP_BASIS_GROWTH
            ? (int) $state['growth_value']
            : (int) $state['lifetime_points'];
        $level = $this->resolver->resolve($this->activeLevels(), $levelBasisValue);

        return [
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'namespace' => $namespace,
            'growth_value' => (int) $state['growth_value'],
            'lifetime_points' => (int) $state['lifetime_points'],
            'vip_basis' => $basis,
            'level' => $level,
            'discount_rate' => (float) $level['discount_rate'],
            'points_multiplier' => (float) $level['points_multiplier'],
            'free_shipping' => (bool) $level['free_shipping'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyState(int $customerId, int $websiteId, string $namespace): array
    {
        return [
            'state_id' => 0,
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'namespace' => $namespace,
            'growth_value' => 0,
            'lifetime_points' => 0,
            'level_id' => 0,
            'level_code' => '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeState(array $row): array
    {
        return [
            'state_id' => (int) ($row[CustomerState::schema_fields_ID] ?? 0),
            'customer_id' => (int) ($row[CustomerState::schema_fields_CUSTOMER_ID] ?? 0),
            'website_id' => (int) ($row[CustomerState::schema_fields_WEBSITE_ID] ?? 0),
            'namespace' => (string) ($row[CustomerState::schema_fields_NAMESPACE] ?? 'live'),
            'growth_value' => (int) ($row[CustomerState::schema_fields_GROWTH_VALUE] ?? 0),
            'lifetime_points' => (int) ($row[CustomerState::schema_fields_LIFETIME_POINTS] ?? 0),
            'level_id' => (int) ($row[CustomerState::schema_fields_LEVEL_ID] ?? 0),
            'level_code' => (string) ($row[CustomerState::schema_fields_LEVEL_CODE] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'level_id' => (int) ($row[VipLevel::schema_fields_ID] ?? 0),
            'code' => (string) ($row[VipLevel::schema_fields_CODE] ?? ''),
            'name' => (string) ($row[VipLevel::schema_fields_NAME] ?? ''),
            'min_growth' => (int) ($row[VipLevel::schema_fields_MIN_GROWTH] ?? 0),
            'discount_rate' => (float) ($row[VipLevel::schema_fields_DISCOUNT_RATE] ?? 0),
            'points_multiplier' => (float) ($row[VipLevel::schema_fields_POINTS_MULTIPLIER] ?? 1),
            'free_shipping' => (int) ($row[VipLevel::schema_fields_FREE_SHIPPING] ?? 0) === 1,
            'is_active' => (int) ($row[VipLevel::schema_fields_IS_ACTIVE] ?? 1) === 1,
            'sort_order' => (int) ($row[VipLevel::schema_fields_SORT_ORDER] ?? 0),
        ];
    }
}
