<?php

declare(strict_types=1);

namespace Weline\Backend\Integration\Framework;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationException;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationProviderInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;
use Weline\Framework\Session\SessionFactory;

/**
 * Backend-owned authority for backend Worker page attestations.
 */
final class FrontendWorkerBackendAttestationProvider implements FrontendWorkerBackendAttestationProviderInterface
{
    // Must stay aligned with FrontendWorkerSessionService::SESSION_TTL.
    // AI workbench realtime actions routinely exceed the old 10-minute window;
    // expired bindings make stream-ticket refresh fail and the UI spin on
    // SSE_TRANSPORT_REOPENING while the detached runner keeps working.
    private const BINDING_TTL = 7200;
    private const SLIDE_WHEN_REMAINING_SECONDS = 900;

    public function issueBinding(
        string $authorityHost,
        ?int $now = null,
    ): ?FrontendWorkerBackendBinding {
        $now ??= \time();
        $identity = $this->currentIdentity();
        if ($identity === null) {
            return null;
        }

        return new FrontendWorkerBackendBinding(
            $identity['user_id'],
            $identity['session_fingerprint'],
            FrontendWorkerBackendBinding::canonicalAuthorityHost($authorityHost),
            $now,
            $now + self::BINDING_TTL,
        );
    }

    public function restoreBinding(
        FrontendWorkerBackendBinding $binding,
        string $authorityHost,
        ?int $now = null,
    ): FrontendWorkerBackendBinding {
        $now ??= \time();
        try {
            $canonicalHost = FrontendWorkerBackendBinding::canonicalAuthorityHost($authorityHost);
        } catch (\Throwable $exception) {
            throw $this->invalid($exception);
        }
        if ($binding->issuedAt > $now
            || !\hash_equals($binding->authorityHost, $canonicalHost)) {
            throw $this->invalid();
        }

        $identity = $this->currentIdentity();
        if ($identity === null
            || $identity['user_id'] !== $binding->backendUserId
            || !\hash_equals($identity['session_fingerprint'], $binding->sessionFingerprint)) {
            throw $this->invalid();
        }

        // Keep the same worker_session_token usable across runtime_rotate by
        // sliding the page attestation while the PHP backend Session is alive.
        if ($binding->expiresAt <= $now
            || ($binding->expiresAt - $now) <= self::SLIDE_WHEN_REMAINING_SECONDS) {
            return new FrontendWorkerBackendBinding(
                $identity['user_id'],
                $identity['session_fingerprint'],
                $canonicalHost,
                $now,
                $now + self::BINDING_TTL,
            );
        }

        return $binding;
    }

    /** @return array{user_id:int,session_fingerprint:string}|null */
    private function currentIdentity(): ?array
    {
        $session = SessionFactory::getInstance()->createBackendSession();
        if (!$session->isLoggedIn()) {
            return null;
        }

        $rawUserId = $session->getUserId();
        if ((!\is_int($rawUserId) && !\is_string($rawUserId))
            || \preg_match('/^[1-9][0-9]*$/D', (string)$rawUserId) !== 1) {
            return null;
        }
        $userId = (int)$rawUserId;
        if ($userId <= 0) {
            return null;
        }

        $sessionId = $session->getId();
        if ($sessionId === '' || \strlen($sessionId) > 4096) {
            return null;
        }

        /** @var BackendUser $user */
        $user = ObjectManager::make(BackendUser::class);
        $user->clear()->load($userId);
        if ((int)$user->getId() !== $userId
            || $user->getIsDeleted()
            || !$user->getIsEnabled()) {
            return null;
        }

        return [
            'user_id' => $userId,
            'session_fingerprint' => \hash('sha256', $sessionId),
        ];
    }

    private function invalid(?\Throwable $previous = null): FrontendWorkerBackendAttestationException
    {
        return new FrontendWorkerBackendAttestationException(
            'backend_attestation_invalid',
            401,
            (string)__('后台页面凭证已失效，请刷新页面后重试。'),
            $previous,
        );
    }
}
