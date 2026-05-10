<?php

declare(strict_types=1);

namespace WeShop\Customer\Service;

use WeShop\Auth\Model\PendingAuthChallenge;
use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;

/**
 * Bridges WeShop WebAuth 2FA into Weline_Customer challenge routes (configured via WeShop_Customer etc/env.php).
 */
class WeShopCustomerLoginChallengeHandler implements CustomerLoginChallengeHandlerInterface
{
    public function __construct(
        private readonly CustomerWebAuthService $customerWebAuthService
    ) {
    }

    public function getChallengeExpiresAt(string $challengeToken): ?int
    {
        $challenge = $this->customerWebAuthService->getChallenge($challengeToken);
        if (!$challenge) {
            return null;
        }

        return (int) $challenge->getData(PendingAuthChallenge::schema_fields_EXPIRES_AT);
    }

    public function completeChallenge(string $challengeToken, string $code): array
    {
        return $this->customerWebAuthService->completeChallenge($challengeToken, $code);
    }
}
