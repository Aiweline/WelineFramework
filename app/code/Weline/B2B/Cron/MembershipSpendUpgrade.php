<?php

declare(strict_types=1);

namespace Weline\B2B\Cron;

use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\B2BCreditGrantService;
use Weline\B2B\Service\B2BPaymentAssetPolicyProvider;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\B2B\Service\MembershipSpendQuery;
use Weline\Framework\Cron\Attribute\CronTestHelp;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

#[CronTestHelp(
    description: '按 tob 已付清消费累计，将系统 VIP 阶梯客户升到满足门槛的最高可用档，并补差额授信。',
    examples: ['php bin/w cron:test --task=b2b_membership_spend_upgrade -v'],
    manual_help: [
        '仅处理 membership 落在系统 vip0–vip12 的客户；自定义组不参与。',
        '批发信用开关关闭时任务直接跳过。',
        '禁用档不可升入；可升到下一可用更高档。',
    ],
)]
final class MembershipSpendUpgrade implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('B2B 批发消费升档');
    }

    public function execute_name(): string
    {
        return 'b2b_membership_spend_upgrade';
    }

    public function tip(): string
    {
        return (string)__('系统 VIP 阶梯按 tob 已付清消费升档并授信');
    }

    public function cron_time(): string
    {
        return '15 */6 * * *';
    }

    public function execute(): string
    {
        $result = $this->runUpgrade();

        return (string)__(
            'B2B 消费升档完成：扫描 %{1}，升档 %{2}，跳过 %{3}，失败 %{4}',
            [
                (string)$result['scanned'],
                (string)$result['upgraded'],
                (string)$result['skipped'],
                (string)$result['failed'],
            ],
        );
    }

    /**
     * @param array<string,mixed> $options
     */
    public function test(array $options = []): string
    {
        return $this->execute();
    }

    /**
     * @return array{scanned:int,upgraded:int,skipped:int,failed:int,reason?:string}
     */
    public function runUpgrade(
        ?CustomerGroupStore $groups = null,
        ?MembershipSpendQuery $spend = null,
        ?B2BCreditGrantService $grant = null,
        ?B2BPaymentAssetPolicyProvider $creditPolicy = null,
    ): array {
        $groups ??= ObjectManager::getInstance(CustomerGroupStore::class);
        $spend ??= ObjectManager::getInstance(MembershipSpendQuery::class);
        $grant ??= ObjectManager::getInstance(B2BCreditGrantService::class);
        $creditPolicy ??= ObjectManager::getInstance(B2BPaymentAssetPolicyProvider::class);

        if (!$creditPolicy->isEnabled()) {
            return [
                'scanned' => 0,
                'upgraded' => 0,
                'skipped' => 0,
                'failed' => 0,
                'reason' => 'credit_disabled',
            ];
        }

        $groups->ensureSystemVipLadder(0);
        $memberships = $groups->listSystemVipMemberships();
        $scanned = 0;
        $upgraded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($memberships as $row) {
            $scanned++;
            $customerId = (string)$row['customer_id'];
            $websiteId = (int)$row['website_id'];
            $currentGroupId = (string)$row['group_id'];
            $currentTier = SystemVipLadder::tierFromGroupId($currentGroupId);
            if ($currentTier === null) {
                $skipped++;
                continue;
            }
            try {
                $total = $spend->sumPaidTobGoodsMinor($customerId, $websiteId);
                $target = $this->highestEligibleTier($groups, $total);
                if ($target === null || (int)$target['tier_rank'] <= $currentTier) {
                    $skipped++;
                    continue;
                }
                $groups->assignCustomer($customerId, (string)$target['group_id']);
                $grant->grantToTarget($customerId, $websiteId, (string)$target['group_id']);
                $upgraded++;
            } catch (\Throwable $e) {
                $failed++;
                w_log_error('b2b_membership_spend_upgrade_failed: ' . $e->getMessage(), [
                    'customer_id' => $customerId,
                    'website_id' => $websiteId,
                ]);
            }
        }

        return compact('scanned', 'upgraded', 'skipped', 'failed');
    }

    /**
     * @return array{tier_rank:int,group_id:string}|null
     */
    private function highestEligibleTier(CustomerGroupStore $groups, int $spendMinor): ?array
    {
        $best = null;
        foreach (SystemVipLadder::seedDefinitions() as $seed) {
            $group = $groups->get($seed['group_id']);
            if ($group === null || !$group->isActive() || $group->status === CustomerGroup::STATUS_DISABLED) {
                continue;
            }
            $meta = $groups->groupCreditMeta($seed['group_id']);
            $threshold = (int)($meta['spend_threshold_minor'] ?? $seed['spend_threshold_minor']);
            if ($spendMinor < $threshold) {
                continue;
            }
            if ($best === null || (int)$seed['tier_rank'] > (int)$best['tier_rank']) {
                $best = [
                    'tier_rank' => (int)$seed['tier_rank'],
                    'group_id' => $seed['group_id'],
                ];
            }
        }

        return $best;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return max(5, $minute);
    }
}
