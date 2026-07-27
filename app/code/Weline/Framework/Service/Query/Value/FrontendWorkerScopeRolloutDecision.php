<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Value;

/**
 * Immutable rollout result for one exact storefront Scope tuple.
 */
final readonly class FrontendWorkerScopeRolloutDecision
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

    public function __construct(
        public string $mode,
        public bool $tokenEnabled,
        public bool $authoritative,
        public ?int $websiteId,
        public ?int $storeId,
        public ?int $channelId,
        public int $shadowSampleBasisPoints,
        public string $reason,
    ) {
        if (!\in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Worker Scope rollout mode is invalid.');
        }
        if ($shadowSampleBasisPoints < 0 || $shadowSampleBasisPoints > 10000) {
            throw new \InvalidArgumentException('Worker Scope shadow sample basis points are invalid.');
        }
        if (\preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('Worker Scope rollout reason is invalid.');
        }
        if (($websiteId === null) !== ($storeId === null)
            || ($storeId === null) !== ($channelId === null)) {
            throw new \InvalidArgumentException('Worker Scope rollout tuple must be complete or absent.');
        }
        if ($websiteId !== null && ($websiteId < 0 || $storeId < 1 || $channelId < 1)) {
            throw new \InvalidArgumentException('Worker Scope rollout tuple is invalid.');
        }
        if ($authoritative && !$tokenEnabled) {
            throw new \InvalidArgumentException('Authoritative Worker Scope rollout must enable the token path.');
        }
        if ($authoritative && !\in_array($mode, [self::MODE_ALLOWLIST, self::MODE_ON], true)) {
            throw new \InvalidArgumentException('Only allowlist/on rollout may be authoritative.');
        }
        if ($mode === self::MODE_OFF && ($tokenEnabled || $authoritative)) {
            throw new \InvalidArgumentException('Off Worker Scope rollout cannot enable token authority.');
        }
        if ($mode === self::MODE_SHADOW && $authoritative) {
            throw new \InvalidArgumentException('Shadow Worker Scope rollout cannot be authoritative.');
        }
    }

    public function isOff(): bool
    {
        return $this->mode === self::MODE_OFF;
    }

    public function isShadow(): bool
    {
        return $this->mode === self::MODE_SHADOW;
    }

    public function isAuthoritative(): bool
    {
        return $this->authoritative;
    }

    /**
     * @return array{
     *     mode:string,
     *     token_enabled:bool,
     *     authoritative:bool,
     *     website_id:?int,
     *     store_id:?int,
     *     channel_id:?int,
     *     shadow_sample_bp:int,
     *     reason:string
     * }
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'token_enabled' => $this->tokenEnabled,
            'authoritative' => $this->authoritative,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'channel_id' => $this->channelId,
            'shadow_sample_bp' => $this->shadowSampleBasisPoints,
            'reason' => $this->reason,
        ];
    }
}
