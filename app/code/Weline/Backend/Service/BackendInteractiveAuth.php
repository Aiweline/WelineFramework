<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\Auth\BackendInteractiveAuthInterface;
use Weline\Backend\Api\Auth\BackendLoginAccount;
use Weline\Backend\Api\Auth\BackendRememberToken;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\BackendUserToken;

final class BackendInteractiveAuth implements BackendInteractiveAuthInterface
{
    private const REMEMBER_TOKEN_TYPE = 'admin_login_remember_me';

    public function __construct(
        private readonly BackendUser $userPrototype,
        private readonly BackendUserToken $tokenPrototype,
        private readonly UserRole $userRolePrototype,
    ) {
    }

    public function find(int $userId): ?BackendLoginAccount
    {
        if ($userId <= 0) {
            return null;
        }
        $user = $this->newUser()->load($userId);
        return $user->getId() ? $this->mapUser($user) : null;
    }

    public function findByUsername(string $username): ?BackendLoginAccount
    {
        $user = $this->newUser()
            ->where(BackendUser::schema_fields_username, $username)
            ->find()
            ->fetch();
        return $user->getId() ? $this->mapUser($user) : null;
    }

    public function findBySessionId(string $sessionId): ?BackendLoginAccount
    {
        $user = $this->newUser()->load(BackendUser::schema_fields_sess_id, $sessionId);
        return $user->getId() ? $this->mapUser($user) : null;
    }

    public function incrementAttemptTimes(int $userId): BackendLoginAccount
    {
        $user = $this->loadRequiredUser($userId);
        $user->addAttemptTimes()->save();
        return $this->mapUser($user);
    }

    public function recordAttemptContext(int $userId, string $sessionId, string $attemptIp): BackendLoginAccount
    {
        $user = $this->loadRequiredUser($userId);
        $user->setSessionId($sessionId)->setAttemptIp($attemptIp)->save();
        return $this->mapUser($user);
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $storedPassword = (string)$this->loadRequiredUser($userId)->getPassword();
        return $storedPassword !== '' && password_verify($password, $storedPassword);
    }

    public function installSessionIdentity(object $session, BackendLoginAccount $account): void
    {
        $user = $this->loadRequiredUser($account->getId());
        if (!method_exists($session, 'login')) {
            throw new \RuntimeException('Backend session does not support login.');
        }
        $session->login($user);
    }

    public function completeLogin(int $userId, string $sessionId, string $loginIp): BackendLoginAccount
    {
        $user = $this->loadRequiredUser($userId);
        $user->setSessionId($sessionId)->setLoginIp($loginIp)->resetAttemptTimes()->save();
        return $this->mapUser($user);
    }

    public function storeRememberToken(int $userId, string $token, int $expireAt): void
    {
        $rememberToken = $this->newToken()->load($userId);
        $rememberToken
            ->setData(BackendUserToken::schema_fields_ID, $userId)
            ->setData(BackendUserToken::schema_fields_type, self::REMEMBER_TOKEN_TYPE)
            ->setData(BackendUserToken::schema_fields_token, $token)
            ->setData(BackendUserToken::schema_fields_token_expire_time, $expireAt)
            ->save();
    }

    public function findRememberToken(string $token): ?BackendRememberToken
    {
        if ($token === '') {
            return null;
        }
        $rememberToken = $this->newToken()
            ->where(BackendUserToken::schema_fields_token, $token)
            ->where(BackendUserToken::schema_fields_type, self::REMEMBER_TOKEN_TYPE)
            ->find()
            ->fetch();
        if (!$rememberToken->getId()) {
            return null;
        }

        return new BackendRememberToken(
            (int)$rememberToken->getId(),
            (int)$rememberToken->getData(BackendUserToken::schema_fields_token_expire_time),
        );
    }

    public function invalidateRememberToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $rememberToken = $this->newToken()
            ->where(BackendUserToken::schema_fields_token, $token)
            ->where(BackendUserToken::schema_fields_type, self::REMEMBER_TOKEN_TYPE)
            ->find()
            ->fetch();
        return $this->clearRememberToken($rememberToken);
    }

    public function invalidateRememberTokenForUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $rememberToken = $this->newToken()
            ->where(BackendUserToken::schema_fields_ID, $userId)
            ->where(BackendUserToken::schema_fields_type, self::REMEMBER_TOKEN_TYPE)
            ->find()
            ->fetch();
        return $this->clearRememberToken($rememberToken);
    }

    public function restoreRememberedSession(object $session, BackendLoginAccount $account, int $expireAt): void
    {
        $user = $this->loadRequiredUser($account->getId());
        if (method_exists($session, 'getAreaConfig') && method_exists($session, 'getSession')) {
            $areaConfig = $session->getAreaConfig();
            $rawSession = $session->getSession();
            $rawSession->set($areaConfig->getLoginKey(), $user->getAuthUsername());
            $rawSession->set($areaConfig->getLoginIdKey(), $user->getAuthIdentifier());
            $rawSession->set($areaConfig->getUserModelKey(), $user::getAuthModelClass());
            $rawSession->set('remember_expire_time', $expireAt);
            $this->installAclContext($rawSession, $account);
            $rawSession->save();
            $this->refreshSessionCookie($session, $rawSession, $expireAt);
            return;
        }

        if (!method_exists($session, 'login')) {
            throw new \RuntimeException('Backend session does not support login.');
        }
        $session->login($user);
        $session->set('remember_expire_time', $expireAt);
        if (method_exists($session, 'getSession')) {
            $rawSession = $session->getSession();
            $this->installAclContext($rawSession, $account);
            $rawSession->save();
            $this->refreshSessionCookie($session, $rawSession, $expireAt);
        }
    }

    private function loadRequiredUser(int $userId): BackendUser
    {
        $user = $this->newUser()->load($userId);
        if (!$user->getId()) {
            throw new \RuntimeException((string)__('用户不存在！'));
        }
        return $user;
    }

    private function mapUser(BackendUser $user): BackendLoginAccount
    {
        $userId = (int)$user->getId();
        $userRole = $this->newUserRole()
            ->where(UserRole::schema_fields_USER_ID, $userId)
            ->find()
            ->fetch();
        $roleId = (int)($userRole->getRoleId() ?: ($userId === 1 ? 1 : 0));

        return new BackendLoginAccount(
            $userId,
            (string)$user->getUsername(),
            (string)$user->getEmail(),
            (string)$user->getAvatar(),
            $user->getAttemptTimes(),
            $user->getIsDeleted(),
            $user->getIsEnabled(),
            $user->isSandboxAccount(),
            $roleId,
        );
    }

    private function installAclContext(object $rawSession, BackendLoginAccount $account): void
    {
        $rawSession->set('backend_acl_role_id', $account->getRoleId());
        $rawSession->set('backend_acl_is_enabled', $account->getIsEnabled() ? 1 : 0);
    }

    private function refreshSessionCookie(object $session, object $rawSession, int $expireAt): void
    {
        if (!method_exists($session, 'getId') || !method_exists($rawSession, 'getStrategy')) {
            return;
        }
        $sessionId = (string)$session->getId();
        $remainingTtl = $expireAt - time();
        if ($sessionId !== '' && $remainingTtl > 0) {
            $rawSession->getStrategy()->setCookie($sessionId, $remainingTtl);
        }
    }

    private function clearRememberToken(BackendUserToken $rememberToken): bool
    {
        if (!$rememberToken->getId()) {
            return false;
        }
        $rememberToken
            ->setData(BackendUserToken::schema_fields_token, '')
            ->setData(BackendUserToken::schema_fields_token_expire_time, 0)
            ->save();
        return true;
    }

    private function newUser(): BackendUser
    {
        return (clone $this->userPrototype)->clearData()->clearQuery();
    }

    private function newToken(): BackendUserToken
    {
        return (clone $this->tokenPrototype)->clearData()->clearQuery();
    }

    private function newUserRole(): UserRole
    {
        return (clone $this->userRolePrototype)->clearData()->clearQuery();
    }
}
