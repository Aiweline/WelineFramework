<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 建站工作台会话：创建、读写 scope、事件流、站点/主题/发布状态
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionEvent;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Connection\Adapter\Pgsql\SchemaConfig;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\LocalWelineHostsSyncService;
use Weline\Websites\Service\LocalWelineWildcardCertificateService;

class AiSiteAgentSessionService
{
    /** @var list<string> */
    private const COMMON_STAGE_SCOPE_KEYS = [
        '_artifact_refs',
        '_workspace_stream_lease',
        'active_operation',
        'active_operations',
        'asset_block_cache',
        'asset_manifest',
        'asset_manifest_hash',
        'block_patch_history',
        'brief_description',
        'build_contracts',
        'build_queue_info',
        'build_summary',
        'build_workbench',
        'can_publish',
        'default_language',
        'default_locale',
        'design_direction',
        'design_direction_code',
        'design_direction_custom_id',
        'design_direction_hash',
        'design_direction_locked',
        'design_direction_match_reason',
        'design_direction_mode',
        'design_direction_snapshot',
        'design_direction_version',
        'design_tokens',
        'language_contract',
        'virtual_page_index',
        'theme_css_ref',
        'draft_website_id',
        'events',
        'fake_mode',
        'latest_build_failed',
        'latest_build_failure',
        'locales',
        'materialized_pages_by_type',
        'page_type_layouts',
        'page_types',
        'page_types_user_customized',
        'pagebuilder_pages_by_type',
        'pending_generation_page_types',
        'plan_locale',
        'plan_queue_info',
        'preferred_registrar_account_id',
        'preview_full_url',
        'preview_page_id',
        'preview_page_type',
        'pre_publish_visual_urls',
        'next_stage_blocked_by_ai_failures',
        'plan_generation_last_error',
        'publish_blocked_by_latest_ai_failure',
        'publish_blocked_reason',
        'publish_verification',
        'publish_status',
        'recommended_domain_list',
        'recommended_pages',
        'recommended_registrar_label',
        'registrar_account_id',
        'qa_report_contract',
        'render_data_contract',
        'reference_image_insights',
        'reference_image_insights_signature',
        'reference_images',
        'retryable_ai_failure_count',
        'retryable_ai_failures',
        'partial_retry_required',
        'page_route_contract',
        'selected_domain',
        'selected_skill_codes',
        'selected_website_id',
        'site_profile_manual',
        'site_ready',
        'source_truth_contract',
        'source_truth_contract_hash',
        'site_tagline',
        'site_title',
        'target_domain',
        'top_logs',
        'user_description',
        'virtual_pages_by_type',
        'virtual_theme_id',
        'visual_edit_url',
        'visual_preview_url',
        'website_id',
        'website_profile',
        'workspace_status',
        'workspace_track',
        'verified_assets',
    ];

    /** @var list<string> */
    private const PLAN_STAGE_SCOPE_KEYS = [
        'plan_confirmed',
        'plan_confirmed_at',
        'plan_ai_generated',
        'plan_generation_progress',
        'plan_generated_at',
        'plan_generated_locale',
        'plan_generated_page_types',
        'plan_generated_source_signature',
        'plan_json',
        'plan_markdown',
        'plan_structured',
        'plan_workbench',
        'page_route_contract',
        'stage1_contract',
        'stage1_validation_report',
        'stage1_first_pass',
        'stage1_generation_attempts',
        'shared_components',
        'shared_prompt_context',
        'theme_context_snapshot',
        '_plan_generation_checkpoint',
        '_plan_sse_request',
    ];

    /** @var list<string> */
    private const VISUAL_EDIT_STAGE_SCOPE_KEYS = [
        'build_contracts',
        'build_plan_confirmed',
        'build_plan_confirmed_at',
        'build_plan_v2',
        'build_plan_v2_validation',
        'build_workbench',
        'component_refinements',
        'plan_confirmed',
        'plan_confirmed_at',
        'plan_markdown',
        'plan_projection',
        'plan_workbench',
        'section_refinements',
        'shared_component_refinements',
        'shared_components',
        'content_manifest',
        'asset_block_cache',
        'asset_manifest',
        'asset_image_generation_failures',
        'has_build_plan_v2',
        'qa_report_contract',
        'render_data_contract',
        'verified_assets',
        '_ai_generated_shared_components',
        '_queue_force_build',
        // 强行契约：build 强制重建标记必须随 scope 一同 load/save，否则
        // isGeneratedArtifactAvailableForTask 会因看不到 active=1 而把 task 当做已完成，导致无任何
        // 实际渲染的「Page layout has no rendered sections」假性失败。
        '_build_regeneration',
    ];

    public function __construct(
        private readonly AiSiteAgentSession $sessionModel,
        private readonly AiSiteAgentSessionEvent $eventModel,
        private readonly ?LocalWelineHostsSyncService $localWelineHostsSyncService = null,
        private readonly ?LocalWelineWildcardCertificateService $localWelineWildcardCertificateService = null,
        private readonly ?AiSiteAgentSessionArtifactService $artifactService = null,
    ) {
    }

    public function generatePublicId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    /**
     * @param array<string, mixed> $initialScope
     */
    public function createSession(int $adminUserId, array $initialScope = []): AiSiteAgentSession
    {
        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $this->generatePublicId());
        $session->setData(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $adminUserId);
        $session->setData(AiSiteAgentSession::schema_fields_WEBSITE_ID, 0);
        $session->setData(AiSiteAgentSession::schema_fields_VIRTUAL_THEME_ID, 0);
        $session->setData(AiSiteAgentSession::schema_fields_STAGE, AiSiteAgentSession::STAGE_BRIEF);
        $session->setData(AiSiteAgentSession::schema_fields_PUBLISH_STATUS, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);
        $session->setScopeArray($initialScope);
        try {
            $session->save();
        } catch (\Throwable $e) {
            if (!$this->isPgsqlAiSessionPrimaryKeySerialMissing($e)) {
                throw $e;
            }
            echo "[DEBUG] 检测到序列问题，开始修复...\n";
            $this->repairPgsqlAiSessionPrimaryKeySerial();
            echo "[DEBUG] 序列修复完成，重新创建会话对象并重试保存...\n";

            // 重新创建一个全新的会话对象
            $session = clone $this->sessionModel;
            $session->clearData()->clearQuery();
            $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $this->generatePublicId());
            $session->setData(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $adminUserId);
            $session->setData(AiSiteAgentSession::schema_fields_WEBSITE_ID, 0);
            $session->setData(AiSiteAgentSession::schema_fields_VIRTUAL_THEME_ID, 0);
            $session->setData(AiSiteAgentSession::schema_fields_STAGE, AiSiteAgentSession::STAGE_BRIEF);
            $session->setData(AiSiteAgentSession::schema_fields_PUBLISH_STATUS, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);
            $session->setScopeArray($initialScope);
            $session->save();
        }
        return $session;
    }

    public function loadByPublicId(string $publicId, int $forAdminUserId): ?AiSiteAgentSession
    {
        $publicId = \trim($publicId);
        if ($publicId === '' || $forAdminUserId <= 0) {
            return null;
        }
        $pgsqlSession = $this->loadSessionMetadataFromPgsql($publicId, $forAdminUserId, true);
        if ($pgsqlSession !== false) {
            return $pgsqlSession;
        }
        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery()
            ->where(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId)
            ->where(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $forAdminUserId)
            ->find()
            ->fetch();
        return $session->getId() > 0 ? $session : null;
    }

    public function loadById(int $sessionId, int $forAdminUserId): ?AiSiteAgentSession
    {
        if ($sessionId <= 0 || $forAdminUserId <= 0) {
            return null;
        }
        $pgsqlSession = $this->loadSessionMetadataFromPgsql($sessionId, $forAdminUserId, false);
        if ($pgsqlSession !== false) {
            return $pgsqlSession;
        }
        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery()
            ->where(AiSiteAgentSession::schema_fields_ID, $sessionId)
            ->where(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $forAdminUserId)
            ->find()
            ->fetch();

        return $session->getId() > 0 ? $session : null;
    }

    public function deleteSession(int $sessionId, int $forAdminUserId): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }

        $eventModel = clone $this->eventModel;
        $eventModel->clearData()->clearQuery()
            ->where(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId)
            ->delete()
            ->fetch();

        // AbstractModel::delete() 内部已执行 fetch()；切勿再链式 ->fetch()，否则返回的是模型对象，(bool)$model 恒为 true。
        // QueryAst 对 DELETE 的 fetch 结果为 rowCount>0 时的 boolean，应以此为准。
        $session->delete();
        if ($session->getQueryData() === true) {
            return true;
        }

        return $this->loadById($sessionId, $forAdminUserId) === null;
    }

    /**
     * 读取 ScopeManifest：不 hydrate 大 artifact，并执行脱水索引。
     *
     * @return array<string, mixed>
     */
    public function loadScopeManifest(AiSiteAgentSession $session): array
    {
        $scope = $this->loadScopeFragmentFromPgsql($session, '');
        if ($scope === null) {
            $scope = $this->decodeSessionScopeData($session);
        }

        return $this->manifestPolicy()->dehydrateScopePaths($scope);
    }

    /**
     * 局部 patch manifest 小字段，写回前强制脱水。
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function patchScopeManifest(int $sessionId, int $forAdminUserId, array $patch): array
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return [];
        }

        $manifest = $this->loadScopeManifest($session);
        $merged = $this->mergeScopePatch($manifest, $patch);
        $merged = $this->manifestPolicy()->dehydrateScopePaths($merged);
        $this->replaceScope($sessionId, $forAdminUserId, $merged);

        return $merged;
    }

    private function allowFullScopeHydrate(): bool
    {
        $raw = \getenv('ai_site.scope.allow_full_hydrate');
        if ($raw === false || $raw === '') {
            return false;
        }

        return \filter_var($raw, \FILTER_VALIDATE_BOOLEAN);
    }

    private function manifestPolicy(): AiSiteScopeManifestPolicy
    {
        return ObjectManager::getInstance(AiSiteScopeManifestPolicy::class);
    }

    /**
     * @param list<string> $artifactKeys
     * @return array<string, mixed>
     */
    public function loadScope(AiSiteAgentSession $session, array $artifactKeys = []): array
    {
        $scope = $this->loadScopeFragmentFromPgsql($session, '');
        if ($scope !== null) {
            return $this->artifactStorage()->hydrateScope(
                (int)$session->getId(),
                $scope,
                $artifactKeys
            );
        }

        return $this->artifactStorage()->hydrateScope(
            (int)$session->getId(),
            $this->decodeSessionScopeData($session),
            $artifactKeys
        );
    }

    /** @var list<string> */
    public const BUILD_OPERATION_ARTIFACT_KEYS = [
        'plan_json',
        'build_plan_v2',
        'plan_projection',
        'content_manifest',
        'build_workbench',
        'build_contracts',
        'render_data_contract',
        'task_results',
        'qa_report',
        'repair_patch',
    ];

    /**
     * Build/publish queue paths must hydrate confirmed build_plan_v2; manifest-only
     * loads leave execution shells without blocks and collapse the task tree to shared chrome.
     *
     * @return array<string, mixed>
     */
    public function loadScopeForBuildOperation(AiSiteAgentSession $session): array
    {
        return $this->loadScopeForStage(
            $session,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            self::BUILD_OPERATION_ARTIFACT_KEYS
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function loadScopeForStage(AiSiteAgentSession $session, string $stageCode, ?array $artifactKeys = null): array
    {
        if (!$this->allowFullScopeHydrate() && $artifactKeys === null) {
            return $this->loadScopeManifest($session);
        }

        $scope = $this->loadScopeFragmentFromPgsql($session, $stageCode);
        if ($scope !== null) {
            $scope = $this->hydrateLegacyArtifactsForStage($session, $scope, $stageCode);
            return $artifactKeys === null
                ? $this->artifactStorage()->hydrateScopeForStage(
                    (int)$session->getId(),
                    $scope,
                    $stageCode
                )
                : $this->hydrateScopeWithExplicitArtifacts((int)$session->getId(), $scope, $artifactKeys);
        }

        $scope = $this->decodeSessionScopeData($session);
        return $artifactKeys === null
            ? $this->artifactStorage()->hydrateScopeForStage(
                (int)$session->getId(),
                $scope,
                $stageCode
            )
            : $this->hydrateScopeWithExplicitArtifacts((int)$session->getId(), $scope, $artifactKeys);
    }

    /**
     * Load only the requested top-level scope keys. This is used by WLS hot paths
     * that must not hydrate full stage artifacts before first paint.
     *
     * @param list<string> $scopeKeys
     * @param list<string> $artifactKeys
     * @return array<string, mixed>
     */
    public function loadScopeFragment(AiSiteAgentSession $session, array $scopeKeys, array $artifactKeys = []): array
    {
        $scopeKeys = $this->normalizeScopeKeyList($scopeKeys);
        if ($scopeKeys === []) {
            return [];
        }

        $scope = $this->loadScopeFragmentByKeysFromPgsql($session, $scopeKeys);
        if ($scope === null) {
            $scope = \array_intersect_key($this->decodeSessionScopeData($session), \array_flip($scopeKeys));
        }

        return $this->hydrateScopeWithExplicitArtifacts((int)$session->getId(), $scope, $artifactKeys);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSessionScopeData(AiSiteAgentSession $session): array
    {
        $raw = \trim((string)($session->getData(AiSiteAgentSession::schema_fields_SCOPE_JSON) ?? ''));
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function normalizeStageCode(string $stageCode): string
    {
        $stageCode = \trim($stageCode);
        return \in_array($stageCode, [
            AiSiteAgentSession::STAGE_PLAN,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            AiSiteAgentSession::STAGE_PUBLISH,
        ], true) ? $stageCode : '';
    }

    public function mergeScope(int $sessionId, int $forAdminUserId, array $patch): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $clearArtifactRefs = $this->shouldClearArtifactRefs($patch);
        if (\array_key_exists('target_domain', $patch)) {
            $td = \trim((string)$patch['target_domain']);
            $patch['target_domain'] = $td === '' ? '' : \strtolower($td);
        }
        $touchedArtifactKeys = $this->artifactStorage()->resolveTouchedArtifactKeysFromPatch($patch);
        $hydrateKeys = $this->artifactStorage()->expandArtifactKeysForMerge($touchedArtifactKeys);
        $scope = $this->loadScope($session, $hydrateKeys);
        $merged = $this->mergeScopePatch($scope, $patch);
        $storage = $this->artifactStorage()->prepareScopeForStorage((int)$session->getId(), $merged, $touchedArtifactKeys);
        $scopeForStorage = $storage['scope'];
        $artifacts = $storage['artifacts'];
        unset($storage, $scope, $merged, $patch, $touchedArtifactKeys, $hydrateKeys);
        $this->artifactStorage()->persistArtifacts((int)$session->getId(), $artifacts);
        unset($artifacts);
        $this->writeScopeArrayToExistingSession($session, $scopeForStorage);
        if ($clearArtifactRefs) {
            $this->clearScopeArtifactRefs($session);
        }
        unset($scopeForStorage);
        return true;
    }

    /**
     * 整体替换 scope（仍须为对象 JSON 对应的关联数组）
     *
     * @param array<string, mixed> $scope
     * @param list<string> $touchedArtifactKeys
     */
    public function replaceScope(int $sessionId, int $forAdminUserId, array $scope, array $touchedArtifactKeys = []): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $clearArtifactRefs = $this->shouldClearArtifactRefs($scope);
        if (\array_key_exists('target_domain', $scope)) {
            $td = \trim((string)$scope['target_domain']);
            $scope['target_domain'] = $td === '' ? '' : \strtolower($td);
        }
        $scope = $this->manifestPolicy()->dehydrateScopePaths($scope);
        $storage = $this->artifactStorage()->prepareScopeForStorage((int)$session->getId(), $scope, $touchedArtifactKeys);
        $scopeForStorage = $storage['scope'];
        $artifacts = $storage['artifacts'];
        unset($storage, $scope);
        $this->artifactStorage()->persistArtifacts((int)$session->getId(), $artifacts);
        unset($artifacts);
        $this->writeScopeArrayToExistingSession($session, $scopeForStorage);
        if ($clearArtifactRefs) {
            $this->clearScopeArtifactRefs($session);
        }
        unset($scopeForStorage);
        return true;
    }

    public function replaceScopeJson(int $sessionId, int $forAdminUserId, string $scopeJson): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $scopeJson = \trim($scopeJson);
        if ($scopeJson === '' || !\json_validate($scopeJson)) {
            return false;
        }

        try {
            $scope = \json_decode($scopeJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!\is_array($scope)) {
            return false;
        }

        $storage = $this->artifactStorage()->prepareScopeForStorage((int)$session->getId(), $scope);
        $scopeForStorage = $storage['scope'];
        $artifacts = $storage['artifacts'];
        unset($storage, $scope, $scopeJson);
        $this->artifactStorage()->persistArtifacts((int)$session->getId(), $artifacts);
        unset($artifacts);
        $this->writeScopeArrayToExistingSession($session, $scopeForStorage);
        unset($scopeForStorage);
        return true;
    }

    /**
     * Lightweight preview-switch snapshot for large scope_json rows.
     *
     * @return array{
     *   scope_json_bytes:int,
     *   page_types:list<string>,
     *   pagebuilder_pages_by_type:array<string, array<string, mixed>>,
     *   virtual_pages_by_type:array<string, array<string, mixed>>,
     *   preview_page_id:int,
     *   preview_page_type:string,
     *   workspace_track:string
     * }|null
     */
    public function loadPreviewSwitchScopeSnapshot(int $sessionId, int $forAdminUserId): ?array
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return null;
        }

        $pgsqlSnapshot = $this->loadPreviewSwitchScopeSnapshotFromPgsql($sessionId, $forAdminUserId);
        if ($pgsqlSnapshot !== null) {
            return $pgsqlSnapshot;
        }

        $raw = (string)($session->getData(AiSiteAgentSession::schema_fields_SCOPE_JSON) ?? '');
        $scope = $this->loadScopeForStage($session, $this->normalizeStageCode($session->getStage()));

        return [
            'scope_json_bytes' => \strlen($raw),
            'page_types' => \array_values(\array_filter(
                \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [],
                static fn(mixed $item): bool => \is_string($item) && \trim($item) !== ''
            )),
            'pagebuilder_pages_by_type' => \is_array($scope['pagebuilder_pages_by_type'] ?? null)
                ? $scope['pagebuilder_pages_by_type']
                : [],
            'virtual_pages_by_type' => \is_array($scope['virtual_pages_by_type'] ?? null)
                ? $scope['virtual_pages_by_type']
                : [],
            'preview_page_id' => (int)($scope['preview_page_id'] ?? 0),
            'preview_page_type' => \trim((string)($scope['preview_page_type'] ?? '')),
            'workspace_track' => \trim((string)($scope['workspace_track'] ?? '')),
        ];
    }

    public function updatePreviewSelectionScope(int $sessionId, int $forAdminUserId, string $previewPageType, int $previewPageId): bool
    {
        $previewPageType = \trim($previewPageType);
        if ($previewPageType === '') {
            return false;
        }

        $pgsqlUpdated = $this->updatePreviewSelectionScopeInPgsql(
            $sessionId,
            $forAdminUserId,
            $previewPageType,
            $previewPageId
        );
        if ($pgsqlUpdated !== null) {
            return $pgsqlUpdated;
        }

        return $this->mergeScope($sessionId, $forAdminUserId, [
            'preview_page_type' => $previewPageType,
            'preview_page_id' => \max(0, $previewPageId),
        ]);
    }

    public function setStage(int $sessionId, int $forAdminUserId, string $stage): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $session->setData(AiSiteAgentSession::schema_fields_STAGE, $stage);
        $this->touchUpdateTime($session);
        $session->save();
        return true;
    }

    public function bindWebsite(int $sessionId, int $forAdminUserId, int $websiteId): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $session->setData(AiSiteAgentSession::schema_fields_WEBSITE_ID, \max(0, $websiteId));
        $this->touchUpdateTime($session);
        $session->save();
        $this->injectEligibleLocalWelineDomainHosts($session);
        $this->ensureEligibleLocalWelineWildcardCertificate($session, $websiteId);
        return true;
    }

    public function bindVirtualTheme(int $sessionId, int $forAdminUserId, int $virtualThemeId): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $session->setData(AiSiteAgentSession::schema_fields_VIRTUAL_THEME_ID, \max(0, $virtualThemeId));
        $this->touchUpdateTime($session);
        $session->save();
        return true;
    }

    public function setPublishStatus(int $sessionId, int $forAdminUserId, string $publishStatus): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $session->setData(AiSiteAgentSession::schema_fields_PUBLISH_STATUS, $publishStatus);
        $this->touchUpdateTime($session);
        $session->save();
        return true;
    }

    private function artifactStorage(): AiSiteAgentSessionArtifactService
    {
        if ($this->artifactService instanceof AiSiteAgentSessionArtifactService) {
            return $this->artifactService;
        }

        return ObjectManager::getInstance(AiSiteAgentSessionArtifactService::class);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function shouldClearArtifactRefs(array $scope): bool
    {
        return \array_key_exists('_artifact_refs', $scope)
            && \is_array($scope['_artifact_refs'])
            && $scope['_artifact_refs'] === [];
    }

    private function clearScopeArtifactRefs(AiSiteAgentSession $session): void
    {
        $this->artifactStorage()->deleteArtifactsForSession((int)$session->getId());
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null || (int)$session->getId() <= 0) {
            return;
        }

        $table = $this->sessionModel->getTable();
        $pk = AiSiteAgentSession::schema_fields_ID;
        $adminField = AiSiteAgentSession::schema_fields_ADMIN_USER_ID;
        $scopeField = AiSiteAgentSession::schema_fields_SCOPE_JSON;
        $sql = <<<SQL
UPDATE {$table}
SET "{$scopeField}" = ((COALESCE(NULLIF("{$scopeField}", ''), '{}')::jsonb - '_artifact_refs')::text)
WHERE "{$pk}" = :session_id AND "{$adminField}" = :admin_id
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'session_id' => (int)$session->getId(),
            'admin_id' => (int)$session->getData(AiSiteAgentSession::schema_fields_ADMIN_USER_ID),
        ]);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function mergeScopePatch(array $scope, array $patch): array
    {
        $merged = \array_replace($scope, $patch);
        if (\array_key_exists('asset_manifest', $patch)) {
            $manifest = \is_array($merged['asset_manifest'] ?? null) ? $merged['asset_manifest'] : [];
            $merged['asset_manifest_hash'] = \sha1((string)\json_encode($manifest, \JSON_UNESCAPED_UNICODE));
        }

        return $merged;
    }

    private function loadSessionMetadataFromPgsql(string|int $identity, int $forAdminUserId, bool $byPublicId): AiSiteAgentSession|false|null
    {
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null) {
            return false;
        }

        $table = $this->sessionModel->getTable();
        $identityField = $byPublicId ? AiSiteAgentSession::schema_fields_PUBLIC_ID : AiSiteAgentSession::schema_fields_ID;
        $adminField = AiSiteAgentSession::schema_fields_ADMIN_USER_ID;
        $fields = [
            AiSiteAgentSession::schema_fields_ID,
            AiSiteAgentSession::schema_fields_PUBLIC_ID,
            AiSiteAgentSession::schema_fields_ADMIN_USER_ID,
            AiSiteAgentSession::schema_fields_WEBSITE_ID,
            AiSiteAgentSession::schema_fields_VIRTUAL_THEME_ID,
            AiSiteAgentSession::schema_fields_STAGE,
            AiSiteAgentSession::schema_fields_PUBLISH_STATUS,
            AiSiteAgentSession::schema_fields_CREATE_TIME,
            AiSiteAgentSession::schema_fields_UPDATE_TIME,
        ];
        $select = \implode(', ', \array_map(static fn(string $field): string => '"' . $field . '"', $fields));
        $sql = <<<SQL
SELECT {$select}
FROM {$table}
WHERE "{$identityField}" = :identity
  AND "{$adminField}" = :admin_user_id
LIMIT 1
SQL;
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute([
            'identity' => $identity,
            'admin_user_id' => $forAdminUserId,
        ])) {
            return false;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return null;
        }

        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery();
        foreach ($fields as $field) {
            if (\array_key_exists($field, $row)) {
                $session->setData($field, $row[$field]);
            }
        }

        return $session->getId() > 0 ? $session : null;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function normalizeScopeKeyList(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            $key = \trim((string)$key);
            if ($key === '' || \in_array($key, $normalized, true)) {
                continue;
            }
            $normalized[] = $key;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $artifactKeys
     * @return array<string, mixed>
     */
    private function hydrateScopeWithExplicitArtifacts(int $sessionId, array $scope, array $artifactKeys): array
    {
        $artifactKeys = $this->normalizeScopeKeyList($artifactKeys);
        if ($artifactKeys === []) {
            return $scope;
        }

        return $this->artifactStorage()->hydrateScope($sessionId, $scope, $artifactKeys);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadScopeFragmentFromPgsql(AiSiteAgentSession $session, string $stageCode): ?array
    {
        return $this->loadScopeFragmentByKeysFromPgsql($session, $this->resolveStageScopeKeys($stageCode));
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>|null
     */
    private function loadScopeFragmentByKeysFromPgsql(AiSiteAgentSession $session, array $keys): ?array
    {
        $sessionId = (int)$session->getId();
        $adminId = (int)$session->getAdminUserId();
        if ($sessionId <= 0 || $adminId <= 0) {
            return null;
        }
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null) {
            return null;
        }

        $keys = $this->normalizeScopeKeyList($keys);
        if ($keys === []) {
            return [];
        }
        $jsonBuild = $this->buildStageScopeJsonbExpression($keys);
        $table = $this->sessionModel->getTable();
        $pk = AiSiteAgentSession::schema_fields_ID;
        $adminField = AiSiteAgentSession::schema_fields_ADMIN_USER_ID;
        $scopeField = AiSiteAgentSession::schema_fields_SCOPE_JSON;
        $sql = <<<SQL
SELECT ({$jsonBuild})::text AS scope_json
FROM (
    SELECT COALESCE(NULLIF("{$scopeField}", ''), '{}')::jsonb AS scope_doc
    FROM {$table}
    WHERE "{$pk}" = :session_id
      AND "{$adminField}" = :admin_user_id
    LIMIT 1
) AS source
SQL;
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute([
            'session_id' => $sessionId,
            'admin_user_id' => $adminId,
        ])) {
            return null;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return null;
        }
        $raw = \trim((string)($row['scope_json'] ?? ''));
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Legacy rows may still keep stage payloads inside scope_json. Migrate only
     * the exact artifact paths needed by this stage, then future reads use the
     * artifact table without transferring full scope_json to PHP.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function hydrateLegacyArtifactsForStage(AiSiteAgentSession $session, array $scope, string $stageCode): array
    {
        $sessionId = (int)$session->getId();
        if ($sessionId <= 0) {
            return $scope;
        }
        $artifactService = $this->artifactStorage();
        $refs = \is_array($scope['_artifact_refs'] ?? null) ? $scope['_artifact_refs'] : [];
        $migrated = false;
        foreach ($artifactService->artifactKeysForStage($stageCode) as $artifactKey) {
            $artifactStage = $artifactService->artifactStage($artifactKey);
            if ($artifactStage === '' || \is_array($refs[$artifactStage][$artifactKey] ?? null)) {
                continue;
            }
            $path = $artifactService->artifactPath($artifactKey);
            if ($path === []) {
                continue;
            }
            if ($artifactService->payloadHasContent($this->getNestedScopeValue($scope, $path))) {
                continue;
            }

            $payload = $this->loadLegacyScopePathValueFromPgsql($session, $path);
            if (!$artifactService->payloadHasContent($payload)) {
                continue;
            }

            $scope = $this->setNestedScopeValue($scope, $path, $payload);
            $migrated = true;
            unset($payload);
        }

        if (!$migrated) {
            return $scope;
        }

        $storage = $artifactService->prepareScopeForStorage($sessionId, $scope);
        $scopeForStorage = $storage['scope'];
        $artifacts = $storage['artifacts'];
        unset($storage, $refs);
        $artifactService->persistArtifacts($sessionId, $artifacts);
        unset($artifacts);
        $this->writeScopeArrayToExistingSession($session, $scopeForStorage);
        unset($scopeForStorage);

        return $scope;
    }

    /**
     * @param list<string> $path
     */
    private function loadLegacyScopePathValueFromPgsql(AiSiteAgentSession $session, array $path): mixed
    {
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null || $path === []) {
            return null;
        }
        $pathSql = "'{" . \implode(',', \array_map(static fn(string $part): string => \str_replace(['\\', '"', ',', '{', '}'], '', $part), $path)) . "}'";
        $table = $this->sessionModel->getTable();
        $pk = AiSiteAgentSession::schema_fields_ID;
        $adminField = AiSiteAgentSession::schema_fields_ADMIN_USER_ID;
        $scopeField = AiSiteAgentSession::schema_fields_SCOPE_JSON;
        $sql = <<<SQL
SELECT (COALESCE(NULLIF("{$scopeField}", ''), '{}')::jsonb #> {$pathSql})::text AS payload_json
FROM {$table}
WHERE "{$pk}" = :session_id
  AND "{$adminField}" = :admin_user_id
LIMIT 1
SQL;
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute([
            'session_id' => (int)$session->getId(),
            'admin_user_id' => (int)$session->getAdminUserId(),
        ])) {
            return null;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $raw = \trim((string)(\is_array($row) ? ($row['payload_json'] ?? '') : ''));
        if ($raw === '' || $raw === 'null') {
            return null;
        }

        try {
            return \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param list<string> $path
     */
    private function getNestedScopeValue(array $scope, array $path): mixed
    {
        $cursor = $scope;
        foreach ($path as $part) {
            if (!\is_array($cursor) || !\array_key_exists($part, $cursor)) {
                return null;
            }
            $cursor = $cursor[$part];
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $path
     * @return array<string, mixed>
     */
    private function setNestedScopeValue(array $scope, array $path, mixed $value): array
    {
        $cursor =& $scope;
        foreach ($path as $index => $part) {
            if ($index === \count($path) - 1) {
                $cursor[$part] = $value;
                break;
            }
            if (!\is_array($cursor[$part] ?? null)) {
                $cursor[$part] = [];
            }
            $cursor =& $cursor[$part];
        }
        unset($cursor);

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function writeScopeArrayToExistingSession(AiSiteAgentSession $session, array $scope): void
    {
        $session->setScopeArray($scope);
        $this->touchUpdateTime($session);
        $session->save();
    }

    /**
     * @return list<string>
     */
    private function resolveStageScopeKeys(string $stageCode): array
    {
        $keys = self::COMMON_STAGE_SCOPE_KEYS;
        if ($stageCode === AiSiteAgentSession::STAGE_PLAN) {
            $keys = \array_merge($keys, self::PLAN_STAGE_SCOPE_KEYS);
        } elseif (\in_array($stageCode, [AiSiteAgentSession::STAGE_VISUAL_EDIT, AiSiteAgentSession::STAGE_PUBLISH, ''], true)) {
            $keys = \array_merge($keys, self::PLAN_STAGE_SCOPE_KEYS, self::VISUAL_EDIT_STAGE_SCOPE_KEYS);
        }

        return \array_values(\array_unique($keys));
    }

    /**
     * jsonb_build_object is limited to 100 arguments on PostgreSQL, so keep each
     * chunk below 50 key/value pairs and merge the JSONB objects.
     *
     * @param list<string> $keys
     */
    private function buildStageScopeJsonbExpression(array $keys): string
    {
        $chunks = [];
        foreach (\array_chunk($keys, 40) as $chunk) {
            $jsonArgs = [];
            foreach ($chunk as $key) {
                $safeKey = \str_replace("'", "''", $key);
                $jsonArgs[] = "'" . $safeKey . "'";
                $jsonArgs[] = "scope_doc -> '" . $safeKey . "'";
            }
            if ($jsonArgs !== []) {
                $chunks[] = 'jsonb_build_object(' . \implode(', ', $jsonArgs) . ')';
            }
        }
        if ($chunks === []) {
            return "'{}'::jsonb";
        }

        return 'jsonb_strip_nulls(' . \implode(' || ', $chunks) . ')';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function appendEvent(
        int $sessionId,
        int $forAdminUserId,
        string $eventType,
        array $payload = [],
        string $stageCode = '',
        string $level = AiSiteAgentSessionEvent::LEVEL_INFO
    ): bool
    {
        if ($this->loadById($sessionId, $forAdminUserId) === null) {
            return false;
        }
        $payload = $this->sanitizeEventPayloadForStorage($payload);
        $event = clone $this->eventModel;
        $event->clearData()->clearQuery();
        $event->setData(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId);
        $event->setData(AiSiteAgentSessionEvent::schema_fields_STAGE_CODE, \trim($stageCode));
        $event->setData(AiSiteAgentSessionEvent::schema_fields_EVENT_TYPE, $eventType);
        $event->setData(
            AiSiteAgentSessionEvent::schema_fields_LEVEL,
            \trim($level) !== '' ? \trim($level) : AiSiteAgentSessionEvent::LEVEL_INFO
        );
        $event->setPayloadArray($payload);
        try {
            $event->save();
        } catch (\Throwable $e) {
            if (!$this->isPgsqlAiSessionEventPrimaryKeyBroken($e)) {
                throw $e;
            }
            $event = clone $this->eventModel;
            $event->clearData()->clearQuery();
            $event->setData(AiSiteAgentSessionEvent::schema_fields_ID, $this->allocateNextAiSessionEventId());
            $event->setData(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId);
            $event->setData(AiSiteAgentSessionEvent::schema_fields_STAGE_CODE, \trim($stageCode));
            $event->setData(AiSiteAgentSessionEvent::schema_fields_EVENT_TYPE, $eventType);
            $event->setData(
                AiSiteAgentSessionEvent::schema_fields_LEVEL,
                \trim($level) !== '' ? \trim($level) : AiSiteAgentSessionEvent::LEVEL_INFO
            );
            $event->setPayloadArray($payload);
            $event->save();
        }
        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizeEventPayloadForStorage(array $payload): array
    {
        if (\is_array($payload['state'] ?? null)) {
            /** @var AiSiteAgentWorkspaceStateHelperService $stateHelper */
            $stateHelper = ObjectManager::getInstance(AiSiteAgentWorkspaceStateHelperService::class);
            $payload['state'] = $stateHelper->pruneStateForEventPayload($payload['state']);
        }
        if (\is_array($payload['task_runtime_context'] ?? null)) {
            $payload['task_runtime_context'] = $this->summarizeTaskRuntimeContextForEvent($payload['task_runtime_context']);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    private function summarizeTaskRuntimeContextForEvent(array $runtime): array
    {
        $summary = [];
        foreach ([
            'session_id',
            'task_key',
            'task_session_id',
            'stream_session_key',
            'content_locale',
            'build_plan_contract_id',
        ] as $key) {
            if (!\array_key_exists($key, $runtime) || \is_array($runtime[$key]) || \is_object($runtime[$key])) {
                continue;
            }
            $summary[$key] = $runtime[$key];
        }
        foreach (['target', 'context_refs', 'page_contract', 'allowed_contract_refs'] as $key) {
            if (\is_array($runtime[$key] ?? null)) {
                $summary[$key] = $runtime[$key];
            }
        }
        $summary['runtime_context_slimmed'] = true;

        return $summary;
    }

    /**
     * 按事件主键游标拉取新事件（供 SSE 增量推送）
     *
     * @return list<array{
     *   event_id: int,
     *   stage_code: string,
     *   event_type: string,
     *   level: string,
     *   payload: array<string, mixed>,
     *   create_time: string
     * }>
     */
    public function getLatestEventId(int $sessionId, int $forAdminUserId): int
    {
        if ($this->loadById($sessionId, $forAdminUserId) === null) {
            return 0;
        }
        $event = clone $this->eventModel;
        $event->clearData()->clearQuery()
            ->where(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId)
            ->order(AiSiteAgentSessionEvent::schema_fields_ID, 'DESC')
            ->limit(1)
            ->find()
            ->fetch();

        return $event->getId() > 0 ? $event->getId() : 0;
    }

    public function listEventsAfterId(int $sessionId, int $forAdminUserId, int $afterEventId, int $limit = 100): array
    {
        if ($this->loadById($sessionId, $forAdminUserId) === null) {
            return [];
        }
        $limit = \min(200, \max(1, $limit));
        $event = clone $this->eventModel;
        $rows = $event->clearData()->clearQuery()
            ->where(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId)
            ->where(AiSiteAgentSessionEvent::schema_fields_ID, $afterEventId, '>')
            ->order(AiSiteAgentSessionEvent::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $m = clone $this->eventModel;
            $m->setData($row);
            $out[] = [
                'event_id' => $m->getId(),
                'stage_code' => $m->getStageCode(),
                'event_type' => $m->getEventType(),
                'level' => $m->getLevel(),
                'payload' => $m->getPayloadArray(),
                'create_time' => (string) ($row[AiSiteAgentSessionEvent::schema_fields_CREATE_TIME] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return list<array{
     *   event_id: int,
     *   stage_code: string,
     *   event_type: string,
     *   level: string,
     *   payload: array<string, mixed>,
     *   create_time: string
     * }>
     */
    public function listRecentEvents(int $sessionId, int $forAdminUserId, int $limit = 200): array
    {
        if ($this->loadById($sessionId, $forAdminUserId) === null) {
            return [];
        }
        $limit = \min(500, \max(1, $limit));
        $event = clone $this->eventModel;
        $rows = $event->clearData()->clearQuery()
            ->where(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId)
            ->order(AiSiteAgentSessionEvent::schema_fields_CREATE_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        $rows = \array_reverse($rows);
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $m = clone $this->eventModel;
            $m->setData($row);
            $out[] = [
                'event_id' => $m->getId(),
                'stage_code' => $m->getStageCode(),
                'event_type' => $m->getEventType(),
                'level' => $m->getLevel(),
                'payload' => $m->getPayloadArray(),
                'create_time' => (string) ($row[AiSiteAgentSessionEvent::schema_fields_CREATE_TIME] ?? ''),
            ];
        }
        return $out;
    }

    private function touchUpdateTime(AiSiteAgentSession $session): void
    {
        $session->setData(
            AiSiteAgentSession::schema_fields_UPDATE_TIME,
            \date('Y-m-d H:i:s')
        );
    }

    /**
     * @return array{
     *   scope_json_bytes:int,
     *   page_types:list<string>,
     *   pagebuilder_pages_by_type:array<string, array<string, mixed>>,
     *   virtual_pages_by_type:array<string, array<string, mixed>>,
     *   preview_page_id:int,
     *   preview_page_type:string,
     *   workspace_track:string
     * }|null
     */
    private function loadPreviewSwitchScopeSnapshotFromPgsql(int $sessionId, int $forAdminUserId): ?array
    {
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null) {
            return null;
        }

        $table = $this->sessionModel->getTable();
        $pk = AiSiteAgentSession::schema_fields_ID;
        $adminField = AiSiteAgentSession::schema_fields_ADMIN_USER_ID;
        $scopeField = AiSiteAgentSession::schema_fields_SCOPE_JSON;
        $sql = <<<SQL
SELECT
    OCTET_LENGTH(COALESCE(scope_row.raw_scope_json, '')) AS scope_json_bytes,
    COALESCE((scope_row.scope_doc -> 'page_types')::text, '[]') AS page_types_json,
    COALESCE((scope_row.scope_doc -> 'pagebuilder_pages_by_type')::text, '{}') AS pagebuilder_pages_json,
    COALESCE((scope_row.scope_doc -> 'virtual_pages_by_type')::text, '{}') AS virtual_pages_json,
    COALESCE(scope_row.scope_doc ->> 'preview_page_type', '') AS preview_page_type,
    COALESCE((scope_row.scope_doc ->> 'preview_page_id')::int, 0) AS preview_page_id,
    COALESCE(scope_row.scope_doc ->> 'workspace_track', '') AS workspace_track
FROM (
    SELECT
        {$scopeField} AS raw_scope_json,
        COALESCE(NULLIF({$scopeField}, ''), '{}')::jsonb AS scope_doc
    FROM {$table}
    WHERE {$pk} = :session_id
      AND {$adminField} = :admin_user_id
    LIMIT 1
) AS scope_row
SQL;
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute([
            'session_id' => $sessionId,
            'admin_user_id' => $forAdminUserId,
        ])) {
            return null;
        }

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return null;
        }

        return [
            'scope_json_bytes' => (int)($row['scope_json_bytes'] ?? 0),
            'page_types' => $this->decodeJsonList((string)($row['page_types_json'] ?? '[]')),
            'pagebuilder_pages_by_type' => $this->decodeJsonMap((string)($row['pagebuilder_pages_json'] ?? '{}')),
            'virtual_pages_by_type' => $this->decodeJsonMap((string)($row['virtual_pages_json'] ?? '{}')),
            'preview_page_id' => (int)($row['preview_page_id'] ?? 0),
            'preview_page_type' => \trim((string)($row['preview_page_type'] ?? '')),
            'workspace_track' => \trim((string)($row['workspace_track'] ?? '')),
        ];
    }

    private function updatePreviewSelectionScopeInPgsql(
        int $sessionId,
        int $forAdminUserId,
        string $previewPageType,
        int $previewPageId
    ): ?bool {
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null) {
            return null;
        }

        $table = $this->sessionModel->getTable();
        $pk = AiSiteAgentSession::schema_fields_ID;
        $adminField = AiSiteAgentSession::schema_fields_ADMIN_USER_ID;
        $scopeField = AiSiteAgentSession::schema_fields_SCOPE_JSON;
        $updateField = AiSiteAgentSession::schema_fields_UPDATE_TIME;
        $sql = <<<SQL
UPDATE {$table} AS session_row
SET
    {$scopeField} = jsonb_set(
        jsonb_set(
            source.scope_doc,
            '{preview_page_type}',
            to_jsonb(CAST(:preview_page_type AS text)),
            true
        ),
        '{preview_page_id}',
        to_jsonb(CAST(:preview_page_id AS integer)),
        true
    )::text,
    {$updateField} = :update_time
FROM (
    SELECT
        {$pk} AS target_session_id,
        COALESCE(NULLIF({$scopeField}, ''), '{}')::jsonb AS scope_doc
    FROM {$table}
    WHERE {$pk} = :source_session_id
      AND {$adminField} = :source_admin_user_id
    LIMIT 1
) AS source
WHERE session_row.{$pk} = source.target_session_id
  AND session_row.{$adminField} = :target_admin_user_id
SQL;
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->execute([
            'preview_page_type' => $previewPageType,
            'preview_page_id' => \max(0, $previewPageId),
            'update_time' => \date('Y-m-d H:i:s'),
            'source_session_id' => $sessionId,
            'source_admin_user_id' => $forAdminUserId,
            'target_admin_user_id' => $forAdminUserId,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function getPgsqlPdo(): ?\PDO
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector || !\method_exists($connector, 'getWrappedConnection')) {
            return null;
        }

        $pdo = $connector->getWrappedConnection()->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return null;
        }

        return $pdo;
    }

    /**
     * @return list<string>
     */
    private function decodeJsonList(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (!\is_string($item)) {
                continue;
            }
            $item = \trim($item);
            if ($item === '') {
                continue;
            }
            $items[] = $item;
        }

        return \array_values(\array_unique($items));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decodeJsonMap(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function getLocalWelineHostsSyncService(): LocalWelineHostsSyncService
    {
        return $this->localWelineHostsSyncService
            ?? ObjectManager::getInstance(LocalWelineHostsSyncService::class);
    }

    private function getLocalWelineWildcardCertificateService(): LocalWelineWildcardCertificateService
    {
        return $this->localWelineWildcardCertificateService
            ?? ObjectManager::getInstance(LocalWelineWildcardCertificateService::class);
    }

    private function injectEligibleLocalWelineDomainHosts(AiSiteAgentSession $session): void
    {
        $scope = $this->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $domain = \strtolower(\trim((string)($scope['target_domain'] ?? $scope['selected_domain'] ?? '')));
        if ($domain === '') {
            return;
        }

        try {
            $this->getLocalWelineHostsSyncService()->ensureHostsInjected($domain);
        } catch (\Throwable $throwable) {
            \w_log_warning(
                '[PageBuilder\\AiSiteAgentSessionService] local weline hosts injection failed: '
                . $domain . ' - ' . $throwable->getMessage()
            );
        }
    }

    private function ensureEligibleLocalWelineWildcardCertificate(AiSiteAgentSession $session, int $websiteId): void
    {
        $scope = $this->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $domain = \strtolower(\trim((string)($scope['target_domain'] ?? $scope['selected_domain'] ?? '')));
        if ($domain === '') {
            return;
        }

        try {
            $this->getLocalWelineWildcardCertificateService()
                ->ensureWildcardCertificateForDomain($domain, $websiteId);
        } catch (\Throwable $throwable) {
            \w_log_warning(
                '[PageBuilder\\AiSiteAgentSessionService] local weline wildcard certificate ensure failed: '
                . $domain . ' - ' . $throwable->getMessage()
            );
        }
    }

    /**
     * @return list<array{
     *   public_id: string,
     *   stage: string,
     *   publish_status: string,
     *   website_id: int,
     *   virtual_theme_id: int,
     *   update_time: string,
     *   workspace_status: string,
     *   active_operation_status: string,
     *   active_operation_queue_id: int,
     *   can_publish: bool,
     *   preview_full_url: string,
     *   visual_preview_url: string,
     *   visual_edit_url: string
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
            ->fields([
                AiSiteAgentSession::schema_fields_ID,
                AiSiteAgentSession::schema_fields_PUBLIC_ID,
                AiSiteAgentSession::schema_fields_STAGE,
                AiSiteAgentSession::schema_fields_PUBLISH_STATUS,
                AiSiteAgentSession::schema_fields_WEBSITE_ID,
                AiSiteAgentSession::schema_fields_VIRTUAL_THEME_ID,
                AiSiteAgentSession::schema_fields_UPDATE_TIME,
            ])
            ->where(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->order(AiSiteAgentSession::schema_fields_UPDATE_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $m = clone $this->sessionModel;
            $m->setData($row);
            if ($m->getId() <= 0) {
                continue;
            }
            // 加载 scope 获取 workspace_status 和 active_operation.queue_id
            $scope = $this->loadScopeForStage($m, $this->normalizeStageCode($m->getStage()));
            $activeOp = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            $activeStatus = \trim((string)($activeOp['status'] ?? ''));
            $workspaceStatus = \trim((string)($scope['workspace_status'] ?? ''));
            $out[] = [
                'public_id' => $m->getPublicId(),
                'stage' => $m->getStage(),
                'publish_status' => $m->getPublishStatus(),
                'website_id' => $m->getWebsiteId(),
                'virtual_theme_id' => $m->getVirtualThemeId(),
                'update_time' => (string) ($row[AiSiteAgentSession::schema_fields_UPDATE_TIME] ?? ''),
                'workspace_status' => $workspaceStatus,
                'active_operation_status' => $activeStatus,
                'active_operation_queue_id' => (int)($activeOp['queue_id'] ?? 0),
                'can_publish' => !empty($scope['can_publish']),
                'preview_full_url' => \trim((string)($scope['preview_full_url'] ?? '')),
                'visual_preview_url' => \trim((string)($scope['visual_preview_url'] ?? '')),
                'visual_edit_url' => \trim((string)($scope['visual_edit_url'] ?? '')),
            ];
        }
        return $out;
    }

    private function isPgsqlAiSessionPrimaryKeySerialMissing(\Throwable $e): bool
    {
        $chain = $e;
        while ($chain !== null) {
            $msg = $chain->getMessage();
            // 检查 NOT NULL 错误（23502）或主键冲突错误（23505）
            if ((\str_contains($msg, '23502') || \str_contains($msg, '23505'))
                && \str_contains($msg, 'ai_site_agent_session_id')
                && (\str_contains($msg, 'guolairen_page_builder_ai_site_agent_session')
                    || \str_contains($msg, 'm_guolairen_page_builder_ai_site_agent_session'))) {
                return true;
            }
            $chain = $chain->getPrevious();
        }
        return false;
    }

    private function isPgsqlAiSessionEventPrimaryKeyBroken(\Throwable $e): bool
    {
        $chain = $e;
        while ($chain !== null) {
            $msg = $chain->getMessage();
            if ((\str_contains($msg, '23502') || \str_contains($msg, '23505'))
                && \str_contains($msg, AiSiteAgentSessionEvent::schema_fields_ID)
                && (\str_contains($msg, 'guolairen_page_builder_ai_site_agent_event')
                    || \str_contains($msg, 'm_guolairen_page_builder_ai_site_agent_event'))) {
                return true;
            }
            $chain = $chain->getPrevious();
        }

        return false;
    }

    private function allocateNextAiSessionEventId(): int
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector || !\method_exists($connector, 'getWrappedConnection')) {
            return 0;
        }

        $pdo = $connector->getWrappedConnection()->getPdo();
        $tableSql = $this->eventModel->getTable();
        $pk = AiSiteAgentSessionEvent::schema_fields_ID;
        $stmt = $pdo->query('SELECT COALESCE(MAX("' . $pk . '"), 0) AS mx FROM ' . $tableSql);
        if ($stmt === false) {
            return 0;
        }

        return ((int)($stmt->fetch(\PDO::FETCH_ASSOC)['mx'] ?? 0)) + 1;
    }

    /**
     * 历史库表在 PG 上曾建成无序列的 INTEGER 主键，INSERT 省略主键时会违反 NOT NULL。
     * 与 SchemaDiff 的 MODIFY 逻辑一致：CREATE SEQUENCE + SET DEFAULT nextval。
     * 同时修复序列值，确保序列值大于当前最大 ID。
     */
    private function repairPgsqlAiSessionPrimaryKeySerial(): void
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector) {
            return;
        }
        $pk = AiSiteAgentSession::schema_fields_ID;
        $declared = [
            'name' => $pk,
            'type' => 'int',
            'length' => null,
            'nullable' => false,
            'primaryKey' => true,
            'autoIncrement' => true,
            'default' => null,
            'comment' => '',
            'unique' => false,
        ];
        $existingCol = null;
        $columns = $connector->getTableColumns($this->sessionModel->getTable());
        foreach ($columns as $row) {
            if (!\is_array($row) || (string) ($row['name'] ?? '') !== $pk) {
                continue;
            }
            $existingCol = [
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'length' => \array_key_exists('length', $row) ? $row['length'] : null,
                'nullable' => (bool) ($row['nullable'] ?? true),
                'primaryKey' => (bool) ($row['primary_key'] ?? false),
                'autoIncrement' => (bool) ($row['auto_increment'] ?? false),
                'default' => $row['default'] ?? null,
                'comment' => (string) ($row['comment'] ?? ''),
                'unique' => (bool) ($row['unique'] ?? false),
            ];
            break;
        }
        $quotedTable = $connector->quoteTable($this->sessionModel->getTable());
        $ddl = $connector->buildAlterModifyColumnSql($quotedTable, $declared, $existingCol);
        foreach (\preg_split('/;\s*\R/m', \trim($ddl)) ?: [] as $piece) {
            $sql = \trim((string) $piece);
            if ($sql === '') {
                continue;
            }
            if (!\str_ends_with($sql, ';')) {
                $sql .= ';';
            }
            $connector->query($sql)->fetch();
        }

        // 修复序列值：确保序列值大于当前最大 ID
        try {
            // 从列信息中提取序列名
            $columnDefault = $existingCol['default'] ?? null;
            if (!$columnDefault || !\preg_match("/nextval\('([^']+)'/", $columnDefault, $matches)) {
                return;
            }

            $sequenceName = $matches[1];

            // 获取当前最大 ID（使用 ORM）
            $session = clone $this->sessionModel;
            $maxIdRow = $session->clearData()->clearQuery()
                ->select("MAX({$pk}) as max_id")
                ->fetch();
            $maxId = (int) ($maxIdRow['max_id'] ?? 0);
            $nextId = $maxId + 1;

            // 重置序列
            $resetSeqSql = "SELECT setval('{$sequenceName}', {$nextId}, false)";
            $connector->query($resetSeqSql)->fetch();
        } catch (\Throwable $e) {
            // 序列修复失败，静默处理
        }
    }
}
