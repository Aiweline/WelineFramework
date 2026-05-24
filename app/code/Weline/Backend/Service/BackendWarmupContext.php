<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

final class BackendWarmupContext
{
    public const INTERNAL_SERVER_FLAG = 'WLS_INTERNAL_BACKEND_WARMUP';
    public const INTERNAL_USER_ID_SERVER_KEY = 'WLS_INTERNAL_BACKEND_WARMUP_USER_ID';
    public const USER_CONTEXT_KEY = 'backend.warmup.user';
    public const USER_ID_CONTEXT_KEY = 'backend.warmup.user_id';
    public const AUTH_CONTEXT_KEY = 'theme.backend_partial_auth_context';

    private const DEFAULT_WARMUP_USER_ID = 1;

    public static function isInternalWarmupRequest(?Request $request = null): bool
    {
        $value = null;
        if ($request !== null) {
            $value = $request->getServer(self::INTERNAL_SERVER_FLAG);
        }
        if ($value === null || $value === '') {
            $value = $_SERVER[self::INTERNAL_SERVER_FLAG] ?? $_ENV[self::INTERNAL_SERVER_FLAG] ?? \getenv(self::INTERNAL_SERVER_FLAG);
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on', 'warmup'], true);
    }

    public static function isActive(): bool
    {
        return self::currentUserId() > 0;
    }

    public static function currentUser(): ?BackendUser
    {
        $user = RequestContext::get(self::USER_CONTEXT_KEY);
        return $user instanceof BackendUser && $user->getId() ? $user : null;
    }

    public static function currentUserId(): int
    {
        $user = self::currentUser();
        if ($user instanceof BackendUser) {
            return (int)$user->getId();
        }

        return (int)RequestContext::get(self::USER_ID_CONTEXT_KEY, 0);
    }

    public static function installForUser(BackendUser $user): void
    {
        if (!$user->getId()) {
            return;
        }

        RequestContext::set(self::USER_CONTEXT_KEY, $user);
        RequestContext::set(self::USER_ID_CONTEXT_KEY, (int)$user->getId());
        RequestContext::set(self::AUTH_CONTEXT_KEY, self::authContextForUser($user));
    }

    public static function clear(): void
    {
        RequestContext::remove(self::USER_CONTEXT_KEY);
        RequestContext::remove(self::USER_ID_CONTEXT_KEY);
        RequestContext::remove(self::AUTH_CONTEXT_KEY);
    }

    public static function resolveWarmupUser(?Request $request = null): ?BackendUser
    {
        $userId = self::resolveWarmupUserId($request);
        if ($userId <= 0) {
            return null;
        }

        try {
            /** @var BackendUser $user */
            $user = ObjectManager::make(BackendUser::class);
            $user->load($userId);
            if (!$user->getId() || $user->getIsDeleted() || !$user->getIsEnabled()) {
                return null;
            }

            return $user;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function resolveWarmupUserId(?Request $request = null): int
    {
        $raw = null;
        if ($request !== null) {
            $raw = $request->getServer(self::INTERNAL_USER_ID_SERVER_KEY);
        }
        if ($raw === null || $raw === '') {
            $raw = \getenv('WLS_WORKER_BACKEND_WARMUP_USER_ID');
        }
        if ($raw === false || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.backend_warmup_user_id', self::DEFAULT_WARMUP_USER_ID);
        }

        return \max(0, (int)$raw);
    }

    public static function authContextForUser(BackendUser $user): string
    {
        return 'backend-auth:1:' . \sha1((string)((int)$user->getId()) . '|' . (string)$user->getUsername());
    }
}
