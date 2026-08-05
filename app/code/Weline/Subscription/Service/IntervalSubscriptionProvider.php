<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Subscription\Api\SubscriptionProviderInterface;

/**
 * Built-in interval Subscription Provider（P4B-001）.
 */
final class IntervalSubscriptionProvider implements SubscriptionProviderInterface
{
    public function __construct(
        private readonly string $code = 'interval_monthly',
        private readonly string $unit = 'month',
        private readonly int $every = 1,
        private readonly string $label = 'Monthly interval',
        private readonly bool $enabled = true,
    ) {
        if ($every < 1) {
            throw new \InvalidArgumentException('subscription_interval_every_invalid');
        }
        if (!\in_array($unit, ['day', 'week', 'month', 'year'], true)) {
            throw new \InvalidArgumentException('subscription_interval_unit_invalid');
        }
    }

    public static function monthly(): self
    {
        return new self();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getInterval(): array
    {
        return ['unit' => $this->unit, 'every' => $this->every];
    }

    public function periodKey(string $subscriptionId, int $periodIndex): string
    {
        if ($periodIndex < 1) {
            throw new \InvalidArgumentException('subscription_period_index_invalid');
        }

        return trim($subscriptionId) . '|p' . $periodIndex . '|' . $this->unit . $this->every;
    }

    public function getMetadata(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'interval' => $this->getInterval(),
        ];
    }
}
