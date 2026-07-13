<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

/** Backend-owned password/session/remember-login boundary for the Admin UI. */
interface BackendInteractiveAuthInterface
{
    public function find(int $userId): ?BackendLoginAccount;

    public function findByUsername(string $username): ?BackendLoginAccount;

    public function findBySessionId(string $sessionId): ?BackendLoginAccount;

    public function incrementAttemptTimes(int $userId): BackendLoginAccount;

    public function recordAttemptContext(int $userId, string $sessionId, string $attemptIp): BackendLoginAccount;

    public function verifyPassword(int $userId, string $password): bool;

    public function installSessionIdentity(object $session, BackendLoginAccount $account): void;

    public function completeLogin(int $userId, string $sessionId, string $loginIp): BackendLoginAccount;

    public function storeRememberToken(int $userId, string $token, int $expireAt): void;

    public function findRememberToken(string $token): ?BackendRememberToken;

    public function invalidateRememberToken(string $token): bool;

    public function invalidateRememberTokenForUser(int $userId): bool;

    public function restoreRememberedSession(object $session, BackendLoginAccount $account, int $expireAt): void;
}
