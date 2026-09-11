<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

/**
 * B2B 客户组（P4C-001）。website_id=0 为系统默认站，合法。
 */
final class CustomerGroup
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    /** @deprecated use SystemVipLadder::LEGACY_* / vip0 ladder */
    public const SYSTEM_VIP_GROUP_ID = SystemVipLadder::LEGACY_GROUP_ID;
    /** @deprecated */
    public const SYSTEM_VIP_CODE = SystemVipLadder::LEGACY_CODE;
    /** @deprecated */
    public const SYSTEM_VIP_DEFAULT_NAME = 'VIP';

    public function __construct(
        public readonly string $groupId,
        public readonly int $websiteId,
        public readonly string $code,
        public readonly string $status = self::STATUS_ACTIVE,
    ) {
        if ($groupId === '' || strlen($groupId) > 64) {
            throw new \InvalidArgumentException(__('B2B group_id 必填且不能超过 64 字符'));
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('B2B website_id 不能为负数：%{1}', [$websiteId]));
        }
        if ($code === '' || strlen($code) > 64) {
            throw new \InvalidArgumentException(__('B2B group code 必填且不能超过 64 字符'));
        }
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException(__('B2B group status 非法：%{1}', [$status]));
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSystemVip(): bool
    {
        return SystemVipLadder::isSystemVipGroupId($this->groupId)
            || SystemVipLadder::isSystemVipCode($this->code);
    }

    /**
     * @return array{group_id:string,website_id:int,code:string,status:string}
     */
    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'website_id' => $this->websiteId,
            'code' => $this->code,
            'status' => $this->status,
        ];
    }
}
