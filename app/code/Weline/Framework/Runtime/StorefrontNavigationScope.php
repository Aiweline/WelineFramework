<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/** Immutable result of one storefront Scope + route-prefix resolution. */
final readonly class StorefrontNavigationScope
{
    public function __construct(
        public ScopeIdentity $identity,
        public string $routePath,
    ) {
        if ($identity->scopeKind !== ScopeIdentity::KIND_CHANNEL) {
            throw new \InvalidArgumentException('Storefront navigation requires a channel ScopeIdentity.');
        }
        if ($routePath === ''
            || !\str_starts_with($routePath, '/')
            || \str_contains($routePath, '?')
            || \str_contains($routePath, '#')
            || \str_contains($routePath, '//')
            || \preg_match('/[\x00-\x20\x7F\\\\]/', $routePath) === 1) {
            throw new \InvalidArgumentException('Storefront route path is not canonical.');
        }
    }
}
