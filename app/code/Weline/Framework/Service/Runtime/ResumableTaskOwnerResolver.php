<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * Derives a task access scope from the authenticated server session only.
 *
 * Browser parameters never supply area, user, session, website or ACL values.
 * Backend authentication wins when present because backend pages currently use
 * the same worker transport path as frontend pages.
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
