<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

/**
 * Canonical presentation model for the public system Scope selector.
 *
 * Identity-owner modules contribute raw catalogs through
 * ScopeIdentityCatalogInterface; consumers never assemble domain choices.
 */
interface ScopeSelectorCatalogInterface
{
    /**
     * @param null|list<array<string,mixed>> $catalogOptions
     * @param array<string,mixed> $claims
     * @return array<string,mixed>
     */
    public function build(string $selectedScope, ?array $catalogOptions = null, array $claims = []): array;
}
