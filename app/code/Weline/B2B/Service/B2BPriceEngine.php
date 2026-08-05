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
    ) {
    }

    public static function forTesting(?B2BRolloutGate $rollout = null): self
    {
        $gate = $rollout ?? B2BRolloutGate::forTestingConfiguration();
        $gate->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);

        return new self(
            CustomerGroupStore::forTesting(),
            PriceListStore::forTesting(),
            $gate,
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
            return $this->retailResult($retail, ['no_b2b_list', 'retail'], self::SOURCE_RETAIL, true);
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

        $amount = $selected->amountForSku($sku);
        if ($amount === null) {
            return $this->retailResult($retail, ['sku_missing_on_list', 'retail'], self::SOURCE_RETAIL, true);
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

    private function selectList(string $groupId, int $websiteId, ?string $channelId, string $sku): ?PriceList
    {
        $candidates = $this->lists->activeForGroup($groupId, $websiteId, $channelId);
        $channelHit = null;
        $websiteHit = null;
        foreach ($candidates as $list) {
            if ($list->amountForSku($sku) === null) {
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
