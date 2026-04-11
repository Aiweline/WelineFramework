<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Websites\Model\AiSiteBuilderSession;
use Weline\Websites\Model\AiSitePlanDraft;
use Weline\Websites\Model\AiSitePlanVersion;
use Weline\Websites\Model\DomainPool;

class PlanDraftService
{
    private const ACTIVE_DRAFT_TTL_SECONDS = 7200;

    public function __construct(
        private readonly AiSitePlanDraft $draftModel,
        private readonly AiSitePlanVersion $versionModel,
        private readonly DomainPool $domainPoolModel,
        private readonly AiSiteBuilderSession $sessionModel,
    ) {
    }

    public function generatePublicId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createDraft(
        int $adminUserId,
        string $providerCode = 'pagebuilder',
        array $payload = [],
        string $buildMode = 'pagebuilder_style'
    ): AiSitePlanDraft {
        if ($adminUserId <= 0) {
            throw new \InvalidArgumentException((string)__('admin_user_id must be greater than 0'));
        }

        $draft = clone $this->draftModel;
        $draft->clearData()->clearQuery();
        $draft->setData(AiSitePlanDraft::schema_fields_PUBLIC_ID, $this->generatePublicId());
        $draft->setData(AiSitePlanDraft::schema_fields_ADMIN_USER_ID, $adminUserId);
        $draft->setData(AiSitePlanDraft::schema_fields_PROVIDER_CODE, \trim($providerCode) !== '' ? \trim($providerCode) : 'pagebuilder');
        $draft->setData(AiSitePlanDraft::schema_fields_STATUS, AiSitePlanDraft::STATUS_DRAFT);
        $draft->setData(AiSitePlanDraft::schema_fields_CURRENT_VERSION_ID, 0);
        $draft->setData(AiSitePlanDraft::schema_fields_SELECTED_DOMAIN, '');
        $draft->setData(AiSitePlanDraft::schema_fields_SELECTED_DOMAIN_SOURCE, AiSitePlanDraft::DOMAIN_SOURCE_NONE);
        $draft->setData(AiSitePlanDraft::schema_fields_SELECTED_POOL_ID, 0);
        $draft->setData(AiSitePlanDraft::schema_fields_REGISTRAR_ACCOUNT_ID, 0);
        $draft->setData(AiSitePlanDraft::schema_fields_BUILD_MODE, $this->normalizeBuildMode($buildMode));
        $draft->setPayloadArray($payload);
        $draft->save();

        return $draft;
    }

    public function loadByPublicId(string $publicId, int $adminUserId): ?AiSitePlanDraft
    {
        $publicId = \trim($publicId);
        if ($publicId === '' || $adminUserId <= 0) {
            return null;
        }

        $draft = clone $this->draftModel;
        $draft->clearData()->clearQuery()
            ->where(AiSitePlanDraft::schema_fields_PUBLIC_ID, $publicId)
            ->where(AiSitePlanDraft::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->find()
            ->fetch();

        return $draft->getId() > 0 ? $draft : null;
    }

    public function loadById(int $draftId, int $adminUserId): ?AiSitePlanDraft
    {
        if ($draftId <= 0 || $adminUserId <= 0) {
            return null;
        }

        $draft = clone $this->draftModel;
        $draft->clearData()->clearQuery()
            ->where(AiSitePlanDraft::schema_fields_ID, $draftId)
            ->where(AiSitePlanDraft::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->find()
            ->fetch();

        return $draft->getId() > 0 ? $draft : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function savePayload(int $draftId, int $adminUserId, array $payload): bool
    {
        $draft = $this->loadById($draftId, $adminUserId);
        if ($draft === null) {
            return false;
        }

        $draft->setPayloadArray($payload);
        $draft->save();

        return true;
    }

    /**
     * @param array<string, mixed> $payloadPatch
     */
    public function mergePayload(int $draftId, int $adminUserId, array $payloadPatch): bool
    {
        $draft = $this->loadById($draftId, $adminUserId);
        if ($draft === null) {
            return false;
        }

        $draft->setPayloadArray(\array_replace($draft->getPayloadArray(), $payloadPatch));
        $draft->save();

        return true;
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function appendPlanVersion(
        int $draftId,
        int $adminUserId,
        array $plan,
        string $sourceType = 'generate',
        string $sourceMessage = ''
    ): ?AiSitePlanVersion {
        $draft = $this->loadById($draftId, $adminUserId);
        if ($draft === null) {
            return null;
        }

        $version = clone $this->versionModel;
        $version->clearData()->clearQuery();
        $version->setData(AiSitePlanVersion::schema_fields_DRAFT_ID, $draft->getId());
        $version->setData(AiSitePlanVersion::schema_fields_VERSION_NO, $this->resolveNextVersionNo($draft->getId()));
        $version->setData(AiSitePlanVersion::schema_fields_SOURCE_TYPE, \trim($sourceType) !== '' ? \trim($sourceType) : 'generate');
        $version->setData(AiSitePlanVersion::schema_fields_SOURCE_MESSAGE, \trim($sourceMessage));
        $version->setPlanArray($plan);
        $version->save();

        $payload = $draft->getPayloadArray();
        $payload['current_plan'] = $plan;
        $payload['current_plan_version_id'] = $version->getId();
        $payload['current_plan_version_no'] = $version->getVersionNo();
        $payload['plan_versions_count'] = $version->getVersionNo();
        $draft->setPayloadArray($payload);
        $draft->setData(AiSitePlanDraft::schema_fields_CURRENT_VERSION_ID, $version->getId());
        if ($draft->getBuildMode() !== $this->normalizeBuildMode((string)($plan['build_mode'] ?? ''))) {
            $draft->setData(AiSitePlanDraft::schema_fields_BUILD_MODE, $this->normalizeBuildMode((string)($plan['build_mode'] ?? '')));
        }
        $draft->save();

        return $version;
    }

    public function confirmDraft(int $draftId, int $adminUserId, int $versionId = 0): bool
    {
        $draft = $this->loadById($draftId, $adminUserId);
        if ($draft === null) {
            return false;
        }

        $resolvedVersionId = $versionId > 0 ? $versionId : $draft->getCurrentVersionId();
        if ($resolvedVersionId <= 0) {
            return false;
        }

        $payload = $draft->getPayloadArray();
        $payload['confirmed'] = 1;
        $payload['confirmed_version_id'] = $resolvedVersionId;
        $draft->setPayloadArray($payload);
        $draft->setData(AiSitePlanDraft::schema_fields_CURRENT_VERSION_ID, $resolvedVersionId);
        $draft->setData(AiSitePlanDraft::schema_fields_STATUS, AiSitePlanDraft::STATUS_CONFIRMED);
        $draft->save();

        return true;
    }

    public function markConverted(int $draftId, int $adminUserId): bool
    {
        $draft = $this->loadById($draftId, $adminUserId);
        if ($draft === null) {
            return false;
        }

        $draft->setData(AiSitePlanDraft::schema_fields_STATUS, AiSitePlanDraft::STATUS_CONVERTED);
        $draft->save();

        return true;
    }

    /**
     * @param array<string, mixed> $payloadPatch
     */
    public function bindDomainSelection(
        int $draftId,
        int $adminUserId,
        string $domain,
        string $domainSource,
        int $poolId = 0,
        int $registrarAccountId = 0,
        array $payloadPatch = []
    ): bool {
        $draft = $this->loadById($draftId, $adminUserId);
        if ($draft === null) {
            return false;
        }

        $draft->setData(AiSitePlanDraft::schema_fields_SELECTED_DOMAIN, \strtolower(\trim($domain)));
        $draft->setData(AiSitePlanDraft::schema_fields_SELECTED_DOMAIN_SOURCE, $this->normalizeDomainSource($domainSource));
        $draft->setData(AiSitePlanDraft::schema_fields_SELECTED_POOL_ID, \max(0, $poolId));
        $draft->setData(AiSitePlanDraft::schema_fields_REGISTRAR_ACCOUNT_ID, \max(0, $registrarAccountId));

        $payload = \array_replace($draft->getPayloadArray(), $payloadPatch);
        $payload['selected_domain'] = \strtolower(\trim($domain));
        $payload['selected_domain_source'] = $this->normalizeDomainSource($domainSource);
        $payload['selected_pool_id'] = \max(0, $poolId);
        $payload['registrar_account_id'] = \max(0, $registrarAccountId);
        $draft->setPayloadArray($payload);
        $draft->save();

        return true;
    }

    /**
     * @return list<array{
     *   version_id:int,
     *   version_no:int,
     *   source_type:string,
     *   source_message:string,
     *   plan:array<string, mixed>,
     *   create_time:string
     * }>
     */
    public function listVersions(int $draftId, int $adminUserId): array
    {
        if ($this->loadById($draftId, $adminUserId) === null) {
            return [];
        }

        $version = clone $this->versionModel;
        $rows = $version->clearData()->clearQuery()
            ->where(AiSitePlanVersion::schema_fields_DRAFT_ID, $draftId)
            ->order(AiSitePlanVersion::schema_fields_VERSION_NO, 'ASC')
            ->select()
            ->fetchArray();

        if (!\is_array($rows)) {
            return [];
        }

        $versions = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $item = clone $this->versionModel;
            $item->setData($row);
            $versions[] = [
                'version_id' => $item->getId(),
                'version_no' => $item->getVersionNo(),
                'source_type' => $item->getSourceType(),
                'source_message' => $item->getSourceMessage(),
                'plan' => $item->getPlanArray(),
                'create_time' => (string)($row[AiSitePlanVersion::schema_fields_CREATE_TIME] ?? ''),
            ];
        }

        return $versions;
    }

    /**
     * @return list<array{
     *   pool_id:int,
     *   domain:string,
     *   root_domain:string,
     *   description:string,
     *   site_ready:int,
     *   site_created:int,
     *   is_selected:bool,
     *   is_reserved:bool
     * }>
     */
    public function listAvailableLocalPoolDomains(
        int $draftId,
        int $adminUserId,
        string $search = '',
        int $limit = 50
    ): array {
        $draft = $this->loadById($draftId, $adminUserId);
        if ($draft === null) {
            return [];
        }

        $search = \trim($search);
        $limit = \min(200, \max(1, $limit));
        $selectedPoolId = $draft->getSelectedPoolId();
        $reservedPoolIds = $this->collectReservedPoolIds($draft->getId());

        $pool = clone $this->domainPoolModel;
        $query = $pool->clearData()->clearQuery()
            ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
            ->where(DomainPool::schema_fields_SITE_READY, 1)
            ->where(DomainPool::schema_fields_SITE_CREATED, 0);
        if ($search !== '') {
            $query->where(DomainPool::schema_fields_DOMAIN, '%' . $search . '%', 'LIKE');
        }

        $rows = $query
            ->order(DomainPool::schema_fields_ROOT_DOMAIN, 'ASC')
            ->order(DomainPool::schema_fields_DOMAIN, 'ASC')
            ->limit($limit * 2)
            ->select()
            ->fetchArray();

        if (!\is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $poolId = (int)($row[DomainPool::schema_fields_ID] ?? 0);
            if ($poolId <= 0) {
                continue;
            }
            $isSelected = $selectedPoolId > 0 && $poolId === $selectedPoolId;
            $isReserved = !$isSelected && isset($reservedPoolIds[$poolId]);
            if ($isReserved) {
                continue;
            }
            $items[] = [
                'pool_id' => $poolId,
                'domain' => (string)($row[DomainPool::schema_fields_DOMAIN] ?? ''),
                'root_domain' => (string)($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? ''),
                'description' => (string)($row[DomainPool::schema_fields_DESCRIPTION] ?? ''),
                'site_ready' => (int)($row[DomainPool::schema_fields_SITE_READY] ?? 0),
                'site_created' => (int)($row[DomainPool::schema_fields_SITE_CREATED] ?? 0),
                'is_selected' => $isSelected,
                'is_reserved' => $isReserved,
            ];
            if (\count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function reserveLocalPoolDomain(
        int $draftId,
        int $adminUserId,
        int $poolId,
        int $registrarAccountId = 0
    ): array {
        $draft = $this->loadById($draftId, $adminUserId);
        if ($draft === null) {
            return ['success' => false, 'message' => (string)__('Plan draft not found')];
        }
        if ($poolId <= 0) {
            return ['success' => false, 'message' => (string)__('Invalid local pool id')];
        }

        $available = $this->listAvailableLocalPoolDomains($draft->getId(), $adminUserId, '', 500);
        $selected = null;
        foreach ($available as $item) {
            if ((int)($item['pool_id'] ?? 0) === $poolId) {
                $selected = $item;
                break;
            }
        }

        if ($selected === null) {
            return ['success' => false, 'message' => (string)__('The selected local pool domain is no longer available')];
        }

        $this->bindDomainSelection(
            $draft->getId(),
            $adminUserId,
            (string)($selected['domain'] ?? ''),
            AiSitePlanDraft::DOMAIN_SOURCE_LOCAL_POOL,
            $poolId,
            $registrarAccountId,
            ['site_ready' => 1]
        );

        return [
            'success' => true,
            'message' => (string)__('Reserved local domain: %{domain}', ['domain' => (string)($selected['domain'] ?? '')]),
            'domain' => (string)($selected['domain'] ?? ''),
            'pool_id' => $poolId,
        ];
    }

    /**
     * @return array<int, bool>
     */
    public function collectReservedPoolIds(int $excludeDraftId = 0): array
    {
        $reserved = [];
        $activeCutoff = \date('Y-m-d H:i:s', \time() - self::ACTIVE_DRAFT_TTL_SECONDS);

        $draft = clone $this->draftModel;
        $draftRows = $draft->clearData()->clearQuery()
            ->where(AiSitePlanDraft::schema_fields_STATUS, AiSitePlanDraft::STATUS_CANCELLED, '!=')
            ->where(AiSitePlanDraft::schema_fields_STATUS, AiSitePlanDraft::STATUS_CONVERTED, '!=')
            ->where(AiSitePlanDraft::schema_fields_UPDATE_TIME, $activeCutoff, '>')
            ->select()
            ->fetchArray();

        if (\is_array($draftRows)) {
            foreach ($draftRows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $draftId = (int)($row[AiSitePlanDraft::schema_fields_ID] ?? 0);
                if ($excludeDraftId > 0 && $draftId === $excludeDraftId) {
                    continue;
                }
                $poolId = (int)($row[AiSitePlanDraft::schema_fields_SELECTED_POOL_ID] ?? 0);
                $source = (string)($row[AiSitePlanDraft::schema_fields_SELECTED_DOMAIN_SOURCE] ?? '');
                if ($poolId > 0 && $source === AiSitePlanDraft::DOMAIN_SOURCE_LOCAL_POOL) {
                    $reserved[$poolId] = true;
                }
            }
        }

        $session = clone $this->sessionModel;
        $sessionRows = $session->clearData()->clearQuery()
            ->where(AiSiteBuilderSession::schema_fields_PROVIDER_CODE, 'pagebuilder')
            ->where(AiSiteBuilderSession::schema_fields_UPDATE_TIME, $activeCutoff, '>')
            ->select()
            ->fetchArray();
        if (\is_array($sessionRows)) {
            foreach ($sessionRows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $scopeRaw = $row[AiSiteBuilderSession::schema_fields_SCOPE_JSON] ?? '{}';
                if (!\is_string($scopeRaw) || $scopeRaw === '') {
                    continue;
                }
                try {
                    $scope = \json_decode($scopeRaw, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $scope = [];
                }
                if (!\is_array($scope)) {
                    continue;
                }
                $poolId = (int)($scope['selected_pool_id'] ?? 0);
                $source = (string)($scope['selected_domain_source'] ?? '');
                if ($poolId > 0 && $source === AiSitePlanDraft::DOMAIN_SOURCE_LOCAL_POOL) {
                    $reserved[$poolId] = true;
                }
            }
        }

        return $reserved;
    }

    /**
     * @return array{
     *   draft_public_id:string,
     *   provider_code:string,
     *   status:string,
     *   current_version_id:int,
     *   selected_domain:string,
     *   selected_domain_source:string,
     *   selected_pool_id:int,
     *   registrar_account_id:int,
     *   build_mode:string,
     *   payload:array<string, mixed>,
     *   versions:list<array<string, mixed>>
     * }|null
     */
    public function buildDraftView(string $publicId, int $adminUserId): ?array
    {
        $draft = $this->loadByPublicId($publicId, $adminUserId);
        if ($draft === null) {
            return null;
        }

        return [
            'draft_public_id' => $draft->getPublicId(),
            'provider_code' => $draft->getProviderCode(),
            'status' => $draft->getStatus(),
            'current_version_id' => $draft->getCurrentVersionId(),
            'selected_domain' => $draft->getSelectedDomain(),
            'selected_domain_source' => $draft->getSelectedDomainSource(),
            'selected_pool_id' => $draft->getSelectedPoolId(),
            'registrar_account_id' => $draft->getRegistrarAccountId(),
            'build_mode' => $draft->getBuildMode(),
            'payload' => $draft->getPayloadArray(),
            'versions' => $this->listVersions($draft->getId(), $adminUserId),
        ];
    }

    private function resolveNextVersionNo(int $draftId): int
    {
        $version = clone $this->versionModel;
        $version->clearData()->clearQuery()
            ->where(AiSitePlanVersion::schema_fields_DRAFT_ID, $draftId)
            ->order(AiSitePlanVersion::schema_fields_VERSION_NO, 'DESC')
            ->limit(1)
            ->find()
            ->fetch();

        return $version->getId() > 0 ? ($version->getVersionNo() + 1) : 1;
    }

    private function normalizeBuildMode(string $buildMode): string
    {
        $buildMode = \trim($buildMode);

        return $buildMode === 'pagebuilder_html' ? 'pagebuilder_html' : 'pagebuilder_style';
    }

    private function normalizeDomainSource(string $domainSource): string
    {
        $domainSource = \trim($domainSource);

        return match ($domainSource) {
            AiSitePlanDraft::DOMAIN_SOURCE_RECOMMENDED,
            AiSitePlanDraft::DOMAIN_SOURCE_MANUAL,
            AiSitePlanDraft::DOMAIN_SOURCE_LOCAL_POOL => $domainSource,
            default => AiSitePlanDraft::DOMAIN_SOURCE_NONE,
        };
    }
}
