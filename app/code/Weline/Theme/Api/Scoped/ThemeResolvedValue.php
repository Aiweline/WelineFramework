<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

/** Provenance-aware resolved value; explicit null remains distinguishable. */
final readonly class ThemeResolvedValue
{
    /** @param list<array<string,mixed>> $conflicts */
    public function __construct(
        public mixed $effectiveValue,
        public mixed $localValue,
        public bool $hasLocalValue,
        public string $sourceScope,
        public ?int $sourceReleaseId,
        public bool $isOwned,
        public bool $canRestoreInheritance,
        public array $conflicts = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'effective_value' => $this->effectiveValue,
            'local_value' => $this->localValue,
            'has_local_value' => $this->hasLocalValue,
            'source_scope' => $this->sourceScope,
            'source_release_id' => $this->sourceReleaseId,
            'is_owned' => $this->isOwned,
            'can_restore_inheritance' => $this->canRestoreInheritance,
            'conflicts' => $this->conflicts,
        ];
    }
}
