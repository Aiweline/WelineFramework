<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionSelection;

/**
 * Theme-scope version CRUD for shared chrome authority (theme_id + scope, no page_type).
 */
final class ThemeScopeVersionService
{
    public function __construct(
        private readonly ThemeScopeVersion $versionModel,
    ) {
    }

    public function ensureCurrent(
        int $themeId,
        string $scope,
        string $scopeKind = 'website',
        ?int $websiteId = null,
        string $storeMode = 'normal',
    ): ThemeScopeVersion {
        $scope = $this->normalizeScope($scope);
        if ($themeId < 1 || $scope === '') {
            throw new \InvalidArgumentException((string)__('Theme 范围版本参数无效。'));
        }

        $current = $this->getCurrent($themeId, $scope);
        if ($current instanceof ThemeScopeVersion) {
            return $current;
        }

        // Orphan rows may exist with is_current=0 (unique on version_number still holds).
        $orphan = $this->loadLatestForScope($themeId, $scope);
        if ($orphan instanceof ThemeScopeVersion) {
            $this->unsetCurrent($themeId, $scope);
            $orphan->setIsCurrent(true)->save();
            $this->forgetFlagged($themeId, $scope);
            // 仅在已有 selection 行时同步草稿指针；不新建行以免把草稿冒充成已发布。
            $this->persistSelectionDraft(
                $themeId,
                $scope,
                $orphan->getStoreMode(),
                $orphan->getArea(),
                $orphan->getVersionId(),
                0,
                false,
            );

            return $orphan;
        }

        $version = clone $this->versionModel;
        $version->reset()
            ->clearData()
            ->setThemeId($themeId)
            ->setScope($scope)
            ->setScopeKind($scopeKind)
            ->setWebsiteId($websiteId)
            ->setStoreMode($storeMode !== '' ? $storeMode : 'normal')
            ->setArea('frontend')
            ->setVersionNumber(1)
            ->setVersionName('v1')
            ->setVersionType(ThemeScopeVersion::TYPE_MANUAL)
            ->setLifecycle(ThemeScopeVersion::LIFECYCLE_DRAFT)
            ->setContentRevision(0)
            ->setCreationSourceKind(\Weline\Theme\Api\Version\ThemeVersionPublicationInterface::CREATION_PACKAGE_DEFAULTS)
            ->setChromePayload([])
            ->setStructureKey($this->hashStructure([]))
            ->setParentVersionId(null)
            ->setIsCurrent(true)
            ->setIsPublished(false)
            ->save();

        // 仅在已有 selection 行时同步草稿指针（见 orphan 分支说明）。
        $this->persistSelectionDraft(
            $themeId,
            $scope,
            $version->getStoreMode(),
            $version->getArea(),
            $version->getVersionId(),
            0,
            false,
        );

        return $version;
    }

    private function loadLatestForScope(int $themeId, string $scope): ?ThemeScopeVersion
    {
        $result = $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if (!\is_array($result) || $result === []) {
            return null;
        }

        $row = \is_array($result[0] ?? null) ? $result[0] : $result;
        $version = clone $this->versionModel;
        $version->setData($row);

        return $version->getVersionId() > 0 ? $version : null;
    }

    /**
     * 当前编辑版本：selection 是唯一可变权威（优先草稿，其次该 owner 已发布版本）。
     *
     * 尚未建立 selection 行的 owner（历史/仅 legacy 标志）继续回落 is_current，
     * 保证硬切前不会因缺 selection 而解析不到版本（否则 ensureCurrent 会重复建版本）。
     */
    public function getCurrent(
        int $themeId,
        string $scope,
        string $storeMode = 'normal',
        string $area = 'frontend',
    ): ?ThemeScopeVersion {
        $scope = $this->normalizeScope($scope);
        if ($themeId < 1 || $scope === '') {
            return null;
        }

        $selection = $this->loadSelection($themeId, $scope, $storeMode, $area);
        foreach ([$selection['draft_version_id'] ?? null, $selection['published_version_id'] ?? null] as $candidateId) {
            if ($candidateId === null || $candidateId === '' || (int)$candidateId < 1) {
                continue;
            }
            $version = $this->loadVersionById((int)$candidateId, $themeId, $scope, $storeMode, $area);
            if ($version instanceof ThemeScopeVersion) {
                return $version;
            }
        }

        return $this->loadFlagged($themeId, $scope, ThemeScopeVersion::schema_fields_IS_CURRENT);
    }

    /**
     * Resolve published chrome version for scope, walking ancestors by trimming
     * the last dotted segment (a.b.c → a.b → a) until found or exhausted.
     *
     * 每个祖先按「selection 权威优先、legacy 标志回落」解析，保持原有逐级回落语义。
     */
    public function getPublished(
        int $themeId,
        string $scope,
        string $storeMode = 'normal',
        string $area = 'frontend',
    ): ?ThemeScopeVersion {
        $scope = $this->normalizeScope($scope);
        if ($themeId < 1 || $scope === '') {
            return null;
        }

        foreach ($this->scopeAncestorChain($scope) as $candidate) {
            $selection = $this->loadSelection($themeId, $candidate, $storeMode, $area);
            $publishedId = (int)($selection['published_version_id'] ?? 0);
            if ($publishedId > 0) {
                $version = $this->loadVersionById($publishedId, $themeId, $candidate, $storeMode, $area);
                if ($version instanceof ThemeScopeVersion) {
                    return $version;
                }
            }

            $version = $this->loadFlagged(
                $themeId,
                $candidate,
                ThemeScopeVersion::schema_fields_IS_PUBLISHED,
            );
            if ($version instanceof ThemeScopeVersion) {
                return $version;
            }
        }

        return null;
    }

    public function markPublished(ThemeScopeVersion $version): void
    {
        $themeId = $version->getThemeId();
        $scope = $this->normalizeScope($version->getScope());
        if ($themeId < 1 || $scope === '' || !$version->getVersionId()) {
            throw new \InvalidArgumentException((string)__('Theme 范围发布版本无效。'));
        }

        $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersion::schema_fields_IS_PUBLISHED, 1)
            ->update([ThemeScopeVersion::schema_fields_IS_PUBLISHED => 0])
            ->fetch();

        $version->setIsPublished(true)->save();
        $this->forgetFlagged($themeId, $scope);

        // selection 是唯一可变权威：同步已发布指针，否则读者仍会解析到旧版本。
        $this->persistSelectionPublished(
            $themeId,
            $scope,
            $version->getStoreMode(),
            $version->getArea(),
            $version->getVersionId(),
        );

        try {
            /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver $pointers */
            $pointers = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver::class,
            );
            $pointers->invalidateChrome($themeId, $scope);
            // Leaf scopes inherit published chrome; flush their cached pointers too.
            foreach (['default.__store__.default', 'default.__store__.__channel__', 'default.__website__.default', 'default.default.default'] as $leaf) {
                if ($leaf !== $scope) {
                    $pointers->invalidateChrome($themeId, $leaf);
                }
            }
        } catch (\Throwable) {
            // Pointer invalidation is best-effort; ThemeRuntimeCacheCleaner may also bump.
        }
    }

    /**
     * Persist full chrome nodes (including config) and refresh structure_key
     * from structure-only fields (excludes config body).
     *
     * @param array<string|int, mixed> $nodes
     */
    public function setChromePayload(ThemeScopeVersion $version, array $nodes): void
    {
        if (!$version->getVersionId()) {
            throw new \InvalidArgumentException((string)__('Theme 范围版本未持久化。'));
        }

        $normalized = $this->normalizeNodesMap($nodes);
        $version
            ->setChromePayload($normalized)
            ->setStructureKey($this->hashStructure($normalized))
            ->save();
        $this->forgetFlagged($version->getThemeId(), $this->normalizeScope((string)$version->getScope()));
    }

    public function createRevisionFrom(
        ThemeScopeVersion $source,
        string $name = '',
        string $actor = '',
    ): ThemeScopeVersion {
        $themeId = $source->getThemeId();
        $scope = $this->normalizeScope($source->getScope());
        if ($themeId < 1 || $scope === '' || !$source->getVersionId()) {
            throw new \InvalidArgumentException((string)__('Theme 范围修订源无效。'));
        }

        $nextNumber = $this->nextVersionNumber($themeId, $scope);
        $this->unsetCurrent($themeId, $scope);

        $revision = clone $this->versionModel;
        $revision->reset()
            ->clearData()
            ->setThemeId($themeId)
            ->setScope($scope)
            ->setScopeKind($source->getScopeKind())
            ->setWebsiteId($source->getWebsiteId())
            ->setStoreMode($source->getStoreMode())
            ->setArea($source->getArea())
            ->setVersionNumber($nextNumber)
            ->setVersionName($name !== '' ? $name : ('v' . $nextNumber))
            ->setVersionType(ThemeScopeVersion::TYPE_MANUAL)
            ->setLifecycle(ThemeScopeVersion::LIFECYCLE_DRAFT)
            ->setContentRevision(0)
            ->setCreationSourceKind(\Weline\Theme\Api\Version\ThemeVersionPublicationInterface::CREATION_CONTINUE_CURRENT)
            ->setChromePayload($source->getChromePayload())
            ->setStructureKey($source->getStructureKey() !== ''
                ? $source->getStructureKey()
                : $this->hashStructure($source->getChromePayload()))
            ->setParentVersionId($source->getVersionId())
            ->setIsCurrent(true)
            ->setIsPublished(false)
            ->setDescription($actor !== '' ? ('by ' . $actor) : null)
            ->save();

        // selection 是唯一可变权威：新修订必须成为该 owner 的当前草稿，
        // 否则 getCurrent 会一直返回旧版本，后续编辑/卸载决定会写到错误版本。
        $this->persistSelectionDraft(
            $themeId,
            $scope,
            $revision->getStoreMode(),
            $revision->getArea(),
            $revision->getVersionId(),
            $source->isPublished() ? $source->getVersionId() : 0,
        );

        return $revision;
    }

    private function loadFlagged(int $themeId, string $scope, string $flagField): ?ThemeScopeVersion
    {
        $cacheKey = $this->flaggedCacheKey($themeId, $scope, $flagField);
        $cached = RequestContext::get($cacheKey);
        if (\is_array($cached) && \array_key_exists('version', $cached)) {
            $version = $cached['version'];

            return $version instanceof ThemeScopeVersion ? $version : null;
        }

        $result = $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where($flagField, 1)
            ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if (!\is_array($result) || $result === []) {
            RequestContext::set($cacheKey, ['version' => null]);

            return null;
        }

        $row = \is_array($result[0] ?? null) ? $result[0] : $result;
        $version = clone $this->versionModel;
        $version->setData($row);
        $resolved = $version->getVersionId() > 0 ? $version : null;
        RequestContext::set($cacheKey, ['version' => $resolved]);

        return $resolved;
    }

    private function flaggedCacheKey(int $themeId, string $scope, string $flagField): string
    {
        return 'theme.scope_version.flag.' . $themeId . '|' . $scope . '|' . $flagField;
    }

    private function forgetFlagged(int $themeId, string $scope): void
    {
        foreach ([
            ThemeScopeVersion::schema_fields_IS_CURRENT,
            ThemeScopeVersion::schema_fields_IS_PUBLISHED,
        ] as $flagField) {
            RequestContext::set($this->flaggedCacheKey($themeId, $scope, $flagField), null);
        }
    }

    // ==================== selection 权威读取与维护 ====================

    /**
     * 读取 owner 的 selection 行（请求内 memo）。
     *
     * @return array{selection_id:int,published_version_id:int,draft_version_id:?int,selection_revision:int}|null
     */
    private function loadSelection(int $themeId, string $scope, string $storeMode, string $area): ?array
    {
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';
        $cacheKey = $this->selectionCacheKey($themeId, $scope, $storeMode, $area);
        $cached = RequestContext::get($cacheKey);
        if (\is_array($cached) && \array_key_exists('selection', $cached)) {
            $selection = $cached['selection'];

            return \is_array($selection) ? $selection : null;
        }

        $selection = $this->loadSelectionRow($themeId, $scope, $storeMode, $area);
        RequestContext::set($cacheKey, ['selection' => $selection]);

        return $selection;
    }

    /**
     * 直接查库读取 selection 行（不走 memo，供写路径复用）。
     *
     * @return array{selection_id:int,published_version_id:int,draft_version_id:?int,selection_revision:int}|null
     */
    private function loadSelectionRow(int $themeId, string $scope, string $storeMode, string $area): ?array
    {
        try {
            /** @var ThemeScopeVersionSelection $model */
            $model = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
            $rows = $model->clearQuery()->clearData()
                ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
                ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $scope)
                ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
                ->where(ThemeScopeVersionSelection::schema_fields_AREA, $area)
                ->limit(1)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($rows) || $rows === []) {
            return null;
        }
        $row = \array_is_list($rows) ? ($rows[0] ?? null) : $rows;
        if (!\is_array($row)) {
            return null;
        }
        $draftId = $row[ThemeScopeVersionSelection::schema_fields_DRAFT_VERSION_ID] ?? null;

        return [
            'selection_id' => (int)($row[ThemeScopeVersionSelection::schema_fields_ID] ?? 0),
            'published_version_id' => (int)($row[ThemeScopeVersionSelection::schema_fields_PUBLISHED_VERSION_ID] ?? 0),
            'draft_version_id' => ($draftId === null || $draftId === '') ? null : (int)$draftId,
            'selection_revision' => (int)($row[ThemeScopeVersionSelection::schema_fields_SELECTION_REVISION] ?? 0),
        ];
    }

    /**
     * 按主键读取版本行，并校验其属于目标 owner（避免 selection 指向跨 owner 版本）。
     */
    private function loadVersionById(
        int $versionId,
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
    ): ?ThemeScopeVersion {
        if ($versionId < 1) {
            return null;
        }
        try {
            $version = clone $this->versionModel;
            $version->reset()->clearData()->load($versionId);
        } catch (\Throwable) {
            return null;
        }
        if ($version->getVersionId() !== $versionId) {
            return null;
        }
        if ($version->getThemeId() !== $themeId || $version->getScope() !== $scope) {
            return null;
        }
        if ($version->getStoreMode() !== ($storeMode !== '' ? $storeMode : 'normal')) {
            return null;
        }
        $expectedArea = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';

        return $version->getArea() === $expectedArea ? $version : null;
    }

    private function selectionCacheKey(int $themeId, string $scope, string $storeMode, string $area): string
    {
        return 'theme.scope_version.selection.' . $themeId . '|' . $scope . '|' . $storeMode . '|' . $area;
    }

    private function forgetSelection(int $themeId, string $scope, string $storeMode, string $area): void
    {
        RequestContext::set($this->selectionCacheKey($themeId, $scope, $storeMode, $area), null);
    }

    /**
     * 让 owner 的当前草稿指向 $draftVersionId（selection 权威写入）。
     * published 缺省时保留原值；确无 published 时以 $fallbackPublishedVersionId 兜底（模型要求 ≥1）。
     */
    private function persistSelectionDraft(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
        int $draftVersionId,
        int $fallbackPublishedVersionId = 0,
        bool $createIfMissing = true,
    ): void {
        if ($themeId < 1 || $draftVersionId < 1) {
            return;
        }
        $scope = $this->normalizeScope($scope);
        if ($scope === '') {
            return;
        }
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';

        try {
            $existing = $this->loadSelectionRow($themeId, $scope, $storeMode, $area);
            if ($existing === null && !$createIfMissing) {
                // 无 selection 行且不允许新建：不发明 published，读取端继续回落 legacy 标志。
                return;
            }
            /** @var ThemeScopeVersionSelection $model */
            $model = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
            $model->clearQuery()->clearData();
            if ($existing !== null && $existing['selection_id'] > 0) {
                $model->load($existing['selection_id']);
            } else {
                $published = $fallbackPublishedVersionId > 0 ? $fallbackPublishedVersionId : $draftVersionId;
                $model->setData(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId);
                $model->setData(ThemeScopeVersionSelection::schema_fields_SCOPE, $scope);
                $model->setData(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode);
                $model->setData(ThemeScopeVersionSelection::schema_fields_AREA, $area);
                $model->setData(ThemeScopeVersionSelection::schema_fields_PUBLISHED_VERSION_ID, $published);
                $model->setData(ThemeScopeVersionSelection::schema_fields_SELECTION_REVISION, 0);
            }
            $model->setData(ThemeScopeVersionSelection::schema_fields_DRAFT_VERSION_ID, $draftVersionId);
            $model->save();
            $this->forgetSelection($themeId, $scope, $storeMode, $area);
        } catch (\Throwable) {
            // selection 写入失败不阻断编辑；读取端会回落 legacy 标志。
        }
    }

    /**
     * 让 owner 的已发布版本指向 $publishedVersionId（selection 权威写入）。
     * 原草稿若正是该版本则清空（它已封存上线，不再是草稿）。
     */
    private function persistSelectionPublished(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
        int $publishedVersionId,
    ): void {
        if ($themeId < 1 || $publishedVersionId < 1) {
            return;
        }
        $scope = $this->normalizeScope($scope);
        if ($scope === '') {
            return;
        }
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';

        try {
            $existing = $this->loadSelectionRow($themeId, $scope, $storeMode, $area);
            /** @var ThemeScopeVersionSelection $model */
            $model = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
            $model->clearQuery()->clearData();
            if ($existing !== null && $existing['selection_id'] > 0) {
                $model->load($existing['selection_id']);
            } else {
                $model->setData(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId);
                $model->setData(ThemeScopeVersionSelection::schema_fields_SCOPE, $scope);
                $model->setData(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode);
                $model->setData(ThemeScopeVersionSelection::schema_fields_AREA, $area);
                $model->setData(ThemeScopeVersionSelection::schema_fields_SELECTION_REVISION, 0);
            }
            $model->setData(ThemeScopeVersionSelection::schema_fields_PUBLISHED_VERSION_ID, $publishedVersionId);
            $model->save();

            if ($existing !== null && (int)($existing['draft_version_id'] ?? 0) === $publishedVersionId) {
                $this->clearSelectionDraft($existing['selection_id']);
            }
            $this->forgetSelection($themeId, $scope, $storeMode, $area);
        } catch (\Throwable) {
            // 同上：失败不阻断发布，读取端回落 legacy 标志。
        }
    }

    /**
     * 清空 selection 的草稿指针。
     *
     * 必须走 Query 的 update 路径：框架 AbstractModel::getModelChangedData() 用 isset() 过滤
     * 变更字段，isset(null) === false，导致 Model setData(field, null)->save() 写 NULL 被静默丢弃。
     */
    private function clearSelectionDraft(int $selectionId): void
    {
        if ($selectionId < 1) {
            return;
        }
        try {
            /** @var ThemeScopeVersionSelection $model */
            $model = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
            $model->clearQuery()->clearData()
                ->where(ThemeScopeVersionSelection::schema_fields_ID, $selectionId)
                ->update([ThemeScopeVersionSelection::schema_fields_DRAFT_VERSION_ID => null])
                ->fetch();
        } catch (\Throwable) {
        }
    }

    private function nextVersionNumber(int $themeId, string $scope): int
    {
        $result = $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if (!\is_array($result) || $result === []) {
            return 1;
        }

        $row = \is_array($result[0] ?? null) ? $result[0] : $result;

        return ((int)($row[ThemeScopeVersion::schema_fields_VERSION_NUMBER] ?? 0)) + 1;
    }

    private function unsetCurrent(int $themeId, string $scope): void
    {
        $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersion::schema_fields_IS_CURRENT, 1)
            ->update([ThemeScopeVersion::schema_fields_IS_CURRENT => 0])
            ->fetch();
        $this->forgetFlagged($themeId, $scope);
    }

    private function normalizeScope(string $scope): string
    {
        return \trim($scope);
    }

    /**
     * @return list<string>
     */
    private function scopeAncestorChain(string $scope): array
    {
        $scope = $this->normalizeScope($scope);
        if ($scope === '') {
            return [];
        }

        try {
            /** @var \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface $scopes */
            $scopes = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class,
            );
            $identity = $scopes->fromStorageScope($scope, true);
            if ($identity !== null) {
                $chain = $scopes->chainFromIdentity($identity);
                $out = [];
                foreach ($chain as $candidate) {
                    $candidate = $this->normalizeScope((string)$candidate);
                    if ($candidate !== '' && !\in_array($candidate, $out, true)) {
                        $out[] = $candidate;
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        } catch (\Throwable) {
            // Fall through to dotted trim only when hierarchy is unavailable.
        }

        $chain = [];
        $current = $scope;
        while ($current !== '') {
            $chain[] = $current;
            $pos = \strrpos($current, '.');
            if ($pos === false) {
                break;
            }
            $current = \substr($current, 0, $pos);
        }

        return $chain;
    }

    /**
     * @param array<string|int, mixed> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNodesMap(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? $key)));
            if ($uid === '') {
                continue;
            }
            $node['node_uid'] = $uid;
            $out[$uid] = $node;
        }

        return $out;
    }

    /**
     * Structure-only fingerprint: node_uid, area, slot_id, widget_*, sort_order, is_active.
     * Config body is excluded from the hash but still stored in chrome_payload_json.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    public function hashStructure(array $nodes): string
    {
        $rows = [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $row = [
                'node_uid' => (string)($node['node_uid'] ?? $uid),
                'area' => (string)($node['area'] ?? ''),
                'slot_id' => (string)($node['slot_id'] ?? ''),
                'sort_order' => (int)($node['sort_order'] ?? 0),
                'is_active' => (int)(!empty($node['is_active']) || !\array_key_exists('is_active', $node) ? 1 : 0),
            ];
            foreach ($node as $field => $value) {
                if (!\is_string($field) || !\str_starts_with($field, 'widget_')) {
                    continue;
                }
                if (\is_scalar($value) || $value === null) {
                    $row[$field] = $value;
                }
            }
            \ksort($row);
            $rows[] = $row;
        }
        \usort(
            $rows,
            static fn(array $a, array $b): int => \strcmp((string)$a['node_uid'], (string)$b['node_uid']),
        );

        return \hash(
            'sha256',
            (string)\json_encode($rows, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        );
    }
}
