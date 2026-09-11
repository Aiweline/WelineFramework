<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\SystemVipLadder;

/**
 * 前台账户「批发身份」：当前等级、优惠要点、下一系统档。
 */
final class AccountMembershipTierPresenter
{
    public function __construct(
        private readonly CustomerGroupStore $groups = new CustomerGroupStore(),
        private readonly ?MembershipSpendQuery $spend = null,
    ) {
    }

    /**
     * @return array{
     *   has_membership:bool,
     *   group_id:string,
     *   group_name:string,
     *   group_code:string,
     *   description:string,
     *   credit_limit_minor:int,
     *   spend_threshold_minor:int,
     *   spend_minor:int,
     *   benefits:list<string>,
     *   next:?array{
     *     group_id:string,
     *     group_name:string,
     *     group_code:string,
     *     description:string,
     *     credit_limit_minor:int,
     *     spend_threshold_minor:int,
     *     remain_spend_minor:int
     *   }
     * }
     */
    public function present(string $customerId, int $websiteId): array
    {
        $customerId = trim($customerId);
        $this->groups->ensureSystemVipLadder($websiteId);
        $group = $customerId !== '' ? $this->groups->groupForCustomer($customerId, $websiteId) : null;
        if ($group === null) {
            return [
                'has_membership' => false,
                'group_id' => '',
                'group_name' => '',
                'group_code' => '',
                'description' => '',
                'credit_limit_minor' => 0,
                'spend_threshold_minor' => 0,
                'spend_minor' => 0,
                'benefits' => [],
                'next' => null,
            ];
        }

        $meta = $this->groups->groupCreditMeta($group->groupId) ?? [];
        $name = trim((string)($meta['name'] ?? '')) !== ''
            ? trim((string)$meta['name'])
            : $group->code;
        $description = trim((string)($meta['description'] ?? ''));
        $credit = max(0, (int)($meta['credit_limit_minor'] ?? 0));
        $threshold = max(0, (int)($meta['spend_threshold_minor'] ?? 0));
        $spendMinor = 0;
        if ($customerId !== '') {
            try {
                $spendMinor = max(0, ($this->spend ?? new MembershipSpendQuery())->sumPaidTobGoodsMinor($customerId, $websiteId));
            } catch (\Throwable) {
                $spendMinor = 0;
            }
        }

        return [
            'has_membership' => true,
            'group_id' => $group->groupId,
            'group_name' => $name,
            'group_code' => $group->code,
            'description' => $description,
            'credit_limit_minor' => $credit,
            'spend_threshold_minor' => $threshold,
            'spend_minor' => $spendMinor,
            'benefits' => $this->benefitsFor($credit, $description),
            'next' => $this->nextSystemTier($group, $spendMinor),
        ];
    }

    /**
     * @return list<string>
     */
    private function benefitsFor(int $creditLimitMinor, string $description): array
    {
        $benefits = [
            (string)__('批发价目成交（批发模式不再叠零售优惠券/满减）'),
            (string)__('数量阶梯批发价（达标起订量后按档计价）'),
        ];
        if ($creditLimitMinor > 0) {
            $benefits[] = (string)__('达标折扣额度可用于批发订单优惠抵扣（需开启批发信用）');
        }
        if ($description !== '') {
            $benefits[] = $description;
        }

        return $benefits;
    }

    /**
     * @return array{
     *   group_id:string,
     *   group_name:string,
     *   group_code:string,
     *   description:string,
     *   credit_limit_minor:int,
     *   spend_threshold_minor:int,
     *   remain_spend_minor:int
     * }|null
     */
    private function nextSystemTier(CustomerGroup $current, int $spendMinor): ?array
    {
        $currentTier = SystemVipLadder::tierFromGroupId($current->groupId);
        if ($currentTier === null) {
            return null;
        }
        for ($tier = $currentTier + 1; $tier <= SystemVipLadder::TIER_MAX; $tier++) {
            $groupId = SystemVipLadder::groupId($tier);
            $group = $this->groups->get($groupId);
            if ($group === null || !$group->isActive() || $group->status === CustomerGroup::STATUS_DISABLED) {
                continue;
            }
            $meta = $this->groups->groupCreditMeta($groupId) ?? [];
            $seed = null;
            foreach (SystemVipLadder::seedDefinitions() as $row) {
                if ((int)$row['tier_rank'] === $tier) {
                    $seed = $row;
                    break;
                }
            }
            $name = trim((string)($meta['name'] ?? '')) !== ''
                ? trim((string)$meta['name'])
                : (string)($seed['name'] ?? $group->code);
            $threshold = max(0, (int)($meta['spend_threshold_minor'] ?? ($seed['spend_threshold_minor'] ?? 0)));
            $credit = max(0, (int)($meta['credit_limit_minor'] ?? ($seed['credit_limit_minor'] ?? 0)));
            $description = trim((string)($meta['description'] ?? ($seed['description'] ?? '')));

            return [
                'group_id' => $groupId,
                'group_name' => $name,
                'group_code' => $group->code,
                'description' => $description,
                'credit_limit_minor' => $credit,
                'spend_threshold_minor' => $threshold,
                'remain_spend_minor' => max(0, $threshold - $spendMinor),
            ];
        }

        return null;
    }
}
