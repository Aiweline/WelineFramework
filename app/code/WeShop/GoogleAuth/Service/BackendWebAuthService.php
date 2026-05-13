<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Service;

use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Model\PendingAuthChallenge;
use WeShop\Auth\Service\PendingAuthChallengeService;
use WeShop\Auth\Service\WeShopAuth2FAOrchestrator;
use Weline\Admin\Service\BackendLoginReturnUrlService;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\BackendUserToken;
use Weline\Backend\Service\MenuServiceInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\Strategy\WlsStrategy;
use Weline\Framework\System\Text;

class BackendWebAuthService
{
    private BackendLoginReturnUrlService $returnUrlService;

    public function __construct(
        private readonly WeShopAuth2FAOrchestrator $twoFactorOrchestrator,
        private readonly PendingAuthChallengeService $pendingAuthChallengeService,
        private readonly MenuServiceInterface $menuService,
        private readonly BackendUserToken $backendUserToken,
        private readonly BackendUser $backendUser,
        private readonly Url $url,
        private readonly Request $request,
        ?BackendLoginReturnUrlService $returnUrlService = null
    ) {
        $this->returnUrlService = $returnUrlService ?? ObjectManager::getInstance(BackendLoginReturnUrlService::class);
    }

    public function beginLoginForBackendUser(
        BackendUser $user,
        string $authMethod = 'password',
        bool $rememberMe = false,
        string $redirectUrl = ''
    ): array {
        $this->assertUserCanLogin($user);
        $redirectUrl = $this->normalizeRedirectTarget($user, $redirectUrl);
        $context = new ActorContext(
            ActorContext::ACTOR_BACKEND,
            (int) $user->getId(),
            'backend',
            ['backend']
        );

        $primaryAuth = $this->twoFactorOrchestrator->beginPrimaryAuth(
            $context,
            $authMethod,
            'backend',
            [
                'flow' => $authMethod,
                'remember_me' => $rememberMe,
                'redirect_url' => $redirectUrl,
            ]
        );

        if (($primaryAuth['status'] ?? '') === 'challenge_required') {
            /** @var PendingAuthChallenge $challenge */
            $challenge = $primaryAuth['challenge'];

            return [
                'status' => 'challenge_required',
                'challenge_token' => (string) $challenge->getData(PendingAuthChallenge::schema_fields_CHALLENGE_TOKEN),
                'expires_at' => (int) $challenge->getData(PendingAuthChallenge::schema_fields_EXPIRES_AT),
                'redirect_url' => $redirectUrl,
            ];
        }

        $this->completeLogin($user, $rememberMe);

        return [
            'status' => 'authenticated',
            'redirect_url' => $redirectUrl,
        ];
    }

    public function completeChallenge(string $challengeToken, string $code): array
    {
        $result = $this->twoFactorOrchestrator->verifyChallenge($challengeToken, $code);
        if (!$result) {
            throw new \RuntimeException((string) __('The verification code is invalid.'));
        }

        /** @var ActorContext $context */
        $context = $result['context'];
        if ($context->getActorType() !== ActorContext::ACTOR_BACKEND) {
            throw new \RuntimeException((string) __('The challenge does not belong to a backend login.'));
        }

        $backendUser = clone $this->backendUser;
        $backendUser->load($context->getActorId());
        if (!$backendUser->getId()) {
            throw new \RuntimeException((string) __('The backend account for this challenge no longer exists.'));
        }

        $payload = (array) ($result['payload'] ?? []);
        $redirectUrl = $this->normalizeRedirectTarget(
            $backendUser,
            (string) ($payload['redirect_url'] ?? '')
        );
        $this->completeLogin($backendUser, (bool) ($payload['remember_me'] ?? false));

        return [
            'status' => 'authenticated',
            'redirect_url' => $redirectUrl,
        ];
    }

    public function getChallenge(string $challengeToken): ?PendingAuthChallenge
    {
        $challenge = $this->pendingAuthChallengeService->getValidChallenge($challengeToken);
        if (!$challenge) {
            return null;
        }

        return (string) $challenge->getData(PendingAuthChallenge::schema_fields_ACTOR_TYPE) === ActorContext::ACTOR_BACKEND
            ? $challenge
            : null;
    }

    private function completeLogin(BackendUser $user, bool $rememberMe = false): void
    {
        $this->assertUserCanLogin($user);
        $session = $this->getBackendSession();
        $session->login($user);

        $role = $user->getRole();
        $isSuperAdminById = (int) $user->getId() === 1;
        $aclRoleId = $role && $role->getRoleId() ? (int) $role->getRoleId() : ($isSuperAdminById ? 1 : 0);

        $rawSession = $session->getSession();
        $rawSession->set('backend_acl_role_id', $aclRoleId);
        $rawSession->set('backend_acl_is_enabled', $user->getIsEnabled() ? 1 : 0);
        $rawSession->delete('need_backend_verification_code');
        $rawSession->delete('backend_verification_code');
        $rawSession->delete('backend_disable_login');
        $rawSession->delete('backend_disable_login_username');

        $user->setSessionId($session->getId())
            ->setLoginIp($this->request->clientIP())
            ->resetAttemptTimes()
            ->save();

        $this->syncSandboxCookie($user->isSandboxAccount());
        $this->persistRememberMe($user, $rememberMe);

        $rawSession->save();
        if ($rawSession instanceof Session) {
            $rawSession->getStrategy()->writeClose();
        }
        Session::flushRequestSessions();

        $sid = $session->getId();
        if ($sid !== '') {
            HeaderCollector::getInstance()->setCookie(
                WlsStrategy::SESSION_NAME,
                $sid,
                time() + 86400 * 30,
                '/',
                '',
                $this->request->isSecure(),
                true,
                'Lax'
            );
        }
    }

    private function persistRememberMe(BackendUser $user, bool $rememberMe): void
    {
        $tokenModel = clone $this->backendUserToken;
        $tokenModel->load((int) $user->getId());

        if ($rememberMe) {
            $token = Text::random_string(32);
            $rememberTtl = 7 * 24 * 60 * 60;
            $tokenExpireTime = time() + $rememberTtl;

            $tokenModel->setData(BackendUserToken::schema_fields_ID, (int) $user->getId())
                ->setData(BackendUserToken::schema_fields_type, 'admin_login_remember_me')
                ->setData(BackendUserToken::schema_fields_token, $token)
                ->setData(BackendUserToken::schema_fields_token_expire_time, $tokenExpireTime)
                ->save();

            Cookie::set('w_ut', $token, $rememberTtl, ['path' => '/']);
            $this->getBackendSession()->set('remember_expire_time', $tokenExpireTime);
            return;
        }

        if ($tokenModel->getId()) {
            $tokenModel->setData(BackendUserToken::schema_fields_type, 'admin_login_remember_me')
                ->setData(BackendUserToken::schema_fields_token, '')
                ->setData(BackendUserToken::schema_fields_token_expire_time, 0)
                ->save();
        }

        Cookie::set('w_ut', '', -1, ['path' => '/']);
        $this->getBackendSession()->delete('remember_expire_time');
    }

    private function normalizeRedirectTarget(BackendUser $user, string $redirectUrl = ''): string
    {
        $target = $this->returnUrlService->resolveForUser($user, $redirectUrl);
        if ($target !== null) {
            return $target;
        }

        return $this->url->getBackendUrl($this->returnUrlService->resolveDefaultRedirectTarget($user));
    }

    private function assertUserCanLogin(BackendUser $user): void
    {
        if (!$user->getId() || !$user->getIsEnabled()) {
            throw new \RuntimeException((string) __('The backend account is unavailable.'));
        }

        $userRole = $user->getRole();
        $hasRole = (bool) ($userRole && $userRole->getRoleId());
        $isSuperAdminById = (int) $user->getId() === 1;
        if (!$hasRole && !$isSuperAdminById) {
            throw new \RuntimeException((string) __('This backend account has no role and cannot sign in.'));
        }
    }

    private function resolveDefaultRedirectTarget(BackendUser $user): string
    {
        return $this->returnUrlService->resolveDefaultRedirectTarget($user);
    }

    private function getBackendSession(): \Weline\Framework\Session\Auth\AuthenticatedSessionInterface
    {
        $session = SessionFactory::backend();
        $session->start(null);
        return $session;
    }

    private function syncSandboxCookie(bool $enabled): void
    {
        $lifetime = $enabled ? 0 : -1;
        Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/']);
        Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/' . $this->request->getAreaRouter()]);
    }
}
