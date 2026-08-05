<?php

declare(strict_types=1);

namespace Weline\Subscription\Model;

/**
 * Subscription lifecycle states（P4B-001）.
 */
final class SubscriptionState
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PAUSED = 'paused';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_CANCELLED,
        self::STATUS_PAUSED,
    ];

    public const PERIOD_OPEN = 'open';
    public const PERIOD_BILLED = 'billed';
    public const PERIOD_MISSED = 'missed';
    public const PERIOD_CANCELLED = 'cancelled';

    public const PERIOD_STATUSES = [
        self::PERIOD_OPEN,
        self::PERIOD_BILLED,
        self::PERIOD_MISSED,
        self::PERIOD_CANCELLED,
    ];

    public const ENV_SANDBOX = 'sandbox';
    public const ENV_LIVE = 'live';

    private function __construct()
    {
    }

    public static function assertStatus(string $status): string
    {
        $status = trim($status);
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(__('非法 Subscription status：%{1}', [$status]));
        }

        return $status;
    }

    public static function assertWebsiteId(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负数：%{1}', [$websiteId]));
        }
    }

    public static function assertEnvironment(string $environment): string
    {
        $environment = trim($environment);
        if (!\in_array($environment, [self::ENV_SANDBOX, self::ENV_LIVE], true)) {
            throw new \InvalidArgumentException(__('非法 Subscription environment：%{1}', [$environment]));
        }

        return $environment;
    }

    public static function assertPeriodStatus(string $status): string
    {
        $status = trim($status);
        if (!\in_array($status, self::PERIOD_STATUSES, true)) {
            throw new \InvalidArgumentException(__('非法 SubscriptionPeriod status：%{1}', [$status]));
        }

        return $status;
    }
}
