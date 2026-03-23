<?php

declare(strict_types=1);

namespace WeShop\Auth\Service;

use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Model\PendingAuthChallenge;
use WeShop\Customer\Service\CustomerAccountService;
use Weline\Api\Model\ApiUser;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;

class AuthGrantService
{
    public function __construct(
        private readonly CustomerAccountService $customerAccountService,
        private readonly WeShopAuth2FAOrchestrator $twoFactorOrchestrator,
        private readonly WeShopAuthTokenService $tokenService
    ) {
    }

    public function issuePasswordToken(string $area, string $username, string $password): array
    {
        $area = strtolower($area);
        if ($area === 'frontend') {
            $result = $this->customerAccountService->authenticate($username, $password);
            $context = new ActorContext(
                ActorContext::ACTOR_CUSTOMER,
                (int) $result['auth_user']->getId(),
                $area,
                ['customer']
            );
        } elseif ($area === 'backend') {
            /** @var BackendUser $backendUser */
            $backendUser = ObjectManager::getInstance(BackendUser::class);
            $backendUser->reset()
                ->where(BackendUser::schema_fields_username, trim($username))
                ->find()
                ->fetch();

            if (!$backendUser->getId() && str_contains($username, '@')) {
                $backendUser->reset()
                    ->where(BackendUser::schema_fields_email, trim($username))
                    ->find()
                    ->fetch();
            }

            if (
                !$backendUser->getId()
                || !$backendUser->getIsEnabled()
                || !password_verify($password, (string) $backendUser->getPassword())
            ) {
                throw new \RuntimeException((string) __('Invalid credentials.'));
            }

            $context = new ActorContext(
                ActorContext::ACTOR_BACKEND,
                (int) $backendUser->getId(),
                $area,
                ['backend']
            );
        } else {
            throw new \InvalidArgumentException((string) __('Unsupported area: %{1}', [$area]));
        }

        $primaryAuth = $this->twoFactorOrchestrator->beginPrimaryAuth(
            $context,
            'password',
            $area,
            ['flow' => 'password']
        );

        if (($primaryAuth['status'] ?? '') === 'challenge_required') {
            /** @var PendingAuthChallenge $challenge */
            $challenge = $primaryAuth['challenge'];
            return $this->buildChallengeResponse($context, $challenge);
        }

        return $this->buildTokenResponse($context, $this->tokenService->createTokenPair($context->withTwoFactorVerified()));
    }

    public function issueApiCredentialsToken(string $apiKey, string $apiSecret): array
    {
        /** @var ApiUser $apiUser */
        $apiUser = ObjectManager::getInstance(ApiUser::class);
        $apiUser->reset()
            ->where(ApiUser::schema_fields_api_key, trim($apiKey))
            ->where(ApiUser::schema_fields_is_deleted, 0)
            ->find()
            ->fetch();

        if (!$apiUser->getId() || !$apiUser->verifySecret($apiSecret) || !$apiUser->getIsEnabled()) {
            throw new \RuntimeException((string) __('Invalid integration credentials.'));
        }

        $context = new ActorContext(
            ActorContext::ACTOR_INTEGRATION,
            (int) $apiUser->getId(),
            'integration',
            ['integration']
        );

        return $this->buildTokenResponse($context, $this->tokenService->createTokenPair($context->withTwoFactorVerified()));
    }

    public function issueGoogleCodeToken(string $area, string $code): array
    {
        $serviceClass = 'WeShop\\GoogleAuth\\Service\\GoogleLoginService';
        if (!class_exists($serviceClass)) {
            throw new \RuntimeException((string) __('Google auth module is not installed.'));
        }

        /** @var object $service */
        $service = ObjectManager::getInstance($serviceClass);
        if (!method_exists($service, 'authenticateByCode')) {
            throw new \RuntimeException((string) __('Google auth service is unavailable.'));
        }

        $result = $service->authenticateByCode($area, $code);
        $actorType = (string) ($result['actor_type'] ?? '');
        $actorId = (int) ($result['actor_id'] ?? 0);
        $scopes = (array) ($result['scopes'] ?? []);

        if ($actorId <= 0 || $actorType === '') {
            throw new \RuntimeException((string) __('Google login failed.'));
        }

        $context = new ActorContext($actorType, $actorId, strtolower($area), $scopes);
        $primaryAuth = $this->twoFactorOrchestrator->beginPrimaryAuth(
            $context,
            'google',
            strtolower($area),
            ['flow' => 'google']
        );

        if (($primaryAuth['status'] ?? '') === 'challenge_required') {
            /** @var PendingAuthChallenge $challenge */
            $challenge = $primaryAuth['challenge'];
            return $this->buildChallengeResponse($context, $challenge);
        }

        return $this->buildTokenResponse($context, $this->tokenService->createTokenPair($context->withTwoFactorVerified()));
    }

    public function refreshToken(string $refreshToken): array
    {
        $tokens = $this->tokenService->refresh($refreshToken);
        if (!$tokens) {
            throw new \RuntimeException((string) __('Refresh token is invalid or expired.'));
        }

        $context = $this->tokenService->resolveAccessToken((string) $tokens['access_token']);
        if (!$context) {
            throw new \RuntimeException((string) __('Unable to resolve refreshed access token.'));
        }

        return $this->buildTokenResponse($context, $tokens);
    }

    public function verifyChallenge(string $challengeToken, string $code): array
    {
        $result = $this->twoFactorOrchestrator->verifyChallenge($challengeToken, $code);
        if (!$result) {
            throw new \RuntimeException((string) __('The 2FA code is invalid.'));
        }

        /** @var ActorContext $context */
        $context = $result['context'];
        return $this->buildTokenResponse($context, $this->tokenService->createTokenPair($context));
    }

    public function resolveMe(string $token): array
    {
        $context = $this->tokenService->resolveAccessToken($token);
        if (!$context) {
            throw new \RuntimeException((string) __('Access token is invalid.'));
        }

        return $this->buildTokenResponse($context, [
            'access_token' => $token,
            'refresh_token' => '',
            'expires_at' => '',
        ]);
    }

    public function logout(string $token): bool
    {
        return $this->tokenService->revoke($token);
    }

    private function buildChallengeResponse(ActorContext $context, PendingAuthChallenge $challenge): array
    {
        return [
            'status' => 'challenge_required',
            'actor_type' => $context->getActorType(),
            'access_token' => '',
            'refresh_token' => '',
            'challenge_token' => (string) $challenge->getData(PendingAuthChallenge::schema_fields_CHALLENGE_TOKEN),
            'expires_at' => (string) $challenge->getData(PendingAuthChallenge::schema_fields_EXPIRES_AT),
            'actor' => $this->buildActorPayload($context),
            'scopes' => $context->getScopes(),
        ];
    }

    private function buildTokenResponse(ActorContext $context, array $tokens): array
    {
        return [
            'status' => 'authenticated',
            'actor_type' => $context->getActorType(),
            'access_token' => $tokens['access_token'] ?? '',
            'refresh_token' => $tokens['refresh_token'] ?? '',
            'challenge_token' => '',
            'expires_at' => $tokens['expires_at'] ?? '',
            'actor' => $this->buildActorPayload($context),
            'scopes' => $context->getScopes(),
        ];
    }

    private function buildActorPayload(ActorContext $context): array
    {
        return [
            'id' => $context->getActorId(),
            'type' => $context->getActorType(),
            'area' => $context->getArea(),
            'is_2fa_verified' => $context->is2faVerified(),
        ];
    }
}
