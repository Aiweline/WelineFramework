<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\BackendSessionUserProviderInterface;

final class BackendSessionUserProvider implements BackendSessionUserProviderInterface
{
    public function findEnabledBySessionId(string $sessionId): ?object
    {
        $user = ObjectManager::getInstance(BackendUser::class);
        $user->where('sess_id', $sessionId)->find()->fetch();
        return $user->getId() && (bool)$user->getData('is_enabled') ? $user : null;
    }
}
