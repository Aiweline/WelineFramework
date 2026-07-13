<?php

declare(strict_types=1);

namespace Weline\Customer\Api;

/** Optional pre-login challenge creation using scalar customer identity only. */
interface CustomerLoginChallengeCreatorInterface
{
    /**
     * @return array{challenge_token: string, redirect: string, expires_at: int}|null
     */
    public function createChallenge(
        int $customerId,
        string $redirectUrl = '',
        int $rememberDuration = 0,
    ): ?array;
}
