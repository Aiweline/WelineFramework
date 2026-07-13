<?php
declare(strict_types=1);

namespace Weline\Api\Service;

use Weline\Acl\Api\Scope\ScopeCatalogInterface;
use Weline\Api\Model\ApiApp;
use Weline\Api\Model\ApiAppAuthorizationCode;
use Weline\Api\Model\ApiAppInstallation;
use Weline\Api\Model\ApiAppInstallationScope;
use Weline\Framework\Manager\ObjectManager;

class ApiAppService
{
    private const AUTHORIZATION_CODE_TTL = 600;

    public function __construct(
        private readonly ApiScopeCatalogService $scopeCatalogService,
        private readonly ApiAppSubjectProviderRegistry $subjectProviderRegistry
    ) {
    }

    protected function newAppModel(): ApiApp
    {
        return ObjectManager::getInstance(ApiApp::class, [], false);
    }

    protected function newInstallationModel(): ApiAppInstallation
    {
        return ObjectManager::getInstance(ApiAppInstallation::class, [], false);
    }

    protected function newScopeModel(): ApiAppInstallationScope
    {
        return ObjectManager::getInstance(ApiAppInstallationScope::class, [], false);
    }

    protected function newCodeModel(): ApiAppAuthorizationCode
    {
        return ObjectManager::getInstance(ApiAppAuthorizationCode::class, [], false);
    }

    public function createApp(string $name, string $redirectUri, string $status = ApiApp::STATUS_ACTIVE): array
    {
        $name = trim($name);
        $redirectUri = trim($redirectUri);
        if ($name === '') {
            throw new \InvalidArgumentException(__('应用名称不能为空'));
        }
        if ($redirectUri === '') {
            throw new \InvalidArgumentException(__('回调地址不能为空'));
        }

        $app = $this->newAppModel();
        $credentials = $app->generateClientCredentials();
        $app->setName($name)
            ->setRedirectUri($redirectUri)
            ->setStatus($status)
            ->setClientId($credentials['client_id'])
            ->setClientSecret($credentials['client_secret'])
            ->setData('raw_client_secret', $credentials['client_secret'])
            ->save();

        return [$app, $credentials['client_secret']];
    }

    public function loadActiveAppByClientId(string $clientId): ?ApiApp
    {
        $app = $this->newAppModel()->where(ApiApp::schema_fields_CLIENT_ID, trim($clientId))
            ->find()
            ->fetch();
        if (!$app->getId() || !$app->getIsEnabled()) {
            return null;
        }
        return $app;
    }

    public function validateClient(string $clientId, string $clientSecret): ?ApiApp
    {
        $app = $this->loadActiveAppByClientId($clientId);
        if (!$app || !$app->verifyClientSecret($clientSecret)) {
            return null;
        }
        return $app;
    }

    public function authorize(
        string $clientId,
        string $redirectUri,
        string $subjectType,
        string $subjectId,
        array $sourceIds
    ): ApiAppAuthorizationCode {
        $app = $this->loadActiveAppByClientId($clientId);
        if (!$app) {
            throw new \InvalidArgumentException(__('应用不存在或已禁用'));
        }

        return $this->authorizeApp($app, $redirectUri, $subjectType, $subjectId, $sourceIds);
    }

    public function authorizeApp(
        ApiApp $app,
        string $redirectUri,
        string $subjectType,
        string $subjectId,
        array $sourceIds
    ): ApiAppAuthorizationCode {
        $redirectUri = trim($redirectUri) ?: $app->getRedirectUri();
        if (!$this->redirectUriMatches($app, $redirectUri)) {
            throw new \InvalidArgumentException(__('回调地址不匹配'));
        }

        $subjectType = trim($subjectType) ?: 'global';
        $subjectId = $this->normalizeSubjectId($subjectType, $subjectId);
        if (!$this->subjectProviderRegistry->validate($subjectType, $subjectId)) {
            throw new \InvalidArgumentException(__('安装目标无效或未注册解析器'));
        }

        $scopeRows = $this->scopeCatalogService->validateRequestedSources($sourceIds);
        $installation = $this->findOrCreateInstallation($app, $subjectType, $subjectId);
        $this->replaceInstallationScopes($installation->getId(), $scopeRows);

        return $this->createAuthorizationCode($app, $installation, $redirectUri);
    }

    public function consumeAuthorizationCode(string $code, string $clientId, string $redirectUri): ?ApiAppAuthorizationCode
    {
        $authorizationCode = $this->newCodeModel()
            ->where(ApiAppAuthorizationCode::schema_fields_CODE, trim($code))
            ->find()
            ->fetch();
        if (!$authorizationCode->getId() || $authorizationCode->isConsumed() || $authorizationCode->isExpired()) {
            return null;
        }

        $app = $this->newAppModel()->load($authorizationCode->getAppId());
        if (!$app->getId() || !$app->getIsEnabled() || $app->getClientId() !== trim($clientId)) {
            return null;
        }

        $redirectUri = trim($redirectUri);
        if ($redirectUri !== '' && $authorizationCode->getRedirectUri() !== $redirectUri) {
            return null;
        }

        $authorizationCode->setConsumedAt(time())->save();
        return $authorizationCode;
    }

    public function loadInstallation(int $installationId): ?ApiAppInstallation
    {
        $installation = $this->newInstallationModel()->load($installationId);
        return $installation->getId() ? $installation : null;
    }

    public function loadApp(int $appId): ?ApiApp
    {
        $app = $this->newAppModel()->load($appId);
        return $app->getId() ? $app : null;
    }

    private function findOrCreateInstallation(ApiApp $app, string $subjectType, string $subjectId): ApiAppInstallation
    {
        $installation = $this->newInstallationModel()
            ->where(ApiAppInstallation::schema_fields_APP_ID, $app->getId())
            ->where(ApiAppInstallation::schema_fields_SUBJECT_TYPE, $subjectType)
            ->where(ApiAppInstallation::schema_fields_SUBJECT_ID, $subjectId)
            ->find()
            ->fetch();

        if (!$installation->getId()) {
            $installation->clear()
                ->setAppId($app->getId())
                ->setSubjectType($subjectType)
                ->setSubjectId($subjectId);
        }

        $installation->setStatus(ApiAppInstallation::STATUS_ACTIVE)->save();
        return $installation;
    }

    private function replaceInstallationScopes(int $installationId, array $scopeRows): void
    {
        $this->newScopeModel()->where(ApiAppInstallationScope::schema_fields_INSTALLATION_ID, $installationId)
            ->delete()
            ->fetch();

        foreach ($scopeRows as $row) {
            $this->newScopeModel()->clear()
                ->setInstallationId($installationId)
                ->setSourceId((string)$row[ScopeCatalogInterface::FIELD_SOURCE_ID])
                ->setAccessMode((string)$row[ScopeCatalogInterface::FIELD_ACCESS_MODE])
                ->setScopeGroup((string)($row[ScopeCatalogInterface::FIELD_SCOPE_GROUP] ?? ''))
                ->save();
        }
    }

    private function createAuthorizationCode(ApiApp $app, ApiAppInstallation $installation, string $redirectUri): ApiAppAuthorizationCode
    {
        $code = 'code_' . bin2hex(random_bytes(32));
        $authorizationCode = $this->newCodeModel();
        $authorizationCode->setAppId($app->getId())
            ->setInstallationId($installation->getId())
            ->setCode($code)
            ->setRedirectUri($redirectUri)
            ->setExpiresAt(time() + self::AUTHORIZATION_CODE_TTL)
            ->setConsumedAt(0)
            ->save();

        return $authorizationCode;
    }

    private function redirectUriMatches(ApiApp $app, string $redirectUri): bool
    {
        return $app->getRedirectUri() !== '' && hash_equals($app->getRedirectUri(), $redirectUri);
    }

    private function normalizeSubjectId(string $subjectType, string $subjectId): string
    {
        $subjectId = trim($subjectId);
        if ($subjectType === 'global' && $subjectId === '') {
            return '0';
        }
        return $subjectId;
    }
}
