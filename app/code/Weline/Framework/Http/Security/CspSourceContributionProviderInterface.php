<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

/**
 * Module-owned CSP source contribution provider.
 *
 * Register only via Extends (do not use etc/module.php provides):
 * `extends/module/Weline_Framework/Security/Csp/{Name}.php`
 *
 * Implementations must have a zero-argument constructor and return only
 * immutable directive/source lists (no request-path I/O).
 *
 * Aggregated contributions become application defaults: always allowed and
 * not removable by SystemConfig Scope overrides.
 */
interface CspSourceContributionProviderInterface
{
    public const EXTENDS_RELATIVE_PREFIX = 'extends/module/weline_framework/security/csp/';

    public function contribution(): CspSourceContribution;
}
