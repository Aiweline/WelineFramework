<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Backend\Api\Runtime\BackendWarmupContext;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

final class BackendUserContextProvider implements BackendUserContextProviderInterface
{
    public function current(): ?BackendUserContext
    {
        $warmupUser = BackendWarmupContext::currentUser();
        if ($warmupUser instanceof BackendUser) {
            return $this->map($warmupUser);
        }

        $user = SessionFactory::getInstance()->createBackendSession()->getUser();
        return $user instanceof BackendUser ? $this->map($user) : null;
    }

    public function find(int $userId): ?BackendUserContext
    {
        if ($userId <= 0) {
            return null;
        }
        /** @var BackendUser $user */
        $user = ObjectManager::getInstance(BackendUser::class, [], false);
        $user->load($userId);
        return $user->getId() ? $this->map($user) : null;
    }

    private function map(BackendUser $user): BackendUserContext
    {
        $userId = (int)$user->getId();
        $roleId = (int)($user->getRole()->getRoleId() ?: 0);
        if ($userId === 1 && $roleId === 0) {
            $roleId = 1;
        }
        return new BackendUserContext(
            $userId,
            (string)$user->getUsername(),
            (string)$user->getEmail(),
            (string)$user->getAvatar(),
            $roleId,
            $user->getIsEnabled(),
            $user->isSandboxAccount(),
        );
    }
}
