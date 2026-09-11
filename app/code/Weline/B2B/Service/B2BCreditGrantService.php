<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\SystemVipLadder;
use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/** Top-up customer b2b_credit wallet to the group's target limit (never claw back). */
final class B2BCreditGrantService
{
    public function __construct(
        private readonly CustomerGroupStore $groups,
        private readonly B2BPaymentAssetPolicyProvider $creditPolicy,
        private ?CustomerAssetFacadeInterface $assets = null,
    ) {
    }

    public static function forTesting(
        CustomerGroupStore $groups,
        B2BPaymentAssetPolicyProvider $creditPolicy,
        ?CustomerAssetFacadeInterface $assets = null,
    ): self {
        return new self($groups, $creditPolicy, $assets);
    }

    /**
     * @return array{ok:bool,granted_minor:int,target_minor:int,balance_minor:int,skipped:?string}
     */
    public function grantToTarget(string $customerId, int $websiteId, string $groupId): array
    {
        $customerId = trim($customerId);
        $groupId = trim($groupId);
        $empty = [
            'ok' => false,
            'granted_minor' => 0,
            'target_minor' => 0,
            'balance_minor' => 0,
            'skipped' => null,
        ];
        if ($customerId === '' || $websiteId < 0 || $groupId === '') {
            return array_merge($empty, ['skipped' => 'invalid_args']);
        }
        if (!$this->creditPolicy->isEnabled()) {
            return array_merge($empty, ['skipped' => 'credit_disabled']);
        }

        $meta = $this->groups->groupCreditMeta($groupId);
        if ($meta === null) {
            return array_merge($empty, ['skipped' => 'group_not_found']);
        }
        $target = max(0, (int)$meta['credit_limit_minor']);
        $assets = $this->assets();
        if ($assets === null) {
            return array_merge($empty, ['skipped' => 'customer_asset_unavailable', 'target_minor' => $target]);
        }

        $balance = $this->readBalance($assets, $customerId, $websiteId);
        if ($balance >= $target) {
            return [
                'ok' => true,
                'granted_minor' => 0,
                'target_minor' => $target,
                'balance_minor' => $balance,
                'skipped' => 'already_at_or_above_target',
            ];
        }

        $delta = $target - $balance;
        $eventId = sprintf(
            'b2b-credit-grant:%s:%d:%s:v%d:t%d',
            $customerId,
            $websiteId,
            $groupId,
            (int)$meta['group_version'],
            $target,
        );
        $assets->credit([
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'asset_code' => SystemVipLadder::ASSET_CODE_B2B_CREDIT,
            'namespace' => AssetAccount::NS_LIVE,
            'amount_minor' => $delta,
            'event_id' => $eventId,
        ]);

        return [
            'ok' => true,
            'granted_minor' => $delta,
            'target_minor' => $target,
            'balance_minor' => $balance + $delta,
            'skipped' => null,
        ];
    }

    private function readBalance(CustomerAssetFacadeInterface $assets, string $customerId, int $websiteId): int
    {
        $row = $assets->getBalance(
            $customerId,
            $websiteId,
            SystemVipLadder::ASSET_CODE_B2B_CREDIT,
            AssetAccount::NS_LIVE,
        );
        if (!is_array($row)) {
            return 0;
        }
        $available = (int)($row['available_minor'] ?? $row['available'] ?? 0);
        $reserved = (int)($row['reserved_minor'] ?? $row['reserved'] ?? 0);

        return max(0, $available + $reserved);
    }

    private function assets(): ?CustomerAssetFacadeInterface
    {
        if ($this->assets instanceof CustomerAssetFacadeInterface) {
            return $this->assets;
        }
        try {
            /** @var RuntimeProviderResolver $resolver */
            $resolver = ObjectManager::getInstance(RuntimeProviderResolver::class);
            $provider = $resolver->resolve(CustomerAssetFacadeInterface::class);
            if ($provider instanceof CustomerAssetFacadeInterface) {
                return $this->assets = $provider;
            }
        } catch (\Throwable) {
        }
        try {
            $candidate = ObjectManager::getInstance(CustomerAssetFacadeInterface::class);
            if ($candidate instanceof CustomerAssetFacadeInterface) {
                return $this->assets = $candidate;
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
