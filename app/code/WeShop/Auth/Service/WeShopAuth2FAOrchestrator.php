<?php

declare(strict_types=1);

namespace WeShop\Auth\Service;

use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Model\PendingAuthChallenge;
use Weline\TwoFactorAuth\Model\TwoFactorConfig;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

class WeShopAuth2FAOrchestrator
{
    public const MODULE = 'WeShop_Auth';

    public function __construct(
        private readonly TwoFactorAuthService $twoFactorAuthService,
        private readonly TwoFactorConfig $twoFactorConfig,
        private readonly PendingAuthChallengeService $pendingAuthChallengeService
    ) {
    }

    public function isEnabled(string $area, string $flow): bool
    {
        return (bool) $this->twoFactorConfig->getConfig(
            'flow.' . $flow . '.enabled',
            self::MODULE,
            $area,
            false
        );
    }

    public function beginPrimaryAuth(
        ActorContext $actorContext,
        string $authMethod,
        string $area,
        array $payload = []
    ): array {
        $flow = $payload['flow'] ?? $authMethod;
        $shadowUserId = $this->getShadowUserId($actorContext->getActorType(), $actorContext->getActorId());

        if (!$this->isEnabled($area, (string) $flow) || !$this->twoFactorAuthService->isEnabled($shadowUserId)) {
            return [
                'status' => 'authenticated',
                'context' => $actorContext,
            ];
        }

        $challenge = $this->pendingAuthChallengeService->create(
            $actorContext,
            $authMethod,
            $area,
            $payload
        );

        return [
            'status' => 'challenge_required',
            'challenge' => $challenge,
        ];
    }

    public function verifyChallenge(string $challengeToken, string $code): ?array
    {
        $challenge = $this->pendingAuthChallengeService->getValidChallenge($challengeToken);
        if (!$challenge) {
            return null;
        }

        $shadowUserId = $this->getShadowUserId(
            (string) $challenge->getData(PendingAuthChallenge::schema_fields_ACTOR_TYPE),
            (int) $challenge->getData(PendingAuthChallenge::schema_fields_LOCAL_USER_ID)
        );

        $verified = $this->twoFactorAuthService->verify($shadowUserId, $code)
            || $this->twoFactorAuthService->verifyBackupCode($shadowUserId, $code);

        if (!$verified) {
            return null;
        }

        $context = new ActorContext(
            (string) $challenge->getData(PendingAuthChallenge::schema_fields_ACTOR_TYPE),
            (int) $challenge->getData(PendingAuthChallenge::schema_fields_LOCAL_USER_ID),
            (string) $challenge->getData(PendingAuthChallenge::schema_fields_AREA),
            $challenge->getScopes(),
            true
        );
        $payload = $challenge->getPayload();

        $this->pendingAuthChallengeService->consume($challenge);

        return [
            'context' => $context,
            'payload' => $payload,
        ];
    }

    public function getShadowUserId(string $actorType, int $actorId): int
    {
        $base = match ($actorType) {
            ActorContext::ACTOR_CUSTOMER => 100000000,
            ActorContext::ACTOR_BACKEND => 200000000,
            ActorContext::ACTOR_INTEGRATION => 300000000,
            default => 400000000,
        };

        return $base + $actorId;
    }
}
