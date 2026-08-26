<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class Attack
{
    /**
     * @param array<string, scalar|null> $options
     */
    public function __construct(
        public readonly string $rateLimit = '',
        public readonly string $challenge = 'managed',
        public readonly int $burst = 0,
        public readonly string $description = '',
        public readonly bool $enabled = true,
        public readonly array $options = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toRuleSegment(): array
    {
        return array_filter([
            'rate_limit' => $this->rateLimit,
            'challenge' => $this->challenge,
            'burst' => $this->burst > 0 ? $this->burst : null,
            'description' => $this->description,
            'enabled' => $this->enabled,
        ] + $this->options, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
