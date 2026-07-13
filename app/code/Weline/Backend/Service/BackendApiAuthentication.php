<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\Auth\BackendApiActor;
use Weline\Backend\Api\Auth\BackendApiAuthenticationInterface;
use Weline\Backend\Api\Auth\BackendApiLoginResult;
use Weline\Backend\Api\Auth\BackendApiUser;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;

final class BackendApiAuthentication implements BackendApiAuthenticationInterface
{
    public function __construct(
        private readonly BackendTokenService $tokenService,
    ) {
    }

    public function authenticate(
        string $username,
        string $password,
        int $expireTime,
        string $clientIp,
    ): BackendApiLoginResult {
        $user = $this->newUserModel();
        $user->where(BackendUser::schema_fields_username, $username)->find()->fetch();

        if (!$user->getId()) {
            return new BackendApiLoginResult(BackendApiLoginResult::STATUS_USER_NOT_FOUND);
        }
        if (!$user->getIsEnabled()) {
            return new BackendApiLoginResult(BackendApiLoginResult::STATUS_USER_DISABLED);
        }
        if (!\password_verify($password, (string)$user->getPassword())) {
            $user->addAttemptTimes()->save();
            return new BackendApiLoginResult(BackendApiLoginResult::STATUS_PASSWORD_INVALID);
        }

        $user->resetAttemptTimes()->save();
        $token = $this->tokenService->createApiToken($user, $expireTime);
        if ($token === null || $token === '') {
            return new BackendApiLoginResult(BackendApiLoginResult::STATUS_TOKEN_FAILED);
        }

        $user->setLoginIp($clientIp)->save();
        return new BackendApiLoginResult(
            BackendApiLoginResult::STATUS_SUCCESS,
            $token,
            $this->mapUser($user),
        );
    }

    public function refreshToken(string $token, int $expireTime = 0): ?string
    {
        return $this->tokenService->refreshToken($token, $expireTime);
    }

    public function revokeToken(string $token): bool
    {
        return $this->tokenService->revokeToken($token);
    }

    public function getUserByToken(string $token): ?BackendApiUser
    {
        $user = $this->tokenService->getUserByToken($token);
        return $user instanceof BackendUser ? $this->mapUser($user) : null;
    }

    public function getTokenInfo(string $token): ?array
    {
        return $this->tokenService->getTokenInfo($token);
    }

    public function loadActor(int $userId): ?BackendApiActor
    {
        if ($userId <= 0) {
            return null;
        }

        $user = $this->newUserModel();
        $user->load($userId);
        if (!$user->getId()) {
            return null;
        }

        $role = $user->getRoleModel();
        return new BackendApiActor(
            $user,
            $role->getId() ? $role : null,
            $user->getIsEnabled(),
            $user->getIsDeleted(),
        );
    }

    private function newUserModel(): BackendUser
    {
        return ObjectManager::getInstance(BackendUser::class, [], false);
    }

    private function mapUser(BackendUser $user): BackendApiUser
    {
        return new BackendApiUser(
            (int)$user->getId(),
            (string)$user->getUsername(),
            (string)$user->getEmail(),
            (string)$user->getAvatar(),
            $user->getIsEnabled(),
            (string)$user->getLoginIp(),
            (string)$user->getData('login_time'),
            $user->isSandboxAccount(),
        );
    }
}
