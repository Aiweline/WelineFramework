<?php

declare(strict_types=1);

namespace Weline\Customer\Api;

/**
 * Optional storefront login challenge (e.g. 2FA). Implemented by integration modules via module env discovery.
 */
interface CustomerLoginChallengeHandlerInterface
{
    /**
     * @return int|null Unix timestamp when the challenge expires, or null if unknown/invalid
     */
    public function getChallengeExpiresAt(string $challengeToken): ?int;

    /**
     * @return array{redirect_url?: string, status?: string}
     */
    public function completeChallenge(string $challengeToken, string $code): array;
}
