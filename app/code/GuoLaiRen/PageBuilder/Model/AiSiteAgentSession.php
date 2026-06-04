<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 建站工作台会话：持久化 scope、阶段、站点/虚拟主题关联与发布状态
 */

namespace GuoLaiRen\PageBuilder\Model;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
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
    private const ARTIFACT_BACKED_SCOPE_PATHS = [
        self::STAGE_PLAN => [
            'plan_json' => [['plan_json'], []],
            'content_manifest' => [['content_manifest'], []],
        ],
        self::STAGE_VISUAL_EDIT => [
            'plan_json' => [['plan_json'], []],
            'content_manifest' => [['content_manifest'], []],
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
     * existing callers by loading only the current stage fragment on demand.
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
        $scope = (new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer()))
            ->stripUnsupportedScopeArtifactKeys($scope);

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
                if (\is_array($virtualPage['block_nodes'] ?? null) && $virtualPage['block_nodes'] !== []) {
                    $virtualPage['block_nodes'] = [];
                    $scope['virtual_pages_by_type'][$pageType] = $virtualPage;
                }
            }
        }

        $scope = $this->removeDuplicatedStageOneFieldsForStorage($scope);

        return $scope;
    }

    /**
     * @param array<string, mixed> $confirmed
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function removeDuplicatedStageOneFieldsForStorage(array $scope): array
    {
        return AiSiteScopeCompatibilityService::stripDuplicatedStageOneStorageFields($scope);
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
