<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/** Frozen catalog identity for an order line. */
final class CatalogSnapshot
{
    /**
     * @param list<array<string, mixed>> $lines
     */
    public function __construct(
        public readonly array $lines = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['lines' => $this->lines];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $lines = $data['lines'] ?? [];
        return new self(is_array($lines) ? array_values($lines) : []);
    }
}
