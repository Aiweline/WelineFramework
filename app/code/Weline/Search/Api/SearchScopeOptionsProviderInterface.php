<?php

declare(strict_types=1);

namespace Weline\Search\Api;

/**
 * Optional scoped filters under a search type (e.g. product categories).
 *
 * Nodes may nest via `children` (storefront cascade menus, typically ≤3 levels).
 */
interface SearchScopeOptionsProviderInterface
{
    /**
     * @return list<array{
     *   code:string,
     *   label:string,
     *   params:array<string,int|string|float|bool>,
     *   children?:list<array<string,mixed>>
     * }>
     */
    public function listScopeOptions(): array;
}
