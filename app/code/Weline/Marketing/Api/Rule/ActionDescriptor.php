<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Rule;

/** Immutable rule-action metadata; no executable module object crosses the boundary. */
final readonly class ActionDescriptor
{
    /**
     * @param array<array-key, mixed> $formFields
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $description,
        public array $formFields = [],
    ) {
    }
}
