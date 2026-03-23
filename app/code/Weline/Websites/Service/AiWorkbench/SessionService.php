<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Websites\Model\AiSiteBuilderSession;

class SessionService
{
    public function __construct(
        private readonly AiSiteBuilderSession $sessionModel,
    ) {
    }

    public function generatePublicId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $providerState
     */
    public function createSession(
        string $providerCode,
        int $adminUserId,
        array $scope = [],
        array $providerState = [],
        string $initialStage = AiSiteBuilderSession::STAGE_BRIEF
    ): AiSiteBuilderSession
    {
        $providerCode = \trim($providerCode);
        if ($providerCode === '') {
            throw new \InvalidArgumentException((string)__('provider_code 不能为空'));
        }
        if ($adminUserId <= 0) {
            throw new \InvalidArgumentException((string)__('admin_user_id 必须大于 0'));
        }

        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery();
        $session->setData(AiSiteBuilderSession::schema_fields_PUBLIC_ID, $this->generatePublicId());
        $session->setData(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId);
        $session->setData(AiSiteBuilderSession::schema_fields_PROVIDER_CODE, $providerCode);
        $session->setData(
            AiSiteBuilderSession::schema_fields_CURRENT_STAGE,
            \trim($initialStage) !== '' ? \trim($initialStage) : AiSiteBuilderSession::STAGE_BRIEF
        );
        $session->setData(AiSiteBuilderSession::schema_fields_WEBSITE_ID, 0);
        $session->setData(AiSiteBuilderSession::schema_fields_SELECTED_DOMAIN, '');
        $session->setData(AiSiteBuilderSession::schema_fields_REGISTRAR_ACCOUNT_ID, 0);
        $session->setData(AiSiteBuilderSession::schema_fields_PREVIEW_URL, '');
        $session->setScopeArray($scope);
        $session->setProviderStateArray($providerState);
        $session->save();

        return $session;
    }

    public function loadByPublicId(string $publicId, int $adminUserId): ?AiSiteBuilderSession
    {
        $publicId = \trim($publicId);
        if ($publicId === '' || $adminUserId <= 0) {
            return null;
        }

        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery()
            ->where(AiSiteBuilderSession::schema_fields_PUBLIC_ID, $publicId)
            ->where(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->find()
            ->fetch();

        return $session->getId() > 0 ? $session : null;
    }

    public function loadById(int $sessionId, int $adminUserId): ?AiSiteBuilderSession
    {
        if ($sessionId <= 0 || $adminUserId <= 0) {
            return null;
        }

        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery()
            ->where(AiSiteBuilderSession::schema_fields_ID, $sessionId)
            ->where(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->find()
            ->fetch();

        return $session->getId() > 0 ? $session : null;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function saveScope(int $sessionId, int $adminUserId, array $scope): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setScopeArray($scope);
        $session->save();

        return true;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function replaceScope(int $sessionId, int $adminUserId, array $scope): bool
    {
        return $this->saveScope($sessionId, $adminUserId, $scope);
    }

    /**
     * @param array<string, mixed> $scopePatch
     */
    public function mergeScope(int $sessionId, int $adminUserId, array $scopePatch): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $mergedScope = \array_replace($session->getScopeArray(), $scopePatch);
        $session->setScopeArray($mergedScope);
        $session->save();

        return true;
    }

    /**
     * @param array<string, mixed> $providerState
     */
    public function saveProviderState(int $sessionId, int $adminUserId, array $providerState): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setProviderStateArray($providerState);
        $session->save();

        return true;
    }

    public function setStage(int $sessionId, int $adminUserId, string $stage): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setData(AiSiteBuilderSession::schema_fields_CURRENT_STAGE, \trim($stage));
        $session->save();

        return true;
    }

    public function bindWebsite(int $sessionId, int $adminUserId, int $websiteId): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setData(AiSiteBuilderSession::schema_fields_WEBSITE_ID, \max(0, $websiteId));
        $session->save();

        return true;
    }

    public function bindDomain(int $sessionId, int $adminUserId, string $domain, int $registrarAccountId): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setData(AiSiteBuilderSession::schema_fields_SELECTED_DOMAIN, \strtolower(\trim($domain)));
        $session->setData(AiSiteBuilderSession::schema_fields_REGISTRAR_ACCOUNT_ID, \max(0, $registrarAccountId));
        $session->save();

        return true;
    }

    public function setPreviewUrl(int $sessionId, int $adminUserId, string $previewUrl): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setData(AiSiteBuilderSession::schema_fields_PREVIEW_URL, \trim($previewUrl));
        $session->save();

        return true;
    }

    /**
     * @return list<array{
     *   session_id:int,
     *   public_id:string,
     *   provider_code:string,
     *   current_stage:string,
     *   website_id:int,
     *   selected_domain:string,
     *   registrar_account_id:int,
     *   preview_url:string,
     *   update_time:string
     * }>
     */
    public function listRecentSessionsForAdmin(int $adminUserId, int $limit = 20): array
    {
        if ($adminUserId <= 0) {
            return [];
        }

        $limit = \min(50, \max(1, $limit));
        $session = clone $this->sessionModel;
        $rows = $session->clearData()->clearQuery()
            ->where(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->order(AiSiteBuilderSession::schema_fields_UPDATE_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        if (!\is_array($rows)) {
            return [];
        }

        $sessions = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $item = clone $this->sessionModel;
            $item->setData($row);
            if ($item->getId() <= 0) {
                continue;
            }

            $sessions[] = [
                'session_id' => $item->getId(),
                'public_id' => $item->getPublicId(),
                'provider_code' => $item->getProviderCode(),
                'current_stage' => $item->getCurrentStage(),
                'website_id' => $item->getWebsiteId(),
                'selected_domain' => $item->getSelectedDomain(),
                'registrar_account_id' => $item->getRegistrarAccountId(),
                'preview_url' => $item->getPreviewUrl(),
                'update_time' => (string)($row[AiSiteBuilderSession::schema_fields_UPDATE_TIME] ?? ''),
            ];
        }

        return $sessions;
    }
}
