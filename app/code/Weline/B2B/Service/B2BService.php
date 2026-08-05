<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\PriceList;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * B2B 门面：组/价目/候选（P4C-001）+ quote/recheck/snapshot（P4C-002）。
 */
final class B2BService
{
    public const CAPABILITY = B2BPriceEngine::CAPABILITY;

    public function __construct(
        private readonly B2BPriceEngine $engine,
        private readonly B2BRolloutGate $rollout,
        private readonly B2BCheckoutRecheckService $checkout,
    ) {
    }

    public static function forTesting(
        ?B2BRolloutGate $rollout = null,
        ?callable $clock = null,
        int $quoteTtlSeconds = B2BCheckoutRecheckService::DEFAULT_QUOTE_TTL_SECONDS,
    ): self
    {
        $engine = B2BPriceEngine::forTesting($rollout);
        $checkout = B2BCheckoutRecheckService::forTesting($engine, $clock, $quoteTtlSeconds);

        return new self($engine, $engine->rollout(), $checkout);
    }

    public function engine(): B2BPriceEngine
    {
        return $this->engine;
    }

    public function checkout(): B2BCheckoutRecheckService
    {
        return $this->checkout;
    }

    public function rollout(): B2BRolloutGate
    {
        return $this->rollout;
    }

    public function enableShadow(): void
    {
        $this->rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_SHADOW);
    }

    public function enableAllowlist(array $allowlist = ['website:0']): void
    {
        $this->rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_ALLOWLIST, $allowlist);
    }

    public function modeOff(): void
    {
        $this->rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);
    }

    public function seedGroup(string $groupId, int $websiteId, string $code, string $status = CustomerGroup::STATUS_ACTIVE): CustomerGroup
    {
        $group = new CustomerGroup($groupId, $websiteId, $code, $status);
        $this->engine->groups()->put($group);

        return $group;
    }

    public function assignCustomer(string $customerId, string $groupId): void
    {
        $this->engine->groups()->assignCustomer($customerId, $groupId);
    }

    /**
     * @param array<string, int> $skuAmounts
     */
    public function seedPriceList(
        string $listId,
        string $groupId,
        int $websiteId,
        int $version,
        array $skuAmounts,
        ?string $channelId = null,
        bool $active = true,
    ): PriceList {
        $group = $this->engine->groups()->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        if ($group->websiteId !== $websiteId) {
            throw new B2BConflictException(
                B2BPriceEngine::ERROR_GROUP_WEBSITE_MISMATCH,
                __('B2B price list 与 group Website 不一致'),
                ['group_id' => $groupId, 'website_id' => $websiteId],
            );
        }
        $list = new PriceList(
            $listId,
            $groupId,
            $websiteId,
            $version,
            $skuAmounts,
            $channelId,
            $active,
        );
        $this->engine->lists()->put($list);

        return $list;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function resolve(array $request): array
    {
        return $this->engine->resolve($request);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function issueQuote(array $request): array
    {
        return $this->checkout->issueQuote($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(
        string $tokenId,
        string $customerId,
        int $websiteId,
        string $orderRef,
    ): array
    {
        return $this->checkout->submit($tokenId, $customerId, $websiteId, $orderRef);
    }
}
