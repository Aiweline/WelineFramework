<?php

declare(strict_types=1);

namespace Weline\Subscription\Api;

/**
 * Subscription billing Provider SPI（P4B-001）.
 *
 * Small interface only — no Order/Payment internals.
 */
interface SubscriptionProviderInterface
{
    /** Unique provider code (e.g. interval_monthly). */
    public function getCode(): string;

    public function getLabel(): string;

    public function isEnabled(): bool;

    /**
     * Interval descriptor used to compose period keys.
     *
     * @return array{unit:string,every:int}
     */
    public function getInterval(): array;

    /**
     * Build canonical period key for a subscription + period index.
     */
    public function periodKey(string $subscriptionId, int $periodIndex): string;

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array;
}
