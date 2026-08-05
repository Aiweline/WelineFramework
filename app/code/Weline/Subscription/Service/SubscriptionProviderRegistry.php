<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Subscription\Api\SubscriptionProviderInterface;

/**
 * Subscription Provider registry（P4B-001）.
 */
final class SubscriptionProviderRegistry
{
    /** @var array<string, SubscriptionProviderInterface> */
    private array $providers = [];

    public function __construct(bool $registerBuiltin = true)
    {
        if ($registerBuiltin) {
            $this->register(IntervalSubscriptionProvider::monthly());
        }
    }

    public static function forTesting(): self
    {
        $reg = new self(registerBuiltin: false);
        $reg->register(IntervalSubscriptionProvider::monthly());

        return $reg;
    }

    public function register(SubscriptionProviderInterface $provider): void
    {
        $code = trim($provider->getCode());
        if ($code === '') {
            throw new \InvalidArgumentException('subscription_provider_code_required');
        }
        if (isset($this->providers[$code])) {
            throw new SubscriptionConflictException(
                'subscription_provider_exists',
                __('Subscription Provider 已存在：%{1}', [$code]),
                ['code' => $code],
            );
        }
        $this->providers[$code] = $provider;
    }

    public function get(string $code): SubscriptionProviderInterface
    {
        $code = trim($code);
        if (!isset($this->providers[$code])) {
            throw new SubscriptionConflictException(
                'subscription_provider_not_found',
                __('Subscription Provider 不存在：%{1}', [$code]),
                ['code' => $code],
            );
        }
        $provider = $this->providers[$code];
        if (!$provider->isEnabled()) {
            throw new SubscriptionConflictException(
                'subscription_provider_disabled',
                __('Subscription Provider 已禁用：%{1}', [$code]),
                ['code' => $code],
            );
        }

        return $provider;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->providers);
    }
}
