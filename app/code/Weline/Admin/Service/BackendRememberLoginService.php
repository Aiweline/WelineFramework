<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\BackendUserToken;
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
        private readonly BackendUserToken $backendUserToken,
        private readonly BackendUser $backendUser,
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

        $backendUserToken = $this->createRememberTokenModel();
        $backendUserToken->where($backendUserToken::schema_fields_token, $token)
            ->where($backendUserToken::schema_fields_type, 'admin_login_remember_me')
            ->find()
            ->fetch();

        if (!$backendUserToken->getId()) {
            $this->clearRememberCookie($request);
            $session->delete('remember_expire_time');
            return false;
        }

        $expireAt = (int) $backendUserToken->getData($backendUserToken::schema_fields_token_expire_time);
        if ($expireAt <= \time()) {
            $this->invalidateRememberToken($backendUserToken);
            $this->clearRememberCookie($request);
            $session->delete('remember_expire_time');
            $this->messageManager->addWarning(__('记住登录已过期，请重新登录！'));
            return false;
        }

        $userId = (int) $backendUserToken->getId();
        $adminUser = $this->createBackendUserModel();
        $adminUser->load($userId);
        if (!$adminUser->getId()) {
            $this->invalidateRememberToken($backendUserToken);
            $this->clearRememberCookie($request);
            $this->messageManager->addWarning(__('用户不存在！'));
            return false;
        }

        $this->restoreIntoCurrentSession($session, $adminUser, $expireAt);

        $adminUser->setSessionId($session->getId())
            ->setLoginIp($request->clientIP())
            ->resetAttemptTimes()
            ->save();

        w_auth_log('remember_login_restored', 'Remember-me restored backend session before controller flow', [
            'user_id' => $adminUser->getId(),
            'route' => $currentRoutePath,
            'session_id_hint' => $session->getId() !== '' ? \substr($session->getId(), 0, 8) . '...' : 'empty',
        ]);

        $this->lastRestoredSession = $session;
        $userRole = $adminUser->getRole();
        $isSuperAdminById = (int) $adminUser->getId() === 1;
        $this->lastRestoredAclContext = [
            'user_id' => (int) $adminUser->getId(),
            'role_id' => $userRole && $userRole->getRoleId() ? (int) $userRole->getRoleId() : ($isSuperAdminById ? 1 : 0),
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

    private function restoreIntoCurrentSession(object $session, BackendUser $adminUser, int $expireAt): void
    {
        if (\method_exists($session, 'getAreaConfig') && \method_exists($session, 'getSession')) {
            /** @var \Weline\Framework\Session\Auth\AuthenticatedSessionInterface $session */
            $areaConfig = $session->getAreaConfig();
            $rawSession = $session->getSession();
            $rawSession->set($areaConfig->getLoginKey(), $adminUser->getAuthUsername());
            $rawSession->set($areaConfig->getLoginIdKey(), $adminUser->getAuthIdentifier());
            $rawSession->set($areaConfig->getUserModelKey(), $adminUser::getAuthModelClass());
            $rawSession->set('remember_expire_time', $expireAt);

            $userRole = $adminUser->getRole();
            $isSuperAdminById = (int) $adminUser->getId() === 1;
            $aclRoleId = $userRole && $userRole->getRoleId() ? (int) $userRole->getRoleId() : ($isSuperAdminById ? 1 : 0);
            $rawSession->set('backend_acl_role_id', $aclRoleId);
            $rawSession->set('backend_acl_is_enabled', $adminUser->getIsEnabled() ? 1 : 0);
            $rawSession->save();
            return;
        }

        $session->login($adminUser);
        $session->set('remember_expire_time', $expireAt);

        if (\method_exists($session, 'getSession')) {
            $userRole = $adminUser->getRole();
            $isSuperAdminById = (int) $adminUser->getId() === 1;
            $aclRoleId = $userRole && $userRole->getRoleId() ? (int) $userRole->getRoleId() : ($isSuperAdminById ? 1 : 0);
            $rawSession = $session->getSession();
            $rawSession->set('backend_acl_role_id', $aclRoleId);
            $rawSession->set('backend_acl_is_enabled', $adminUser->getIsEnabled() ? 1 : 0);
            $rawSession->save();
        }
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

    protected function createRememberTokenModel(): BackendUserToken
    {
        return clone $this->backendUserToken;
    }

    protected function getBackendSession(): object
    {
        if ((\defined('ENV_TEST') && ENV_TEST === true) || \defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__')) {
            return $this->sessionFactory->createBackendSession();
        }

        return SessionFactory::getInstance()->createBackendSession();
    }

    protected function createBackendUserModel(): BackendUser
    {
        return clone $this->backendUser;
    }

    private function invalidateRememberToken(BackendUserToken $backendUserToken): void
    {
        if (!$backendUserToken->getId()) {
            return;
        }

        $backendUserToken->setData($backendUserToken::schema_fields_token, '')
            ->setData($backendUserToken::schema_fields_token_expire_time, 0)
            ->save();
    }
}
