<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeRolloutDecision;

/**
 * Optional owning-module bridge for Scope Token issuance and Worker recovery.
 *
 * Framework owns Worker sessions and transport. The provider owns storefront
 * catalog revalidation and is the only component allowed to restore a bound
 * Website/Store/Channel Scope into the current request.
 */
interface FrontendWorkerScopeProviderInterface
{
    /**
     * Whether a non-backend operation must carry a bound Worker Scope.
     * Callers must evaluate operation descriptors first: explicit backend-only
     * calls use backend Session authorization and do not consult this gate.
     */
    public function requiresBinding(string $requestScheme): bool;

    public function rollout(
        ScopeIdentity $scope,
        string $requestScheme,
    ): FrontendWorkerScopeRolloutDecision;

    /**
     * Returns null for off/shadow. Allowlist issues a non-authoritative proof
     * even for a tuple outside the list so omitting the bootstrap cannot
     * downgrade an allowlisted request path.
     * The returned token is sensitive transport material and must not be logged
     * or exposed to page JavaScript.
     */
    public function issueToken(
        ScopeIdentity $trustedScope,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?string;

    /**
     * Returns null when the rollout does not bind this Scope (off/shadow).
     * Invalid, expired, or conflicting tokens throw FrontendWorkerScopeException.
     */
    public function verifyToken(
        string $token,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?FrontendWorkerScopeBinding;

    /**
     * Revalidates the persisted binding against current catalog facts and
     * installs the Scope only for an authoritative allowlist/on decision.
     * A null binding is accepted in off/shadow and rejected in allowlist/on;
     * non-allowlisted bound decisions return null without installing it.
     */
    public function restoreBinding(
        ?FrontendWorkerScopeBinding $binding,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?ScopeIdentity;
}
