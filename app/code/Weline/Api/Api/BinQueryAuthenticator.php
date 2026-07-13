<?php

declare(strict_types=1);

namespace Weline\Api\Api;

use Weline\Api\Service\ApiAppTokenService;
use Weline\Framework\Service\Query\Auth\BinQueryAuthContext;
use Weline\Framework\Service\Query\Auth\BinQueryAuthenticatorInterface;

final class BinQueryAuthenticator implements BinQueryAuthenticatorInterface
{
    public function __construct(
        private readonly ApiAppTokenService $tokenService,
    ) {
    }

    public function authenticate(string $token): ?BinQueryAuthContext
    {
        $context = $this->tokenService->resolveAccessToken($token);
        if ($context === null) {
            return null;
        }

        $actor = $context->getActor();
        return new BinQueryAuthContext(
            $context->getAccessSources(),
            [
                'app_id' => $actor->getApp()->getId(),
                'installation_id' => $actor->getInstallation()->getId(),
            ],
        );
    }
}
