<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\CustomerGroup;
use Weline\Framework\Runtime\RequestContext;

/**
 * FAQ /faq/b2b-wholesale：按后台客户组（VIP 等级）排序输出消费门槛与达标折扣额度。
 */
final class WholesaleFaqVipLadderPresenter
{
    public function __construct(
        private readonly CustomerGroupStore $groups = new CustomerGroupStore(),
        private readonly ?B2BBaseCurrencyResolver $currency = null,
        private readonly ?B2BPaymentAssetPolicyProvider $creditPolicy = null,
    ) {
    }

    /**
     * @return array{
     *   currency_code:string,
     *   credit_enabled:bool,
     *   groups:list<array{
     *     group_id:string,
     *     code:string,
     *     name:string,
     *     description:string,
     *     is_system:bool,
     *     tier_rank:int,
     *     spend_threshold_minor:int,
     *     credit_limit_minor:int,
     *     spend_threshold_major:string,
     *     credit_limit_major:string
     *   }>
     * }
     */
    public function present(?int $websiteId = null): array
    {
        $websiteId = max(0, $websiteId ?? (int)RequestContext::getWelineWebsiteId());
        $this->groups->ensureSystemVipLadder($websiteId);

        $currencyCode = ($this->currency ?? new B2BBaseCurrencyResolver())->forWebsite($websiteId);
        $creditEnabled = true;
        try {
            $creditEnabled = ($this->creditPolicy ?? new B2BPaymentAssetPolicyProvider())->isEnabled();
        } catch (\Throwable) {
            $creditEnabled = true;
        }

        $groups = [];
        foreach ($this->groups->listAdminRows(200) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((string)($row['status'] ?? '') !== CustomerGroup::STATUS_ACTIVE) {
                continue;
            }
            if ((int)($row['website_id'] ?? -1) !== $websiteId) {
                continue;
            }
            $spendMinor = max(0, (int)($row['spend_threshold_minor'] ?? 0));
            $creditMinor = max(0, (int)($row['credit_limit_minor'] ?? 0));
            $name = trim((string)($row['name'] ?? ''));
            $code = trim((string)($row['code'] ?? ''));
            $groups[] = [
                'group_id' => trim((string)($row['group_id'] ?? '')),
                'code' => $code,
                'name' => $name !== '' ? $name : $code,
                'description' => trim((string)($row['description'] ?? '')),
                'is_system' => !empty($row['is_system']),
                'tier_rank' => (int)($row['tier_rank'] ?? -1),
                'spend_threshold_minor' => $spendMinor,
                'credit_limit_minor' => $creditMinor,
                'spend_threshold_major' => self::formatMajor($spendMinor),
                'credit_limit_major' => self::formatMajor($creditMinor),
            ];
        }

        return [
            'currency_code' => $currencyCode,
            'credit_enabled' => $creditEnabled,
            'groups' => $groups,
        ];
    }

    public static function formatMajor(int $minor): string
    {
        return number_format(max(0, $minor) / 100, 2, '.', '');
    }
}
