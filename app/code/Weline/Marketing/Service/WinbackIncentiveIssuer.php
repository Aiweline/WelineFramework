<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface;

/**
 * step≥2 且配置了 incentive_rule_id 时发随机券；可注入便于 UT。
 */
final class WinbackIncentiveIssuer
{
    /** @var callable(int,array):array{coupon_code:string,coupon_id:int} */
    private $issue;

    /**
     * @param callable(int,array):array{coupon_code:string,coupon_id:int}|null $issue
     */
    public function __construct(?callable $issue = null)
    {
        $this->issue = $issue ?? [$this, 'defaultIssue'];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{coupon_code:string,coupon_id:int}
     */
    public function issue(int $ruleId, array $context = []): array
    {
        if ($ruleId <= 0) {
            return ['coupon_code' => '', 'coupon_id' => 0];
        }

        try {
            $result = ($this->issue)($ruleId, $context);
        } catch (\Throwable) {
            return ['coupon_code' => '', 'coupon_id' => 0];
        }

        $code = \trim((string)($result['coupon_code'] ?? ''));

        return [
            'coupon_code' => $code,
            'coupon_id' => (int)($result['coupon_id'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{coupon_code:string,coupon_id:int}
     */
    public function maybeIssue(int $step, int $incentiveRuleId, array $context = []): array
    {
        if ($step < 2 || $incentiveRuleId <= 0) {
            return ['coupon_code' => '', 'coupon_id' => 0];
        }

        return $this->issue($incentiveRuleId, $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{coupon_code:string,coupon_id:int}
     */
    private function defaultIssue(int $ruleId, array $context): array
    {
        /** @var RandomCouponCampaignProviderInterface $provider */
        $provider = ObjectManager::getInstance(RandomCouponCampaignProviderInterface::class);

        return $provider->issueRandomCoupon($ruleId, $context);
    }
}
