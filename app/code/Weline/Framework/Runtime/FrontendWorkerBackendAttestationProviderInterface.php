<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;

/**
 * Optional Backend-owned bridge for trusted backend Worker page attestation.
 *
 * Framework owns the transport and never imports Backend models. The provider
 * must derive the binding exclusively from the current server-side backend
 * Session and current user facts; client-supplied area or principal data is
 * never authoritative.
 */
interface FrontendWorkerBackendAttestationProviderInterface
{
    public function issueBinding(
        string $authorityHost,
        ?int $now = null,
    ): ?FrontendWorkerBackendBinding;

    /**
     * Revalidates authority, current Session identity and current user state.
     * When the current backend Session is still valid, near-expiry or expired
     * page attestations are slid to a fresh binding window so long-running
     * runtime SSE ticket refreshes do not force a full page reload.
     * Implementations throw FrontendWorkerBackendAttestationException on any
     * mismatch and must never silently downgrade to frontend authority.
     */
    public function restoreBinding(
        FrontendWorkerBackendBinding $binding,
        string $authorityHost,
        ?int $now = null,
    ): FrontendWorkerBackendBinding;
}
