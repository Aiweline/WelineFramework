<?php

declare(strict_types=1);

namespace WeShop\Auth\Service;

use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Model\PendingAuthChallenge;

class PendingAuthChallengeService
{
    public function __construct(
        private readonly PendingAuthChallenge $pendingAuthChallenge
    ) {
    }

    public function create(
        ActorContext $actorContext,
        string $authMethod,
        string $area,
        array $payload = [],
        int $ttl = 300
    ): PendingAuthChallenge {
        $this->deleteExpired();

        return $this->pendingAuthChallenge->reset()
            ->clearData()
            ->setData(PendingAuthChallenge::schema_fields_CHALLENGE_TOKEN, bin2hex(random_bytes(32)))
            ->setData(PendingAuthChallenge::schema_fields_ACTOR_TYPE, $actorContext->getActorType())
            ->setData(PendingAuthChallenge::schema_fields_AUTH_METHOD, $authMethod)
            ->setData(PendingAuthChallenge::schema_fields_AREA, $area)
            ->setData(PendingAuthChallenge::schema_fields_LOCAL_USER_ID, $actorContext->getActorId())
            ->setScopes($actorContext->getScopes())
            ->setPayload($payload)
            ->setData(PendingAuthChallenge::schema_fields_EXPIRES_AT, time() + max(60, $ttl))
            ->save();
    }

    public function getValidChallenge(string $challengeToken): ?PendingAuthChallenge
    {
        $challenge = $this->pendingAuthChallenge->reset()
            ->where(PendingAuthChallenge::schema_fields_CHALLENGE_TOKEN, $challengeToken)
            ->find()
            ->fetch();

        if (!$challenge->getId() || $challenge->isExpired()) {
            return null;
        }

        return $challenge;
    }

    public function consume(PendingAuthChallenge $challenge): void
    {
        $challenge->delete();
    }

    public function deleteExpired(): void
    {
        $this->pendingAuthChallenge->reset()
            ->where(PendingAuthChallenge::schema_fields_EXPIRES_AT, time(), '<=')
            ->delete()
            ->fetch();
    }
}
