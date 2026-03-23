<?php

declare(strict_types=1);

namespace WeShop\Auth\Service;

use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Model\AuthToken;

class WeShopAuthTokenService
{
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 2592000;

    public function __construct(
        private readonly AuthToken $authToken
    ) {
    }

    public function createTokenPair(
        ActorContext $actorContext,
        int $accessTtl = self::ACCESS_TTL,
        int $refreshTtl = self::REFRESH_TTL
    ): array {
        $this->revokeActorTokens($actorContext->getActorType(), $actorContext->getActorId());

        $accessToken = $this->createTokenRecord($actorContext, AuthToken::TYPE_ACCESS, $accessTtl);
        $refreshToken = $this->createTokenRecord($actorContext, AuthToken::TYPE_REFRESH, $refreshTtl);

        return [
            'access_token' => (string) $accessToken->getData(AuthToken::schema_fields_TOKEN),
            'refresh_token' => (string) $refreshToken->getData(AuthToken::schema_fields_TOKEN),
            'expires_at' => (int) $accessToken->getData(AuthToken::schema_fields_EXPIRES_AT),
        ];
    }

    public function resolveAccessToken(string $token): ?ActorContext
    {
        $record = $this->getValidTokenRecord($token, AuthToken::TYPE_ACCESS);
        if (!$record) {
            return null;
        }

        return new ActorContext(
            (string) $record->getData(AuthToken::schema_fields_ACTOR_TYPE),
            (int) $record->getData(AuthToken::schema_fields_ACTOR_ID),
            (string) ($record->getData(AuthToken::schema_fields_AREA) ?: 'api'),
            $record->getScopes(),
            (bool) $record->getData(AuthToken::schema_fields_IS_2FA_VERIFIED)
        );
    }

    public function refresh(string $refreshToken): ?array
    {
        $record = $this->getValidTokenRecord($refreshToken, AuthToken::TYPE_REFRESH);
        if (!$record) {
            return null;
        }

        $context = new ActorContext(
            (string) $record->getData(AuthToken::schema_fields_ACTOR_TYPE),
            (int) $record->getData(AuthToken::schema_fields_ACTOR_ID),
            (string) ($record->getData(AuthToken::schema_fields_AREA) ?: 'api'),
            $record->getScopes(),
            (bool) $record->getData(AuthToken::schema_fields_IS_2FA_VERIFIED)
        );

        return $this->createTokenPair($context);
    }

    public function revoke(string $token): bool
    {
        $record = $this->authToken->reset()
            ->where(AuthToken::schema_fields_TOKEN, $token)
            ->find()
            ->fetch();
        if (!$record->getId()) {
            return false;
        }

        $this->revokeActorTokens(
            (string) $record->getData(AuthToken::schema_fields_ACTOR_TYPE),
            (int) $record->getData(AuthToken::schema_fields_ACTOR_ID)
        );

        return true;
    }

    public function revokeActorTokens(string $actorType, int $actorId): void
    {
        $this->authToken->reset()
            ->where(AuthToken::schema_fields_ACTOR_TYPE, $actorType)
            ->where(AuthToken::schema_fields_ACTOR_ID, $actorId)
            ->delete()
            ->fetch();
    }

    private function createTokenRecord(ActorContext $actorContext, string $tokenType, int $ttl): AuthToken
    {
        $record = $this->authToken->reset()
            ->clearData()
            ->setData(AuthToken::schema_fields_ACTOR_TYPE, $actorContext->getActorType())
            ->setData(AuthToken::schema_fields_ACTOR_ID, $actorContext->getActorId())
            ->setData(AuthToken::schema_fields_AREA, $actorContext->getArea())
            ->setData(AuthToken::schema_fields_TOKEN_TYPE, $tokenType)
            ->setData(AuthToken::schema_fields_TOKEN, bin2hex(random_bytes(32)))
            ->setScopes($actorContext->getScopes())
            ->setData(AuthToken::schema_fields_IS_2FA_VERIFIED, $actorContext->is2faVerified() ? 1 : 0)
            ->setData(AuthToken::schema_fields_EXPIRES_AT, time() + max(60, $ttl));

        $record->save();

        return $record;
    }

    private function getValidTokenRecord(string $token, string $tokenType): ?AuthToken
    {
        $record = $this->authToken->reset()
            ->where(AuthToken::schema_fields_TOKEN, $token)
            ->where(AuthToken::schema_fields_TOKEN_TYPE, $tokenType)
            ->find()
            ->fetch();

        if (!$record->getId() || $record->isExpired() || $record->isRevoked()) {
            return null;
        }

        return $record;
    }
}
