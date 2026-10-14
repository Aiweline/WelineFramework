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
     * @param array<int, string> $tags Cache-Tag / Surrogate-Key tokens for local+CDN purge
     */
    public function __construct(
        public readonly string $ttl,
        public readonly string $description = '',
        public readonly string $visibility = 'public',
        public readonly array $keyParams = [],
        public readonly array $vary = ['area', 'locale', 'currency'],
        public readonly array $status = [200],
        public readonly string $trigger = 'cron',
        public readonly bool $cdn = true,
        public readonly array $tags = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDescriptor(): array
    {
        $out = [
            'cdn' => $this->cdn,
            'ttl' => $this->ttl,
            'visibility' => $this->visibility,
            'key_params' => $this->keyParams,
            'vary' => $this->vary,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'description' => $this->description,
        ];
        if ($this->tags !== []) {
            $out['tags'] = \array_values(\array_unique(\array_filter(\array_map(
                static fn(mixed $t): string => \trim((string)$t),
                $this->tags,
            ))));
        }

        return $out;
    }
}
