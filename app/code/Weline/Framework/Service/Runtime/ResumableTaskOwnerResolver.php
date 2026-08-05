<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * Derives a task access scope from trusted request authority and its matching
 * authenticated server session.
 *
 * Browser parameters never supply area, user, session, website or ACL values.
 * Worker calls use the server-constructed execution context, so a backend PHP
 * Session cookie carried by a storefront request can never upgrade its owner.
 * Legacy non-Worker callers retain the historical backend-first resolution.
 */
class ResumableTaskOwnerResolver
{
    public function __construct(
        private readonly SessionFactory $sessionFactory,
        private readonly Request $request,
    ) {
    }

    /**
     * @throws ResumableTaskAccessDeniedException when a stable anonymous
     *         session cannot be created.
     */
    public function resolve(): TaskOwner
    {
        $websiteId = $this->resolveWebsiteId();

        if (RequestContext::has(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY)) {
            $executionContext = RequestContext::get(
                FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
            );
            if (!$executionContext instanceof FrontendWorkerExecutionContext) {
                throw new ResumableTaskAccessDeniedException(
                    'Runtime task Worker authority is invalid.',
                );
            }

            return $executionContext->area === FrontendWorkerExecutionContext::AREA_BACKEND
                ? $this->resolveBackendOwner($executionContext, $websiteId)
                : $this->resolveFrontendOwner($websiteId);
        }

        $backend = $this->sessionFactory->createBackendSession();
        $backendUserId = $this->authenticatedUserId($backend);
        if ($backendUserId !== null) {
            return new TaskOwner(
                area: 'backend',
                principal: 'backend:' . $backendUserId,
                sessionId: $this->sessionId($backend),
                websiteId: $websiteId,
                acl: $this->backendAcl($backend),
            );
        }

        return $this->resolveFrontendOwner($websiteId);
    }

    private function resolveBackendOwner(
        FrontendWorkerExecutionContext $executionContext,
        ?int $websiteId,
    ): TaskOwner
    {
        $binding = $executionContext->backendBinding;
        if ($binding === null || $binding->expiresAt <= \time()) {
            throw new ResumableTaskAccessDeniedException(
                'Runtime task backend authority is unavailable.',
            );
        }

        $backend = $this->sessionFactory->createBackendSession();
        $backendUserId = $this->authenticatedUserId($backend);
        $sessionId = $this->sessionId($backend);
        if ($backendUserId === null
            || !\hash_equals((string)$binding->backendUserId, $backendUserId)
            || $sessionId === ''
            || !\hash_equals($binding->sessionFingerprint, \hash('sha256', $sessionId))) {
            throw new ResumableTaskAccessDeniedException(
                'Runtime task backend authority no longer matches the Session.',
            );
        }

        return new TaskOwner(
            area: 'backend',
            principal: 'backend:' . $backendUserId,
            sessionId: $sessionId,
            websiteId: $websiteId,
            acl: $this->backendAcl($backend),
        );
    }

    private function resolveFrontendOwner(?int $websiteId): TaskOwner
    {
        $frontend = $this->sessionFactory->createFrontendSession();
        $frontendUserId = $this->authenticatedUserId($frontend);
        if ($frontendUserId !== null) {
            return new TaskOwner(
                area: 'frontend',
                principal: 'frontend:' . $frontendUserId,
                sessionId: $this->sessionId($frontend),
                websiteId: $websiteId,
            );
        }

        $frontend->start();
        $sessionId = $this->sessionId($frontend);
        if ($sessionId === '') {
            throw new ResumableTaskAccessDeniedException('Runtime task owner session is unavailable.');
        }

        return new TaskOwner(
            area: 'frontend',
            principal: 'session:' . $sessionId,
            sessionId: $sessionId,
            websiteId: $websiteId,
        );
    }

    private function authenticatedUserId(AuthenticatedSessionInterface $session): ?string
    {
        if (!$session->isLoggedIn()) {
            return null;
        }

        $userId = \trim((string)($session->getUserId() ?? ''));
        return $userId === '' ? null : $userId;
    }

    private function sessionId(AuthenticatedSessionInterface $session): string
    {
        if (!$session->isStarted()) {
            $session->start();
        }

        return \trim($session->getId());
    }

    /**
     * A role marker is captured from the server session, never from request
     * data.  A changed role produces a distinct TaskOwner and is therefore
     * denied by the runtime's owner comparison.
     *
     * @return list<string>
     */
    private function backendAcl(AuthenticatedSessionInterface $session): array
    {
        $roleId = (int)$session->getSession()->get('backend_acl_role_id');
        return $roleId > 0 ? ['backend_role:' . $roleId] : [];
    }

    /**
     * `0` is a valid system-default website.  `null` means the request has no
     * server-derived website scope and must not be replaced with a client value.
     */
    private function resolveWebsiteId(): ?int
    {
        $value = $this->request->getServer('WELINE_WEBSITE_ID');
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $normalized = \trim((string)$value);
        if ($normalized === '' || \preg_match('/^(?:0|[1-9][0-9]*)$/', $normalized) !== 1) {
            return null;
        }

        if (\strlen($normalized) > \strlen((string)PHP_INT_MAX)
            || (\strlen($normalized) === \strlen((string)PHP_INT_MAX)
                && \strcmp($normalized, (string)PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int)$normalized;
    }
}
