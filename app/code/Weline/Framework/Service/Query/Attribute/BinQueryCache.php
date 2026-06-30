<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class BinQueryCache
{
    /**
     * @param array<int, string> $keyParams
     * @param array<int, string> $vary
     * @param array<int, int> $status
     */
    public function __construct(
        public readonly string $ttl,
        public readonly string $description = '',
        public readonly string $visibility = 'public',
        public readonly array $keyParams = [],
        public readonly array $vary = ['area', 'locale', 'currency'],
        public readonly array $status = [200],
        public readonly string $trigger = 'cron',
        public readonly bool $cdn = true
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDescriptor(): array
    {
        return [
            'cdn' => $this->cdn,
            'ttl' => $this->ttl,
            'visibility' => $this->visibility,
            'key_params' => $this->keyParams,
            'vary' => $this->vary,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'description' => $this->description,
        ];
    }
}
