<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Api\B2BPriceCandidateInterface;
use Weline\B2B\Model\PriceList;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * B2B 候选引擎：规则栈 channel → website list → retail；mode off 关闭 B2B 候选。
 */
final class B2BPriceEngine implements B2BPriceCandidateInterface
{
    public const CAPABILITY = 'b2b';

    public const SOURCE_RETAIL = 'retail';
    public const SOURCE_B2B_WEBSITE = 'b2b_website';
    public const SOURCE_B2B_CHANNEL = 'b2b_channel';
    public const SOURCE_B2B_DEFAULT_POLICY = 'b2b_default_policy';
    public const SOURCE_CLOSED = 'b2b_closed';

    public const ERROR_MODE_OFF = 'b2b_mode_off_closes_candidate';
    public const ERROR_GROUP_DISABLED = 'b2b_group_disabled';
    public const ERROR_GROUP_WEBSITE_MISMATCH = 'b2b_group_website_mismatch';
    public const ERROR_GROUP_OVERRIDE = 'b2b_group_override_rejected';
    public const ERROR_FORGED_PRICE_LIST = 'b2b_forged_price_list_rejected';
    public const ERROR_VERSION_MISMATCH = 'b2b_price_list_version_mismatch';
    public const ERROR_NO_SKU = 'b2b_price_list_sku_missing';

    public function __construct(
        private readonly CustomerGroupStore $groups,
        private readonly PriceListStore $lists,
        private readonly B2BRolloutGate $rollout,
        private readonly ?DefaultWholesalePolicy $defaultPolicy = null,
        private readonly ?WholesalePricingGuard $pricingGuard = null,
    ) {
    }

    public static function forTesting(
        ?B2BRolloutGate $rollout = null,
        ?DefaultWholesalePolicy $defaultPolicy = null,
        ?WholesalePricingGuard $pricingGuard = null,
    ): self {
        $gate = $rollout ?? B2BRolloutGate::forTestingConfiguration();
        $gate->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);

        return new self(
            CustomerGroupStore::forTesting(),
            PriceListStore::forTesting(),
            $gate,
            $defaultPolicy,
            $pricingGuard ?? new WholesalePricingGuard(),
        );
    }

    public function groups(): CustomerGroupStore
    {
        return $this->groups;
    }

    public function lists(): PriceListStore
    {
        return $this->lists;
    }

    public function rollout(): B2BRolloutGate
    {
        return $this->rollout;
    }

    /**
     * @return list<array{sku:string,outcome:string}>
     */
    public function orderAttempts(): array
    {
        return [];
    }

    public function orderCount(): int
    {
        return 0;
    }

    public function resolve(array $request): array
    {
        $customerId = trim((string) ($request['customer_id'] ?? ''));
        $websiteId = (int) ($request['website_id'] ?? -1);
        $channelId = isset($request['channel_id']) && $request['channel_id'] !== null && $request['channel_id'] !== ''
            ? (string) $request['channel_id']
            : null;
        $sku = trim((string) ($request['sku'] ?? ''));
        $qty = max(1, (int) ($request['qty'] ?? 1));
        $retail = (int) ($request['retail_amount_minor'] ?? -1);
        $claimedListId = isset($request['claimed_price_list_id']) && $request['claimed_price_list_id'] !== null && $request['claimed_price_list_id'] !== ''
            ? (string) $request['claimed_price_list_id']
            : null;
        $claimedVersion = array_key_exists('claimed_version', $request)
            && $request['claimed_version'] !== null
            ? (int) $request['claimed_version']
            : null;

        if ($websiteId < 0 || $sku === '' || $retail < 0) {
            throw new \InvalidArgumentException(__('B2B candidate 请求缺少合法 Website/SKU/amount'));
        }
        $explicitGroupId = isset($request['group_id']) && $request['group_id'] !== null && $request['group_id'] !== ''
            ? (string) $request['group_id']
            : null;
        if ($explicitGroupId !== null) {
            return [
                'ok' => false,
                'source' => self::SOURCE_RETAIL,
                'amount_minor' => $retail,
                'price_list_id' => null,
                'version' => null,
                'group_id' => null,
                'rule_stack' => [self::ERROR_GROUP_OVERRIDE],
                'error' => self::ERROR_GROUP_OVERRIDE,
            ];
        }

        $group = $this->groups->groupForCustomer($customerId, $websiteId);
        // Retail identity cannot claim either a B2B list or its version.
        if ($group === null) {
            if ($claimedListId !== null || $claimedVersion !== null) {
                return [
                    'ok' => false,
                    'source' => self::SOURCE_RETAIL,
                    'amount_minor' => $retail,
                    'price_list_id' => null,
                    'version' => null,
                    'group_id' => null,
                    'rule_stack' => ['retail', self::ERROR_FORGED_PRICE_LIST],
                    'error' => self::ERROR_FORGED_PRICE_LIST,
                ];
            }

            return $this->retailResult($retail, ['retail'], self::SOURCE_RETAIL, true);
        }

        if (!$group->isActive()) {
            return [
                'ok' => false,
                'source' => self::SOURCE_RETAIL,
                'amount_minor' => $retail,
                'price_list_id' => null,
                'version' => null,
                'group_id' => $group->groupId,
                'rule_stack' => [self::ERROR_GROUP_DISABLED],
                'error' => self::ERROR_GROUP_DISABLED,
            ];
        }

        $mode = $this->rollout->mode(self::CAPABILITY);
        if ($mode === CommerceRolloutGateInterface::MODE_OFF) {
            return $this->retailResult($retail, [self::ERROR_MODE_OFF], self::SOURCE_CLOSED, true);
        }

        $subject = 'website:' . $websiteId;
        if (in_array($mode, [CommerceRolloutGateInterface::MODE_ALLOWLIST, CommerceRolloutGateInterface::MODE_ON], true)) {
            try {
                $this->rollout->assertMutable(self::CAPABILITY, $subject);
            } catch (\Throwable $e) {
                return $this->retailResult($retail, ['allowlist_miss', 'retail'], self::SOURCE_RETAIL, true);
            }
        }

        $selected = $this->selectList($group->groupId, $websiteId, $channelId, $sku);
        if ($selected === null) {
            return $this->resolveDefaultPolicy(
                $request,
                $group->groupId,
                $websiteId,
                $sku,
                $qty,
                $retail,
                $claimedListId,
                $claimedVersion,
            );
        }

        if ($claimedListId !== null && $claimedListId !== $selected->listId) {
            return [
                'ok' => false,
                'source' => self::SOURCE_RETAIL,
                'amount_minor' => $retail,
                'price_list_id' => $selected->listId,
                'version' => $selected->version,
                'group_id' => $group->groupId,
                'rule_stack' => [self::ERROR_FORGED_PRICE_LIST],
                'error' => self::ERROR_FORGED_PRICE_LIST,
            ];
        }

        if ($claimedVersion !== null && $claimedVersion !== $selected->version) {
            return [
                'ok' => false,
                'source' => $selected->channelId !== null ? self::SOURCE_B2B_CHANNEL : self::SOURCE_B2B_WEBSITE,
                'amount_minor' => $retail,
                'price_list_id' => $selected->listId,
                'version' => $selected->version,
                'group_id' => $group->groupId,
                'rule_stack' => [self::ERROR_VERSION_MISMATCH],
                'error' => self::ERROR_VERSION_MISMATCH,
            ];
        }

        $amount = $selected->amountForSku($sku, $qty);
        if ($amount === null) {
            return [
                'ok' => false,
                'source' => $selected->channelId !== null ? self::SOURCE_B2B_CHANNEL : self::SOURCE_B2B_WEBSITE,
                'amount_minor' => $retail,
                'price_list_id' => $selected->listId,
                'version' => $selected->version,
                'group_id' => $group->groupId,
                'rule_stack' => [self::ERROR_NO_SKU],
                'error' => self::ERROR_NO_SKU,
            ];
        }

        $source = $selected->channelId !== null ? self::SOURCE_B2B_CHANNEL : self::SOURCE_B2B_WEBSITE;
        $stack = [];
        if ($selected->channelId !== null) {
            $stack[] = 'channel:' . $selected->channelId;
        }
        $stack[] = 'website_list:' . $selected->listId . '@v' . $selected->version;
        $stack[] = 'group:' . $group->groupId;

        // shadow：只返回候选，不记 order
        return [
            'ok' => true,
            'source' => $source,
            'amount_minor' => $amount,
            'price_list_id' => $selected->listId,
            'version' => $selected->version,
            'group_id' => $group->groupId,
            'rule_stack' => $stack,
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @param list<string> $extraStack
     * @return array{ok:bool,source:string,amount_minor:int,price_list_id:?string,version:?int,group_id:?string,rule_stack:list<string>,error?:string}
     */
    private function resolveDefaultPolicy(
        array $request,
        string $groupId,
        int $websiteId,
        string $sku,
        int $qty,
        int $retail,
        ?string $claimedListId,
        ?int $claimedVersion,
        array $extraStack = [],
    ): array {
        if (!$this->productTobAllowsTemplate($request)) {
            return $this->retailResult($retail, array_merge($extraStack, ['no_b2b_list', 'retail']), self::SOURCE_RETAIL, true);
        }

        $policy = $this->defaultPolicy();
        if ($policy === null || !$policy->groupCanInheritTemplate($groupId)) {
            return $this->retailResult($retail, array_merge($extraStack, ['no_b2b_list', 'retail']), self::SOURCE_RETAIL, true);
        }

        $tiers = $policy->tiersForGroup($groupId, $websiteId);
        if ($tiers === []) {
            return $this->retailResult($retail, array_merge($extraStack, ['no_default_policy', 'retail']), self::SOURCE_RETAIL, true);
        }

        $syntheticId = DefaultWholesalePolicy::syntheticListId($websiteId, $groupId);
        if ($claimedListId !== null
            && $claimedListId !== $syntheticId
            && !DefaultWholesalePolicy::isSyntheticListId($claimedListId)
        ) {
            return [
                'ok' => false,
                'source' => self::SOURCE_RETAIL,
                'amount_minor' => $retail,
                'price_list_id' => $syntheticId,
                'version' => 0,
                'group_id' => $groupId,
                'rule_stack' => [self::ERROR_FORGED_PRICE_LIST],
                'error' => self::ERROR_FORGED_PRICE_LIST,
            ];
        }
        if ($claimedVersion !== null && $claimedVersion !== 0) {
            return [
                'ok' => false,
                'source' => self::SOURCE_B2B_DEFAULT_POLICY,
                'amount_minor' => $retail,
                'price_list_id' => $syntheticId,
                'version' => 0,
                'group_id' => $groupId,
                'rule_stack' => [self::ERROR_VERSION_MISMATCH],
                'error' => self::ERROR_VERSION_MISMATCH,
            ];
        }

        $resolveQty = $qty;
        $lowest = $policy->lowestMinQty($tiers);
        if ($resolveQty < $lowest) {
            // Unit display / Provider may pass qty=1; use lowest MOQ for amount pick.
            $resolveQty = $lowest;
        }
        $amount = $policy->amountForQty($retail, $tiers, $resolveQty);
        if ($amount === null) {
            return $this->retailResult($retail, array_merge($extraStack, ['default_policy_qty_miss', 'retail']), self::SOURCE_RETAIL, true);
        }

        $bps = 0;
        foreach ($tiers as $tier) {
            if ($resolveQty >= (int) $tier['min_qty']) {
                $bps = (int) $tier['discount_bps'];
            }
        }
        try {
            $this->pricingGuard()->assertAmountAllowed(
                $retail,
                $amount,
                $policy->maxDiscountBps($websiteId),
                $policy->minMarginBps($websiteId),
                null,
            );
        } catch (\InvalidArgumentException) {
            return $this->retailResult($retail, array_merge($extraStack, ['default_policy_guard', 'retail']), self::SOURCE_RETAIL, true);
        }

        $tierRank = \Weline\B2B\Model\SystemVipLadder::tierFromGroupId($groupId) ?? 0;
        $stack = array_merge($extraStack, [
            'default_policy:vip' . $tierRank . '@d' . $bps,
            'group:' . $groupId,
        ]);

        return [
            'ok' => true,
            'source' => self::SOURCE_B2B_DEFAULT_POLICY,
            'amount_minor' => $amount,
            'price_list_id' => $syntheticId,
            'version' => 0,
            'group_id' => $groupId,
            'rule_stack' => $stack,
        ];
    }

    /**
     * @param array<string,mixed> $request
     */
    private function productTobAllowsTemplate(array $request): bool
    {
        if (array_key_exists('selling_mode_tob', $request)) {
            return $this->toBoolFlag($request['selling_mode_tob'], false);
        }
        $flags = $request['product_flags'] ?? null;
        if (!is_array($flags) || !array_key_exists(SellingModePolicy::PRODUCT_FLAG_TOB, $flags)) {
            // Callers must opt in; missing flags = no template (explicit lists only).
            return false;
        }

        return $this->toBoolFlag($flags[SellingModePolicy::PRODUCT_FLAG_TOB], false);
    }

    private function toBoolFlag(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function defaultPolicy(): ?DefaultWholesalePolicy
    {
        if ($this->defaultPolicy instanceof DefaultWholesalePolicy) {
            return $this->defaultPolicy;
        }
        try {
            return \Weline\Framework\Manager\ObjectManager::getInstance(DefaultWholesalePolicy::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function pricingGuard(): WholesalePricingGuard
    {
        return $this->pricingGuard ?? new WholesalePricingGuard();
    }

    private function selectList(string $groupId, int $websiteId, ?string $channelId, string $sku): ?PriceList
    {
        $candidates = $this->lists->activeForGroup($groupId, $websiteId, $channelId);
        $channelHit = null;
        $websiteHit = null;
        foreach ($candidates as $list) {
            if (!$list->hasSku($sku)) {
                continue;
            }
            if ($channelId !== null && $list->channelId === $channelId) {
                if ($channelHit === null || $list->version > $channelHit->version) {
                    $channelHit = $list;
                }
                continue;
            }
            if ($list->channelId === null) {
                if ($websiteHit === null || $list->version > $websiteHit->version) {
                    $websiteHit = $list;
                }
            }
        }

        return $channelHit ?? $websiteHit;
    }

    /**
     * @param list<string> $stack
     * @return array{ok:bool,source:string,amount_minor:int,price_list_id:?string,version:?int,group_id:?string,rule_stack:list<string>}
     */
    private function retailResult(int $retail, array $stack, string $source, bool $ok): array
    {
        return [
            'ok' => $ok,
            'source' => $source,
            'amount_minor' => $retail,
            'price_list_id' => null,
            'version' => null,
            'group_id' => null,
            'rule_stack' => $stack,
        ];
    }
}
