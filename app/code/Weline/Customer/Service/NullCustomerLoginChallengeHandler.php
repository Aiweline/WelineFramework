<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;

/**
 * Default no-op handler when no integration registers {@see CustomerLoginChallengeHandlerInterface}.
 */
class NullCustomerLoginChallengeHandler implements CustomerLoginChallengeHandlerInterface
{
    public function getChallengeExpiresAt(string $challengeToken): ?int
    {
        unset($challengeToken);
        return null;
    }

    public function completeChallenge(string $challengeToken, string $code): array
    {
        unset($challengeToken, $code);
        throw new \RuntimeException((string) __('Two-factor verification is not available.'));
    }
}
