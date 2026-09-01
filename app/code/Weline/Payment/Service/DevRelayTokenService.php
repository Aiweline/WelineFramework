<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

final class DevRelayTokenService
{
    public function generateToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', trim($token));
    }

    public function verifyToken(string $token, string $hash): bool
    {
        if ($token === '' || $hash === '') {
            return false;
        }

        return hash_equals($hash, $this->hashToken($token));
    }
}
