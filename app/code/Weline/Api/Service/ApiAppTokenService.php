<?php
declare(strict_types=1);

namespace Weline\Api\Service;

use Weline\Api\Data\ApiAppActor;
use Weline\Api\Data\ApiAppTokenContext;
use Weline\Api\Model\ApiApp;
use Weline\Api\Model\ApiAppInstallation;
use Weline\Api\Model\ApiAppToken;
use Weline\Framework\Manager\ObjectManager;

class ApiAppTokenService
{
    private const ACCESS_TOKEN_TTL = 3600;
    private const REFRESH_TOKEN_TTL = 2592000;

    public function __construct(
        private readonly ApiAppService $appService,
        private readonly ApiScopeCatalogService $scopeCatalogService
    ) {
    }

    protected function newTokenModel(): ApiAppToken
    {
        return ObjectManager::getInstance(ApiAppToken::class, [], false);
    }

    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri): ?array
    {
        $app = $this->appService->validateClient($clientId, $clientSecret);
        if (!$app) {
            return null;
        }

        $authorizationCode = $this->appService->consumeAuthorizationCode($code, $clientId, $redirectUri);
        if (!$authorizationCode) {
            return null;
        }

        $installation = $this->appService->loadInstallation($authorizationCode->getInstallationId());
        if (!$installation || !$installation->isActive() || $installation->getAppId() !== $app->getId()) {
            return null;
        }

        return $this->issueTokenPair($app, $installation);
    }

    public function refresh(string $clientId, string $clientSecret, string $refreshToken): ?array
    {
        $app = $this->appService->validateClient($clientId, $clientSecret);
        if (!$app) {
            return null;
        }

        $token = $this->newTokenModel()
            ->where(ApiAppToken::schema_fields_TOKEN, trim($refreshToken))
            ->where(ApiAppToken::schema_fields_TYPE, ApiAppToken::TYPE_REFRESH_TOKEN)
            ->find()
            ->fetch();
        if (!$token->getId() || $token->isExpired() || $token->isRevoked()) {
            return null;
        }

        $installation = $this->appService->loadInstallation($token->getInstallationId());
        if (!$installation || !$installation->isActive() || $installation->getAppId() !== $app->getId()) {
            return null;
        }

        $token->setRevokedAt(time())->save();
        return $this->issueTokenPair($app, $installation);
    }

    public function revoke(string $token): bool
    {
        $tokenRecord = $this->newTokenModel()
            ->where(ApiAppToken::schema_fields_TOKEN, trim($token))
            ->find()
            ->fetch();
        if (!$tokenRecord->getId()) {
            return false;
        }
        $tokenRecord->setRevokedAt(time())->save();
        return true;
    }

    public function resolveAccessToken(string $token): ?ApiAppTokenContext
    {
        $tokenRecord = $this->newTokenModel()
            ->where(ApiAppToken::schema_fields_TOKEN, trim($token))
            ->where(ApiAppToken::schema_fields_TYPE, ApiAppToken::TYPE_ACCESS_TOKEN)
            ->find()
            ->fetch();
        if (!$tokenRecord->getId() || $tokenRecord->isExpired() || $tokenRecord->isRevoked()) {
            return null;
        }

        $installation = $this->appService->loadInstallation($tokenRecord->getInstallationId());
        if (!$installation || !$installation->isActive()) {
            return null;
        }

        $app = $this->appService->loadApp($installation->getAppId());
        if (!$app || !$app->getIsEnabled()) {
            return null;
        }

        $accessSources = $this->scopeCatalogService->getAclEntriesForInstallation($installation->getId());
        if (empty($accessSources)) {
            return null;
        }

        return new ApiAppTokenContext(new ApiAppActor($app, $installation), $accessSources);
    }

    private function issueTokenPair(ApiApp $app, ApiAppInstallation $installation): array
    {
        $this->revokeInstallationTokens($installation->getId(), [ApiAppToken::TYPE_ACCESS_TOKEN]);

        $accessToken = $this->createToken($installation->getId(), ApiAppToken::TYPE_ACCESS_TOKEN, self::ACCESS_TOKEN_TTL);
        $refreshToken = $this->createToken($installation->getId(), ApiAppToken::TYPE_REFRESH_TOKEN, self::REFRESH_TOKEN_TTL);

        return [
            'access_token' => $accessToken->getToken(),
            'refresh_token' => $refreshToken->getToken(),
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'expire_time' => $accessToken->getExpiresAt(),
            'app' => [
                'app_id' => $app->getId(),
                'client_id' => $app->getClientId(),
                'name' => $app->getName(),
            ],
            'installation' => [
                'installation_id' => $installation->getId(),
                'subject_type' => $installation->getSubjectType(),
                'subject_id' => $installation->getSubjectId(),
            ],
        ];
    }

    private function createToken(int $installationId, string $type, int $ttl): ApiAppToken
    {
        $token = 'appt_' . bin2hex(random_bytes(32));
        $tokenModel = $this->newTokenModel();
        $tokenModel->setInstallationId($installationId)
            ->setToken($token)
            ->setType($type)
            ->setExpiresAt(time() + $ttl)
            ->setRevokedAt(0)
            ->save();

        return $tokenModel;
    }

    private function revokeInstallationTokens(int $installationId, array $types = []): void
    {
        $query = $this->newTokenModel()
            ->where(ApiAppToken::schema_fields_INSTALLATION_ID, $installationId)
            ->where(ApiAppToken::schema_fields_REVOKED_AT, 0);

        if (!empty($types)) {
            $query->where(ApiAppToken::schema_fields_TYPE, $types, 'in');
        }

        $rows = $query->select()->fetchArray();
        foreach ($rows as $row) {
            $token = $this->newTokenModel()->load((int)($row[ApiAppToken::schema_fields_ID] ?? 0));
            if ($token->getId()) {
                $token->setRevokedAt(time())->save();
            }
        }
    }
}
