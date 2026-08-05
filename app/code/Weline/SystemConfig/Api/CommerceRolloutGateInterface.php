<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api;

/**
 * 商城能力统一 rollout gate（DEC-025）。
 *
 * 仅允许 off|shadow|allowlist|on；默认 off；生产 on 必须显式授权令牌。
 */
interface CommerceRolloutGateInterface
{
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_ALLOWLIST = 'allowlist';
    public const MODE_ON = 'on';

    public const MODES = [
        self::MODE_OFF,
        self::MODE_SHADOW,
        self::MODE_ALLOWLIST,
        self::MODE_ON,
    ];

    public function mode(string $capability): string;

    /**
     * @param list<string> $allowlistSubjects
     */
    public function setMode(
        string $capability,
        string $mode,
        array $allowlistSubjects = [],
        string $productionOnToken = '',
    ): void;

    public function isShadow(string $capability): bool;

    public function isEffectivelyOn(string $capability, string $subject = ''): bool;

    /** 未知 mode 或未授权 on → 拒绝写路径 */
    public function assertMutable(string $capability, string $subject = ''): void;
}
