<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

interface BackendApiAuthenticationInterface
{
    public function authenticate(
        string $username,
        string $password,
        int $expireTime,
        string $clientIp,
    ): BackendApiLoginResult;

    public function refreshToken(string $token, int $expireTime = 0): ?string;

    public function revokeToken(string $token): bool;

    public function getUserByToken(string $token): ?BackendApiUser;

    public function getTokenInfo(string $token): ?array;

    public function loadActor(int $userId): ?BackendApiActor;
}
