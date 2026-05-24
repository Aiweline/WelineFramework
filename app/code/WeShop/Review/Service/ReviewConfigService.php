<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

class ReviewConfigService
{
    public const MODE_ANONYMOUS = 'anonymous';
    public const MODE_ORDER = 'order';

    private const MODULE = 'WeShop_Review';
    private const CONFIG_KEY_REVIEW_MODE = 'review_mode';

    public function __construct(
        private readonly ?SystemConfig $systemConfig = null
    ) {
    }

    public function getReviewMode(): string
    {
        $mode = (string) ($this->getSystemConfig()->getConfig(
            self::CONFIG_KEY_REVIEW_MODE,
            self::MODULE,
            SystemConfig::area_FRONTEND
        ) ?? '');

        return $this->normalizeReviewMode($mode);
    }

    public function saveReviewMode(string $mode): void
    {
        $this->getSystemConfig()->setConfig(
            self::CONFIG_KEY_REVIEW_MODE,
            $this->normalizeReviewMode($mode),
            self::MODULE,
            SystemConfig::area_FRONTEND
        );
    }

    /**
     * @return array<string, string>
     */
    public function getReviewModeOptions(): array
    {
        return [
            self::MODE_ORDER => (string) __('下单后评论'),
            self::MODE_ANONYMOUS => (string) __('匿名评论'),
        ];
    }

    public function getReviewModeLabel(?string $mode = null): string
    {
        $mode = $this->normalizeReviewMode($mode ?? $this->getReviewMode());
        $options = $this->getReviewModeOptions();

        return $options[$mode] ?? $options[self::MODE_ORDER];
    }

    public function normalizeReviewMode(string $mode): string
    {
        return $mode === self::MODE_ANONYMOUS ? self::MODE_ANONYMOUS : self::MODE_ORDER;
    }

    public function isAnonymousMode(): bool
    {
        return $this->getReviewMode() === self::MODE_ANONYMOUS;
    }

    private function getSystemConfig(): SystemConfig
    {
        return $this->systemConfig ?? ObjectManager::getInstance(SystemConfig::class);
    }
}
