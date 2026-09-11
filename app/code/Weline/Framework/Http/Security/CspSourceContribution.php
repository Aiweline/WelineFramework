<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

/**
 * Module-owned CSP source contribution (directive => sources).
 *
 * Providers return only scalar host/keyword sources; no Closure, services, or ORM.
 */
final class CspSourceContribution
{
    /**
     * @param array<string, list<string>> $directives
     */
    public function __construct(
        public readonly array $directives = [],
    ) {
    }
}
