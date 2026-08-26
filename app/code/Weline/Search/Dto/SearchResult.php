<?php

declare(strict_types=1);

namespace Weline\Search\Dto;

final class SearchResult
{
    /**
     * @param list<SearchHit> $hits
     * @param array<string, list<SearchHit>> $sections keyed by provider code (type=all)
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $type,
        public readonly array $hits,
        public readonly int $hitCount,
        public readonly array $sections = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly array $meta = [],
        public readonly float $elapsedMs = 0.0,
        public readonly string $engine = 'mysql',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $sections = [];
        foreach ($this->sections as $code => $hits) {
            $sections[$code] = \array_map(
                static fn(SearchHit $hit): array => $hit->toArray(),
                $hits,
            );
        }

        return [
            'ok' => $this->ok,
            'success' => $this->ok,
            'type' => $this->type,
            'hits' => \array_map(
                static fn(SearchHit $hit): array => $hit->toArray(),
                $this->hits,
            ),
            'hit_count' => $this->hitCount,
            'sections' => $sections,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'elapsed_ms' => $this->elapsedMs,
            'engine' => $this->engine,
        ] + $this->meta;
    }

    public static function fail(string $errorCode, string $message, array $meta = []): self
    {
        return new self(
            ok: false,
            type: (string)($meta['type'] ?? 'all'),
            hits: [],
            hitCount: 0,
            errorCode: $errorCode,
            message: $message,
            meta: $meta,
        );
    }
}
