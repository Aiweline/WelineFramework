<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 建站工作台会话：持久化 scope、阶段、站点/虚拟主题关联与发布状态
 */

namespace GuoLaiRen\PageBuilder\Model;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI建站工作台会话')]
#[Index(name: 'idx_public_id', columns: ['public_id'], comment: '对外令牌')]
#[Index(name: 'idx_admin_user', columns: ['admin_user_id'], comment: '后台用户')]
#[Index(name: 'idx_website', columns: ['website_id'], comment: '站点')]
#[Index(name: 'idx_virtual_theme', columns: ['virtual_theme_id'], comment: 'PageBuilder 虚拟主题')]
class AiSiteAgentSession extends Model
{
    private const SCOPE_LOG_MAX_ITEMS = 80;
    private const SCOPE_LOG_MESSAGE_MAX_LEN = 800;
    private const ASSET_IMAGE_FAILURE_MAX_ITEMS = 80;
    private const ASSET_IMAGE_FAILURE_MESSAGE_MAX_LEN = 800;
    private const WORKSPACE_TRACK_VIRTUAL_THEME = 'virtual_theme';
    /**
     * build_tasks should keep mutable execution state only; task definitions live
     * in build_blueprint.tasks and are rejoined at runtime.
     *
     * @var array<string, true>
     */
    private const BUILD_TASK_DUPLICATE_STATE_KEYS = [
        'task_type' => true,
        'group_key' => true,
        'page_type' => true,
        'section_code' => true,
        'dependencies' => true,
        'can_parallel' => true,
        'progress_weight' => true,
        'runtime_context' => true,
        'plan_context' => true,
        'task_script' => true,
        'block_task' => true,
        'implementation_contract' => true,
    ];
    private const BUILD_RUNTIME_SHARED_CONTEXT_KEYS = [
        'theme_context_snapshot' => true,
        'shared_prompt_context' => true,
    ];
    private const ARTIFACT_BACKED_SCOPE_PATHS = [
        self::STAGE_PLAN => [
            'plan_json' => [['plan_json'], []],
            'plan_structured' => [['plan_structured'], []],
            'build_plan_v2' => [['build_plan_v2'], []],
            'plan_projection' => [['plan_projection'], []],
            'content_manifest' => [['content_manifest'], []],
            'execution_blueprint' => [['execution_blueprint'], []],
            'plan_workbench' => [['plan_workbench'], []],
        ],
        self::STAGE_VISUAL_EDIT => [
            'plan_json' => [['plan_json'], []],
            'plan_structured' => [['plan_structured'], []],
            'build_plan_v2' => [['build_plan_v2'], []],
            'plan_projection' => [['plan_projection'], []],
            'content_manifest' => [['content_manifest'], []],
            'execution_blueprint' => [['execution_blueprint'], []],
            'plan_workbench' => [['plan_workbench'], []],
            'build_blueprint' => [['build_blueprint'], []],
            'build_workbench' => [['build_workbench'], []],
            'build_contracts' => [['build_contracts'], []],
            'render_data_contract' => [['render_data_contract'], []],
            'task_results' => [['task_results'], []],
            'qa_report' => [['qa_report_v2'], []],
            'repair_patch' => [['repair_patch'], []],
        ],
    ];
    private ?string $scopeJsonDecodeCacheRaw = null;
    /** @var array<string, mixed> */
    private array $scopeJsonDecodeCacheData = [];
    private bool $scopeLazyLoading = false;

    public const schema_table = 'guolairen_page_builder_ai_site_agent_session';
    public const schema_primary_key = 'ai_site_agent_session_id';

    public const STAGE_BRIEF = 'brief';
    public const STAGE_DOMAIN = 'domain';
    public const STAGE_DOMAIN_WAIT = 'domain_wait';
    public const STAGE_PLAN = 'plan';
    public const STAGE_PAGE_TYPES = 'page_types';
    public const STAGE_CONTENT = 'content';
    public const STAGE_VISUAL_EDIT = 'visual_edit';
    public const STAGE_PUBLISH = 'publish';

    public const PUBLISH_STATUS_DRAFT = 'draft';
    public const PUBLISH_STATUS_PUBLISHING = 'publishing';
    public const PUBLISH_STATUS_PUBLISHED = 'published';
    public const PUBLISH_STATUS_FAILED = 'failed';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '会话主键')]
    public const schema_fields_ID = 'ai_site_agent_session_id';

    #[Col(type: 'varchar', length: 32, nullable: false, unique: true, comment: '对外会话令牌(前端/API)')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'int', nullable: false, comment: '后台用户ID')]
    public const schema_fields_ADMIN_USER_ID = 'admin_user_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '关联站点ID，0 表示未绑定')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'PageBuilder 虚拟主题 ID，0 表示未创建')]
    public const schema_fields_VIRTUAL_THEME_ID = 'virtual_theme_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: self::STAGE_BRIEF, comment: '当前流程阶段')]
    public const schema_fields_STAGE = 'stage';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::PUBLISH_STATUS_DRAFT, comment: '发布状态')]
    public const schema_fields_PUBLISH_STATUS = 'publish_status';

    #[Col(type: 'longtext', nullable: true, comment: 'Scope JSON（站点简报、域名、页面类型、多语言片段等）')]
    public const schema_fields_SCOPE_JSON = 'scope_json';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int) ($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getPublicId(): string
    {
        return (string) ($this->getData(self::schema_fields_PUBLIC_ID) ?: '');
    }

    public function getAdminUserId(): int
    {
        return (int) ($this->getData(self::schema_fields_ADMIN_USER_ID) ?: 0);
    }

    public function getWebsiteId(): int
    {
        return (int) ($this->getData(self::schema_fields_WEBSITE_ID) ?: 0);
    }

    public function getVirtualThemeId(): int
    {
        return (int) ($this->getData(self::schema_fields_VIRTUAL_THEME_ID) ?: 0);
    }

    public function setVirtualThemeId(int $themeId): static
    {
        return $this->setData(self::schema_fields_VIRTUAL_THEME_ID, $themeId);
    }

    public function getStage(): string
    {
        return (string) ($this->getData(self::schema_fields_STAGE) ?: self::STAGE_BRIEF);
    }

    public function getPublishStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_PUBLISH_STATUS) ?: self::PUBLISH_STATUS_DRAFT);
    }

    /**
     * @return array<string, mixed>
     */
    public function getScopeArray(): array
    {
        $raw = $this->getData(self::schema_fields_SCOPE_JSON);
        if ($raw === null || $raw === '') {
            if ($this->scopeJsonDecodeCacheRaw === '__lazy_scope__') {
                return $this->scopeJsonDecodeCacheData;
            }
            return $this->loadScopeArrayLazily();
        }
        if (!\is_string($raw)) {
            return [];
        }
        if ($this->scopeJsonDecodeCacheRaw !== null && $this->scopeJsonDecodeCacheRaw === $raw) {
            return $this->scopeJsonDecodeCacheData;
        }
        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $result = \is_array($decoded) ? $decoded : [];
        $this->scopeJsonDecodeCacheRaw = $raw;
        $this->scopeJsonDecodeCacheData = $result;

        return $result;
    }

    /**
     * Metadata-only session loads intentionally do not select scope_json. Preserve
     * legacy callers by loading only the current stage fragment on demand.
     *
     * @return array<string, mixed>
     */
    private function loadScopeArrayLazily(): array
    {
        if ($this->scopeLazyLoading || $this->getId() <= 0 || $this->getAdminUserId() <= 0) {
            return [];
        }

        $this->scopeLazyLoading = true;
        try {
            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            $scope = $sessionService->loadScopeForStage($this, $this->getStage());
        } catch (\Throwable) {
            $scope = [];
        } finally {
            $this->scopeLazyLoading = false;
        }

        $this->scopeJsonDecodeCacheRaw = '__lazy_scope__';
        $this->scopeJsonDecodeCacheData = \is_array($scope) ? $scope : [];

        return $this->scopeJsonDecodeCacheData;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function setScopeArray(array $scope): static
    {
        $scope = $this->compactScopeBeforeStorage($scope);
        try {
            $json = $scope === []
                ? '{}'
                : (string)\json_encode(
                    $scope,
                    \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR
                );
        } catch (\JsonException) {
            $json = (string)($this->getData(self::schema_fields_SCOPE_JSON) ?? '{}');
        }
        $this->scopeJsonDecodeCacheRaw = $json;
        $this->scopeJsonDecodeCacheData = $scope;
        return $this->setData(self::schema_fields_SCOPE_JSON, $json);
    }

    public function setScopeJsonRaw(string $scopeJson): static
    {
        $scopeJson = \trim($scopeJson);
        if ($scopeJson === '') {
            $scopeJson = '{}';
        }

        $this->scopeJsonDecodeCacheRaw = null;
        $this->scopeJsonDecodeCacheData = [];

        return $this->setData(self::schema_fields_SCOPE_JSON, $scopeJson);
    }

    /**
     * 仅裁剪日志类冗余数据，避免 scope_json 在 worker 常驻进程中持续膨胀。
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function compactScopeForStorage(array $scope): array
    {
        foreach (['events', 'top_logs'] as $field) {
            $scope[$field] = $this->compactScopeLogEntries($scope[$field] ?? []);
        }
        $scope['asset_image_generation_failures'] = $this->compactAssetImageGenerationFailures(
            $scope['asset_image_generation_failures'] ?? []
        );

        $scope = $this->stripArtifactBackedPayloadsForStorage($scope);
        $scope = $this->compactBuildArtifactsBeforeStorage($scope);

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactScopeBeforeStorage(array $scope): array
    {
        return $this->compactScopeForStorage($scope);
    }

    /**
     * Artifact references make the artifact table the canonical storage for
     * these large payloads. Do not write hydrated copies back into scope_json.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function stripArtifactBackedPayloadsForStorage(array $scope): array
    {
        $refs = \is_array($scope['_artifact_refs'] ?? null) ? $scope['_artifact_refs'] : [];
        if ($refs === []) {
            return $scope;
        }

        foreach (self::ARTIFACT_BACKED_SCOPE_PATHS as $stageCode => $artifactPaths) {
            $stageRefs = \is_array($refs[$stageCode] ?? null) ? $refs[$stageCode] : [];
            if ($stageRefs === []) {
                continue;
            }
            foreach ($artifactPaths as $artifactKey => $pathConfig) {
                if (!\is_array($stageRefs[$artifactKey] ?? null)) {
                    continue;
                }
                [$path, $emptyValue] = $pathConfig;
                $scope = $this->setScopePathForStorage($scope, $path, $emptyValue);
            }
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $path
     * @return array<string, mixed>
     */
    private function setScopePathForStorage(array $scope, array $path, mixed $value): array
    {
        if ($path === []) {
            return $scope;
        }

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
     * @return array<string, mixed>
     */
    private function compactBuildArtifactsBeforeStorage(array $scope): array
    {
        if (\trim((string)($scope['workspace_track'] ?? '')) !== self::WORKSPACE_TRACK_VIRTUAL_THEME) {
            return $scope;
        }

        foreach (['shared_components', '_ai_generated_shared_components'] as $field) {
            if (!\is_array($scope[$field] ?? null)) {
                continue;
            }
            foreach ($scope[$field] as $key => $component) {
                if (!\is_array($component)) {
                    continue;
                }
                $scope[$field][$key] = $this->compactGeneratedComponentForStorage($component);
            }
        }

        if (\is_array($scope['virtual_pages_by_type'] ?? null)) {
            foreach ($scope['virtual_pages_by_type'] as $pageType => $virtualPage) {
                if (!\is_array($virtualPage)) {
                    continue;
                }
                if (\is_array($virtualPage['blocks'] ?? null) && $virtualPage['blocks'] !== []) {
                    $virtualPage['blocks'] = [];
                    $scope['virtual_pages_by_type'][$pageType] = $virtualPage;
                }
            }
        }

        $scope = $this->compactPlanWorkbenchSnapshotsForStorage($scope);
        $scope = $this->compactConfirmedStageOneExecutionBlueprintSnapshotForStorage($scope);
        $scope = $this->compactConfirmedStageOnePlanPayloadsForStorage($scope);
        $scope = $this->compactConfirmedBuildBlueprintTaskRuntimeForStorage($scope);
        $scope = $this->compactConfirmedBuildTaskStateForStorage($scope);
        $scope = $this->removePersistentSnapshotBackupsForStorage($scope);

        return $scope;
    }

    /**
     * Keep only the latest confirmed stage-one snapshot plus the request summary
     * needed by later stages.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactPlanWorkbenchSnapshotsForStorage(array $scope): array
    {
        $planWorkbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
        $confirmed = \is_array($planWorkbench['confirmed'] ?? null) ? $planWorkbench['confirmed'] : [];
        $stageOne = \is_array($planWorkbench['stage1'] ?? null) ? $planWorkbench['stage1'] : [];
        if ($confirmed === []) {
            return $scope;
        }

        if ((int)($scope['plan_confirmed'] ?? 0) === 1) {
            $scope = $this->materializeConfirmedStageOnePlanArtifactsForStorage($scope, $confirmed);
        }

        if ($stageOne !== []) {
            $slimStageOne = [];
            $requestSummary = \is_array($stageOne['request_summary'] ?? null) ? $stageOne['request_summary'] : [];
            if ($requestSummary !== []) {
                $slimStageOne['request_summary'] = $requestSummary;
            }
            $progress = \is_array($stageOne['progress'] ?? null) ? $stageOne['progress'] : [];
            if ($progress !== []) {
                $slimStageOne['progress'] = $progress;
            }

            $planWorkbench['stage1'] = $slimStageOne;
        }
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $executionBlueprintDraft = \is_array($scope['execution_blueprint_draft'] ?? null) ? $scope['execution_blueprint_draft'] : [];
        if (($executionBlueprint !== [] || $executionBlueprintDraft !== []) && \is_array($planWorkbench['confirmed']['execution_blueprint'] ?? null)) {
            unset($planWorkbench['confirmed']['execution_blueprint']);
        }
        if ((int)($scope['plan_confirmed'] ?? 0) === 1 || $this->hasMaterializedStageOnePlanArtifacts($scope)) {
            $planWorkbench['confirmed'] = $this->compactPlanWorkbenchConfirmedForStorage($planWorkbench['confirmed']);
        }
        $scope['plan_workbench'] = $planWorkbench;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function hasMaterializedStageOnePlanArtifacts(array $scope): bool
    {
        foreach (['plan_structured', 'plan_json', 'execution_blueprint', 'execution_blueprint_draft'] as $key) {
            if (\is_array($scope[$key] ?? null) && $scope[$key] !== []) {
                return true;
            }
        }

        return \trim((string)($scope['plan_markdown'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $confirmed
     * @return array<string, mixed>
     */
    private function materializeConfirmedStageOnePlanArtifactsForStorage(array $scope, array $confirmed): array
    {
        if (
            (!\is_array($scope['plan_structured'] ?? null) || $scope['plan_structured'] === [])
            && \is_array($confirmed['structured_plan'] ?? null)
            && $confirmed['structured_plan'] !== []
        ) {
            $scope['plan_structured'] = $confirmed['structured_plan'];
        }
        if (
            (!\is_array($scope['plan_json'] ?? null) || $scope['plan_json'] === [])
            && \is_array($confirmed['plan_json'] ?? null)
            && $confirmed['plan_json'] !== []
        ) {
            $scope['plan_json'] = $confirmed['plan_json'];
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $confirmed
     * @return array<string, mixed>
     */
    private function extractConfirmedStageOnePlanBook(array $confirmed): array
    {
        foreach ([$confirmed['plan_book']['structured'] ?? null, $confirmed['plan_book'] ?? null] as $candidate) {
            if (\is_array($candidate) && $this->looksLikeConfirmedStageOnePlanBook($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $planBook
     */
    private function looksLikeConfirmedStageOnePlanBook(array $planBook): bool
    {
        return \is_array($planBook['pages'] ?? null)
            || \is_array($planBook['shared_blocks'] ?? null)
            || (string)($planBook['source'] ?? '') === 'stage1.block_tree';
    }

    /**
     * Once stage one is confirmed, keep a single confirmed execution blueprint
     * copy and drop stale draft mirrors.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedStageOneExecutionBlueprintSnapshotForStorage(array $scope): array
    {
        if ((int)($scope['plan_confirmed'] ?? 0) !== 1) {
            return $scope;
        }

        $confirmed = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $draft = \is_array($scope['execution_blueprint_draft'] ?? null) ? $scope['execution_blueprint_draft'] : [];
        if ($confirmed === [] || $draft === []) {
            return $scope;
        }

        $scope['execution_blueprint_draft'] = [];

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedStageOnePlanPayloadsForStorage(array $scope): array
    {
        if ((int)($scope['plan_confirmed'] ?? 0) !== 1) {
            return $scope;
        }

        return $scope;
    }

    /**
     * Build-plan runtime snapshots are identical across tasks. Store them once
     * at scope level, then keep task definitions focused on task-specific data.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedBuildBlueprintTaskRuntimeForStorage(array $scope): array
    {
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $tasks = \is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [];
        if ((string)($buildBlueprint['source'] ?? '') !== 'build_plan_v2' || $tasks === []) {
            return $scope;
        }

        $changed = false;
        foreach (self::BUILD_RUNTIME_SHARED_CONTEXT_KEYS as $key => $_) {
            if (\array_key_exists($key, $buildBlueprint)) {
                $changed = true;
            }
        }
        foreach ($tasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }

            foreach (self::BUILD_RUNTIME_SHARED_CONTEXT_KEYS as $key => $_) {
                if (\array_key_exists($key, $task)) {
                    unset($task[$key]);
                    $changed = true;
                }
            }

            $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
            if ($runtimeContext !== []) {
                $runtimeContext = $this->stripTaskRuntimeSharedContextForStorage($runtimeContext);
                if ($runtimeContext === []) {
                    unset($task['runtime_context']);
                } else {
                    $task['runtime_context'] = $runtimeContext;
                }
                $changed = true;
            }

            $tasks[$idx] = $task;
        }

        if ($changed) {
            unset(
                $buildBlueprint['theme_context_snapshot'],
                $buildBlueprint['shared_prompt_context']
            );
            $buildBlueprint['tasks'] = $tasks;
            $scope['build_blueprint'] = $buildBlueprint;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedBuildTaskStateForStorage(array $scope): array
    {
        $buildTasks = \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [];
        if ($buildTasks === []) {
            return $scope;
        }

        $buildTaskDefinitions = $this->indexBuildBlueprintTasksByTaskKey(
            \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : []
        );
        foreach ($buildTasks as $taskKey => $taskState) {
            if (!\is_array($taskState)) {
                continue;
            }

            $taskKey = (string)$taskKey;
            foreach (self::BUILD_TASK_DUPLICATE_STATE_KEYS as $key => $_) {
                unset($taskState[$key]);
            }

            $runtimeContext = \is_array($taskState['runtime_context'] ?? null) ? $taskState['runtime_context'] : [];
            $definitionRuntimeContext = \is_array($buildTaskDefinitions[$taskKey]['runtime_context'] ?? null)
                ? $buildTaskDefinitions[$taskKey]['runtime_context']
                : [];
            if ($runtimeContext === [] || $runtimeContext == $definitionRuntimeContext) {
                unset($taskState['runtime_context']);
            }

            if (isset($taskState['result_ref']) && !\is_array($taskState['result_ref'])) {
                $taskState['result_ref'] = [];
            }
            if (isset($taskState['message']) && !\is_scalar($taskState['message'])) {
                $taskState['message'] = '';
            }

            $buildTasks[$taskKey] = $taskState;
        }

        $scope['build_tasks'] = $buildTasks;

        return $scope;
    }

    /**
     * @param array<string, mixed> $confirmed
     * @return array<string, mixed>
     */
    private function compactPlanWorkbenchConfirmedForStorage(array $confirmed): array
    {
        $slim = [];
        foreach ([
            'signature',
            'plan_signature',
            'content_locale',
            'plan_locale',
            'source',
            'version',
            'generated_at',
            'confirmed_at',
            'updated_at',
            'summary',
        ] as $key) {
            if (\array_key_exists($key, $confirmed)) {
                $slim[$key] = $confirmed[$key];
            }
        }

        if (\is_array($confirmed['structured_plan'] ?? null) || \is_array($confirmed['plan_json'] ?? null)) {
            $slim['structured_plan_ref'] = ['storage_compacted' => 1];
        }
        if (\is_array($confirmed['plan_book'] ?? null)) {
            $slim['plan_book_ref'] = ['field' => 'plan_json'];
        }
        $slim['_storage_compacted'] = 1;

        return $slim;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function removePersistentSnapshotBackupsForStorage(array $scope): array
    {
        unset(
            $scope['confirmed_stage1_plan_book'],
            $scope['theme_context_snapshot'],
            $scope['shared_prompt_context']
        );

        foreach (['build_blueprint'] as $rootKey) {
            if (\is_array($scope[$rootKey] ?? null)) {
                $scope[$rootKey] = $this->stripSnapshotBackupsFromPlanTree($scope[$rootKey]);
            }
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function stripSnapshotBackupsFromPlanTree(array $tree): array
    {
        unset(
            $tree['theme_context_snapshot'],
            $tree['shared_prompt_context'],
            $tree['confirmed_stage1_plan_book']
        );

        if (\is_array($tree['runtime_context'] ?? null)) {
            $tree['runtime_context'] = $this->stripTaskRuntimeSharedContextForStorage($tree['runtime_context']);
            if ($tree['runtime_context'] === []) {
                unset($tree['runtime_context']);
            }
        }

        foreach (['tasks', 'shared_tasks'] as $listKey) {
            if (!\is_array($tree[$listKey] ?? null)) {
                continue;
            }
            foreach ($tree[$listKey] as $idx => $item) {
                if (\is_array($item)) {
                    $tree[$listKey][$idx] = $this->stripSnapshotBackupsFromPlanTree($item);
                }
            }
        }

        foreach (['page_tasks', 'pages'] as $mapKey) {
            if (!\is_array($tree[$mapKey] ?? null)) {
                continue;
            }
            foreach ($tree[$mapKey] as $key => $value) {
                if (\is_array($value)) {
                    $tree[$mapKey][$key] = $this->stripSnapshotBackupsFromPlanTree($value);
                }
            }
        }

        if (\is_array($tree['execution_blueprint']['tasks'] ?? null)) {
            foreach ($tree['execution_blueprint']['tasks'] as $idx => $task) {
                if (\is_array($task)) {
                    $tree['execution_blueprint']['tasks'][$idx] = $this->stripSnapshotBackupsFromPlanTree($task);
                }
            }
        }

        if (\is_array($tree['execution_blueprint']['task_groups'] ?? null)) {
            $tree['execution_blueprint']['task_groups'] = $this->stripSnapshotBackupsFromPlanTree($tree['execution_blueprint']['task_groups']);
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $runtimeContext
     * @return array<string, mixed>
     */
    private function stripTaskRuntimeSharedContextForStorage(array $runtimeContext): array
    {
        unset(
            $runtimeContext['theme_context_snapshot'],
            $runtimeContext['shared_prompt_context']
        );

        return $runtimeContext;
    }

    /**
     * @param array<string, mixed> $component
     * @return array<string, mixed>
     */
    private function compactGeneratedComponentForStorage(array $component): array
    {
        unset($component['phtml'], $component['html'], $component['ai_data']);

        foreach (['default_config', 'config'] as $field) {
            if (\is_array($component[$field] ?? null)) {
                $component[$field] = $this->compactGeneratedComponentConfigForStorage($component[$field]);
            }
        }

        return $component;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function compactGeneratedComponentConfigForStorage(array $config): array
    {
        foreach ($config as $key => $value) {
            $stringKey = (string)$key;
            if ($stringKey === 'html_content' || \str_starts_with($stringKey, '_pb_server_')) {
                unset($config[$key]);
                continue;
            }
            if (\is_array($value)) {
                $config[$key] = $this->compactGeneratedComponentConfigForStorage($value);
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $buildBlueprint
     * @return array<string, array<string, mixed>>
     */
    private function indexBuildBlueprintTasksByTaskKey(array $buildBlueprint): array
    {
        $indexed = [];
        foreach (\is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [] as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $indexed[$taskKey] = $task;
        }

        return $indexed;
    }

    /**
     * @param mixed $entries
     * @return list<array<string, mixed>>
     */
    private function compactScopeLogEntries(mixed $entries): array
    {
        if (!\is_array($entries) || $entries === []) {
            return [];
        }
        $entries = \array_values(\array_filter($entries, static fn($entry): bool => \is_array($entry)));
        if (\count($entries) > self::SCOPE_LOG_MAX_ITEMS) {
            $entries = \array_slice($entries, -self::SCOPE_LOG_MAX_ITEMS);
        }

        foreach ($entries as &$entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $message = \trim((string)($entry['message'] ?? ''));
            if ($message !== '' && \mb_strlen($message) > self::SCOPE_LOG_MESSAGE_MAX_LEN) {
                $entry['message'] = \mb_substr($message, 0, self::SCOPE_LOG_MESSAGE_MAX_LEN) . '...';
            }
            if (isset($entry['payload']) && \is_array($entry['payload'])) {
                if (isset($entry['payload']['message']) && \is_string($entry['payload']['message'])) {
                    $payloadMessage = \trim($entry['payload']['message']);
                    if ($payloadMessage !== '' && \mb_strlen($payloadMessage) > self::SCOPE_LOG_MESSAGE_MAX_LEN) {
                        $entry['payload']['message'] = \mb_substr($payloadMessage, 0, self::SCOPE_LOG_MESSAGE_MAX_LEN) . '...';
                    }
                }
            }
        }
        unset($entry);

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compactAssetImageGenerationFailures(mixed $entries): array
    {
        if (!\is_array($entries) || $entries === []) {
            return [];
        }

        $entries = \array_values(\array_filter($entries, static fn($entry): bool => \is_array($entry)));
        if (\count($entries) > self::ASSET_IMAGE_FAILURE_MAX_ITEMS) {
            $entries = \array_slice($entries, -self::ASSET_IMAGE_FAILURE_MAX_ITEMS);
        }

        foreach ($entries as &$entry) {
            $slotId = \trim((string)($entry['slot_id'] ?? $entry['slotId'] ?? ''));
            if ($slotId !== '') {
                $entry['slot_id'] = $slotId;
            }
            unset($entry['slotId']);

            $message = \trim((string)($entry['message'] ?? ''));
            if ($message !== '' && \mb_strlen($message) > self::ASSET_IMAGE_FAILURE_MESSAGE_MAX_LEN) {
                $entry['message'] = \mb_substr($message, 0, self::ASSET_IMAGE_FAILURE_MESSAGE_MAX_LEN) . '...';
            }
        }
        unset($entry);

        return $entries;
    }
}
