<?php

declare(strict_types=1);

namespace Weline\Framework\View\Cache;

interface TemplateCachePolicyProviderInterface
{
    /**
     * Providers must return immutable scalar/array descriptors only.
     *
     * @return array{
     *     request_hooks?: list<string>,
     *     diagnostic_hooks?: list<string>,
     *     aggregate_hooks?: array<string, array<string, mixed>>,
     *     output_files?: array<string, array<string, mixed>>
     * }
     */
    public function policies(): array;
}
