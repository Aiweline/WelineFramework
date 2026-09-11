<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\MembershipApplicationRecord;

/**
 * Storefront CTA projection: application audit vs entitlement gate.
 */
final class MembershipStatusProjection
{
    public const UI_NEED_LOGIN = 'need_login';
    public const UI_CAN_APPLY = 'can_apply';
    public const UI_PENDING = 'pending';
    public const UI_REJECTED = 'rejected';
    public const UI_ACTIVE = 'active';
    public const UI_INACTIVE = 'inactive';

    public const POLL_INTERVAL_MS = 300000;
    public const FIRST_PROBE_MS = 45000;

    public function __construct(
        private readonly CustomerGroupStore $groups,
        private readonly MembershipApplicationService $applications,
    ) {
    }

    public static function forTesting(
        ?CustomerGroupStore $groups = null,
        ?MembershipApplicationService $applications = null,
    ): self {
        $groups ??= CustomerGroupStore::forTesting();
        $applications ??= MembershipApplicationService::forTesting($groups);
        return new self($groups, $applications);
    }

    /**
     * @return array{
     *   ui_state:string,
     *   application_status:?string,
     *   application_id:?string,
     *   has_membership:bool,
     *   membership_active:bool,
     *   group_id:?string,
     *   can_submit:bool,
     *   should_poll:bool,
     *   poll_interval_ms:int,
     *   next_probe_hint_ms:int,
     *   server_time:string
     * }
     */
    public function snapshot(string $customerId, int $websiteId): array
    {
        $customerId = trim($customerId);
        $serverTime = gmdate('c');
        $base = [
            'application_status' => null,
            'application_id' => null,
            'has_membership' => false,
            'membership_active' => false,
            'group_id' => null,
            'can_submit' => false,
            'should_poll' => false,
            'poll_interval_ms' => self::POLL_INTERVAL_MS,
            'next_probe_hint_ms' => 0,
            'server_time' => $serverTime,
        ];

        if ($customerId === '' || $customerId === '0' || $websiteId < 0) {
            return array_merge($base, ['ui_state' => self::UI_NEED_LOGIN]);
        }

        $group = $this->groups->groupForCustomer($customerId, $websiteId);
        $hasRow = $group instanceof CustomerGroup;
        $membershipActive = $hasRow && $group->isActive();
        $application = $this->applications->latestForCustomer($customerId, $websiteId);
        $appStatus = is_array($application) ? (string)($application['status'] ?? '') : '';
        $appId = is_array($application) ? (string)($application['application_id'] ?? '') : '';

        $base['application_status'] = $appStatus !== '' ? $appStatus : null;
        $base['application_id'] = $appId !== '' ? $appId : null;
        $base['has_membership'] = $hasRow;
        $base['membership_active'] = $membershipActive;
        $base['group_id'] = $hasRow ? $group->groupId : null;

        // Hard rule: active entitlement always wins (covers approve half-success).
        if ($membershipActive) {
            return array_merge($base, [
                'ui_state' => self::UI_ACTIVE,
                'can_submit' => false,
                'should_poll' => false,
                'next_probe_hint_ms' => 0,
            ]);
        }

        if ($appStatus === MembershipApplicationRecord::STATUS_PENDING) {
            return array_merge($base, [
                'ui_state' => self::UI_PENDING,
                'can_submit' => false,
                'should_poll' => true,
                'next_probe_hint_ms' => self::FIRST_PROBE_MS,
            ]);
        }

        if ($hasRow && !$membershipActive) {
            return array_merge($base, [
                'ui_state' => self::UI_INACTIVE,
                'can_submit' => true,
                'should_poll' => false,
                'next_probe_hint_ms' => 0,
            ]);
        }

        if ($appStatus === MembershipApplicationRecord::STATUS_REJECTED) {
            return array_merge($base, [
                'ui_state' => self::UI_REJECTED,
                'can_submit' => true,
                'should_poll' => false,
                'next_probe_hint_ms' => 0,
            ]);
        }

        // Approved audit after revoke: same row must be edited+resubmitted, not a second application.
        if ($appStatus === MembershipApplicationRecord::STATUS_APPROVED) {
            return array_merge($base, [
                'ui_state' => self::UI_INACTIVE,
                'can_submit' => true,
                'should_poll' => false,
                'next_probe_hint_ms' => 0,
            ]);
        }

        return array_merge($base, [
            'ui_state' => self::UI_CAN_APPLY,
            'can_submit' => true,
            'should_poll' => false,
            'next_probe_hint_ms' => 0,
        ]);
    }

    public function statusLabel(array $snapshot): string
    {
        return match ((string)($snapshot['ui_state'] ?? '')) {
            self::UI_ACTIVE => (string)__('已开通'),
            self::UI_PENDING => (string)__('审核中'),
            self::UI_REJECTED => (string)__('已驳回'),
            self::UI_INACTIVE => (string)__('资格不可用'),
            self::UI_NEED_LOGIN => (string)__('未登录'),
            default => (string)__('未申请'),
        };
    }
}
