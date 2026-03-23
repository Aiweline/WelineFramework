<?php

declare(strict_types=1);

namespace WeShop\Auth\Service;

use WeShop\Auth\Data\ActorContext;
use WeShop\GoogleAuth\Service\GoogleLoginService;

class GoogleCodeAuthenticator
{
    public function __construct(
        private readonly GoogleLoginService $googleLoginService
    ) {
    }

    public function authenticate(string $area, string $code): ActorContext
    {
        $result = $this->googleLoginService->authenticateByCode($area, $code);
        $actorType = (string) ($result['actor_type'] ?? '');
        $actorId = (int) ($result['actor_id'] ?? 0);
        $scopes = (array) ($result['scopes'] ?? []);

        if ($actorId <= 0 || $actorType === '') {
            throw new \RuntimeException((string) __('Google login failed.'));
        }

        return new ActorContext($actorType, $actorId, strtolower($area), $scopes);
    }
}
