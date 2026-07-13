<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Backend\Api\Auth\BackendInteractiveAuthInterface;
use Weline\Backend\Api\Auth\BackendLoginAccount;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Session\SessionFactory;

class BackendRememberLoginService
{
    private ?object $lastRestoredSession = null;
    private ?array $lastRestoredAclContext = null;

    public function __construct(
        private readonly Request $request,
        private readonly SessionFactory $sessionFactory,
        private readonly BackendInteractiveAuthInterface $backendAuth,
        private readonly MessageManager $messageManager
    ) {
    }

    public function restoreIfNeeded(?Request $request = null): bool
    {
        $this->lastRestoredSession = null;
        $this->lastRestoredAclContext = null;
        $request ??= $this->request;
        $currentRoutePath = \trim((string) $request->getRouteUrlPath(), '/');
        if ($currentRoutePath === 'admin/login/post') {
            return false;
        }

        $session = $this->getBackendSession();
        if ($session->getUserId()) {
            return false;
        }

        $token = $this->readRememberToken();
        if ($token === '') {
            $session->delete('remember_expire_time');
            return false;
        }

        $backendUserToken = $this->createRememberTokenModel()->findRememberToken($token);
        if ($backendUserToken === null) {
            $this->clearRememberCookie($request);
            $session->delete('remember_expire_time');
            return false;
        }

        $expireAt = $backendUserToken->getExpireAt();
        if ($expireAt <= \time()) {
            $this->invalidateRememberToken($token);
            $this->clearRememberCookie($request);
            $session->delete('remember_expire_time');
            $this->messageManager->addWarning(__('记住登录已过期，请重新登录！'));
            return false;
        }

        $userId = $backendUserToken->getUserId();
        $adminUser = $this->createBackendUserModel()->find($userId);
        if ($adminUser === null) {
            $this->invalidateRememberToken($token);
            $this->clearRememberCookie($request);
            $this->messageManager->addWarning(__('用户不存在！'));
            return false;
        }

        $this->restoreIntoCurrentSession($session, $adminUser, $expireAt);
        $adminUser = $this->backendAuth->completeLogin($userId, (string)$session->getId(), $request->clientIP());

        w_auth_log('remember_login_restored', 'Remember-me restored backend session before controller flow', [
            'user_id' => $adminUser->getId(),
            'route' => $currentRoutePath,
            'session_id_hint' => $session->getId() !== '' ? \substr($session->getId(), 0, 8) . '...' : 'empty',
        ]);

        $this->lastRestoredSession = $session;
        $this->lastRestoredAclContext = [
            'user_id' => (int) $adminUser->getId(),
            'role_id' => $adminUser->getRoleId(),
            'is_enabled' => $adminUser->getIsEnabled() ? 1 : 0,
        ];
        return true;
    }

    public function consumeRestoredSession(): ?object
    {
        $session = $this->lastRestoredSession;
        $this->lastRestoredSession = null;

        return $session;
    }

    public function consumeRestoredAclContext(): ?array
    {
        $context = $this->lastRestoredAclContext;
        $this->lastRestoredAclContext = null;

        return $context;
    }

    private function restoreIntoCurrentSession(object $session, BackendLoginAccount $adminUser, int $expireAt): void
    {
        $this->backendAuth->restoreRememberedSession($session, $adminUser, $expireAt);
    }

    private function clearRememberCookie(Request $request): void
    {
        Cookie::set('w_ut', '', -1, ['path' => '/']);
        Cookie::set('w_ut', '', -1, ['path' => '/' . $request->getAreaRouter()]);
    }

    protected function readRememberToken(): string
    {
        return (string) Cookie::get('w_ut', '');
    }

    protected function createRememberTokenModel(): BackendInteractiveAuthInterface
    {
        return $this->backendAuth;
    }

    protected function getBackendSession(): object
    {
        if ((\defined('ENV_TEST') && ENV_TEST === true) || \defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__')) {
            return $this->sessionFactory->createBackendSession();
        }

        return SessionFactory::getInstance()->createBackendSession();
    }

    protected function createBackendUserModel(): BackendInteractiveAuthInterface
    {
        return $this->backendAuth;
    }

    private function invalidateRememberToken(string $token): void
    {
        $this->backendAuth->invalidateRememberToken($token);
    }
}
