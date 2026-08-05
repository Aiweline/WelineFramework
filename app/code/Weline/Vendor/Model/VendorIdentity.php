<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

/**
 * Vendor identity constants and validators（P4A-001）.
 *
 * legal/account/commission freeze lives here as ENV + STATUS only;
 * payout fields arrive in P4A-002.
 */
final class VendorIdentity
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_LIVE = 'live';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const ENVIRONMENTS = [self::ENV_SANDBOX, self::ENV_LIVE];
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DISABLED];

    private function __construct()
    {
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
        if (!\in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException(__('非法 Vendor environment：%{1}', [$environment]));
        }

        return $environment;
    }

    public static function assertStatus(string $status): string
    {
        $status = trim($status);
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(__('非法 Vendor status：%{1}', [$status]));
        }

        return $status;
    }

    public static function assertVendorCode(string $code): string
    {
        $code = trim($code);
        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code)) {
            throw new \InvalidArgumentException(__('非法 Vendor code：%{1}', [$code]));
        }

        return $code;
    }
}
