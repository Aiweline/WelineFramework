<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionSelection;

/**
 * Theme-scope version CRUD for shared chrome authority (theme_id + scope, no page_type).
 *
 * Request read memos store identity row snapshots (not live ORM, not chrome longtext).
 * Chrome lives only in CHROME_DECODE memo.
 *
 * Isolation contract (architecture):
 * - Public mutators / unknown callers → {@see ThemeScopeVersion::getChromePayload()} (deep copy).
 * - Trusted read-only builders → {@see ThemeScopeVersion::peekChromePayload()} (borrow memo; do not mutate).
 * - Deep copy is recursive PHP, not JSON roundtrip ({@see copyArrayTree()}).
 */
final class ThemeScopeVersionService
{
    /** Identity row bag without chrome_payload_json (v2). */
    public const ROW_CACHE_PREFIX = 'theme.scope_version.row.v2.';
    public const RESOLVED_CACHE_PREFIX = 'theme.scope_version.resolved.v1.';
    public const CHROME_DECODE_CACHE_PREFIX = 'theme.scope_version.chrome.decoded.v1.';
    public const SHARED_CHROME_BY_SLOT_PREFIX = 'theme.shared_chrome.by_slot.v1.';

    private const SHARED_CHROME_BY_SLOT_KEYS = 'theme.shared_chrome.by_slot.v1.keys';
    private const SNAPSHOT_MISS = '__theme_scope_version_snapshot_miss__';

    /**
     * 发布内容代次锚点：所有版本行/selection 行写入后 bump。
     * 读侧用同一命名空间的 fingerprint 作为跨 Worker L1 的失效键。
     */
    public const GENERATION_NAMESPACE = 'global/storefront/theme/scope_version';

    /** 跨 Worker 已发布解析 L1：值只存 version id / miss，命中后仍校验行归属。 */
    private const RESOLVED_BAG = 'theme.scope_version.resolved';
    private const RESOLVED_BAG_MAX = 4096;

    /** @var array<string, mixed> */
    private static array $processResolved = [];

    public function __construct(
        private readonly ThemeScopeVersion $versionModel,
    ) {
    }

    public function invalidateOwner(int $themeId, string $scope, string $storeMode = 'normal', string $area = 'frontend'): void
    {
        $scope = $this->normalizeScope($scope);
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';
        $versionIds = $this->knownVersionIdsForOwner($themeId, $scope, $storeMode, $area);
        $this->forgetSelection($themeId, $scope, $storeMode, $area);
        $this->forgetFlagged($themeId, $scope, $storeMode, $area);
        $this->forgetResolved($themeId, $scope, $storeMode, $area);
        foreach ($versionIds as $versionId) {
            $this->forgetVersionRow($versionId);
            self::forgetChromeDecodeMemo($versionId);
        }
        self::forgetSharedChromeBySlot($themeId, $area, $scope);
        self::bumpGeneration();
    }

    /**
     * 发布内容变更后推进代次，令其它 Worker 的跨 Worker L1 失效。
     * 失败只记录日志：保存本身已提交，不能因缓存代次写入失败回滚业务写入。
     */
    public static function bumpGeneration(): void
    {
        try {
            ObjectManager::getInstance(NamespaceGenerationInterface::class)
                ->bump(self::GENERATION_NAMESPACE);
        } catch (\Throwable $exception) {
            if (\function_exists('w_log_error')) {
                \w_log_error(
                    'theme.scope_version.generation_bump_failed',
                    ['error' => $exception->getMessage()],
                    'cache',
                );
            }
        }
    }

    /** Register a shared-chrome bySlot request memo key for owner invalidation. */
    public static function rememberSharedChromeBySlotKey(string $key): void
    {
        if ($key === '' || !RequestContext::isInitialized()) {
            return;
        }
        $keys = RequestContext::get(self::SHARED_CHROME_BY_SLOT_KEYS, []);
        if (!\is_array($keys)) {
            $keys = [];
        }
        $keys[] = $key;
        RequestContext::set(self::SHARED_CHROME_BY_SLOT_KEYS, \array_values(\array_unique($keys)));
    }

    public static function forgetChromeDecodeMemo(int $versionId): void
    {
        if ($versionId < 1 || !RequestContext::isInitialized()) {
            return;
        }
        RequestContext::remove(self::CHROME_DECODE_CACHE_PREFIX . $versionId);
    }

    /**
     * Deep-copy array trees returned from request memos so callers cannot poison them.
     *
     * Recursive PHP copy (JSON roundtrip is forbidden here: CPU cost + depth/type edge cases).
     * Nested arrays are fully separated; scalars/null are assigned by value.
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public static function copyArrayTree(array $data): array
    {
        if ($data === []) {
            return [];
        }

        $copied = [];
        foreach ($data as $key => $value) {
            $copied[$key] = \is_array($value) ? self::copyArrayTree($value) : $value;
        }

        return $copied;
    }

    public static function forgetSharedChromeBySlot(int $themeId, string $area, string $scope): void
    {
        if ($themeId < 1 || !RequestContext::isInitialized()) {
            return;
        }
        $exact = self::SHARED_CHROME_BY_SLOT_PREFIX . $themeId . '|' . $area . '|' . $scope;
        RequestContext::remove($exact);
        $keys = RequestContext::get(self::SHARED_CHROME_BY_SLOT_KEYS, []);
        if (!\is_array($keys)) {
            return;
        }
        $ownerPrefix = self::SHARED_CHROME_BY_SLOT_PREFIX . $themeId . '|';
        $kept = [];
        foreach ($keys as $key) {
            if (!\is_string($key) || !\str_starts_with($key, $ownerPrefix)) {
                if (\is_string($key)) {
                    $kept[] = $key;
                }
                continue;
            }
            // Drop all bySlot memos for this themeId (area/scope variants).
            RequestContext::remove($key);
        }
        RequestContext::set(self::SHARED_CHROME_BY_SLOT_KEYS, $kept);
    }

    public function ensureCurrent(
        int $themeId,
        string $scope,
        string $scopeKind = 'website',
        ?int $websiteId = null,
        string $storeMode = 'normal',
        string $area = 'frontend',
    ): ThemeScopeVersion {
        $scope = $this->normalizeScope($scope);
        if ($themeId < 1 || $scope === '') {
            throw new \InvalidArgumentException((string)__('Theme 范围版本参数无效。'));
        }

        $current = $this->getCurrent($themeId, $scope, $storeMode, $area);
        if ($current instanceof ThemeScopeVersion) {
            return $current;
        }

        // Orphan rows may exist with is_current=0 (unique on version_number still holds).
        $orphan = $this->loadLatestForScope($themeId, $scope, $storeMode, $area);
        if ($orphan instanceof ThemeScopeVersion) {
            $this->unsetCurrent($themeId, $scope, $storeMode, $area);
            $orphan->setIsCurrent(true)->save();
            $this->forgetFlagged($themeId, $scope, $storeMode, $area);
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
            ->setArea($area)
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
        // 新建前 getCurrent() 会把「该 scope 无 is_current 版本」的否定结果 memo 进 RequestContext；
        // 若此处不失效，随后的 getCurrent() 仍读到陈旧 null，快照捕获会误判为缺少历史头而 fail-closed。
        $this->forgetFlagged($themeId, $scope, $version->getStoreMode(), $version->getArea());

        return $version;
    }

    private function loadLatestForScope(int $themeId, string $scope, string $storeMode, string $area): ?ThemeScopeVersion
    {
        $result = $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersion::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersion::schema_fields_AREA, $area)
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
     * 最近 N 个已封存（SEALED）版本 id，version_number 倒序。
     *
     * 用途：预热过渡窗口内放行旧店面世代（html-disk 多版本共存）。
     * 严格限定 owner 四元组（theme_id+scope+store_mode+area），不展开祖先 Scope，
     * 保证「同 Scope 链内」限定，跨 Scope / 跨主题不放行。
     *
     * @return list<int>
     */
    public function listRecentSealedVersionIds(
        int $themeId,
        string $scope,
        string $storeMode = 'normal',
        string $area = 'frontend',
        int $limit = 3,
    ): array {
        $ids = [];
        foreach ($this->listRecentSealedVersions($themeId, $scope, $storeMode, $area, $limit) as $version) {
            $ids[] = $version->getVersionId();
        }

        return $ids;
    }

    /**
     * @return list<ThemeScopeVersion>
     */
    public function listRecentSealedVersions(
        int $themeId,
        string $scope,
        string $storeMode = 'normal',
        string $area = 'frontend',
        int $limit = 3,
    ): array {
        $scope = $this->normalizeScope($scope);
        if ($themeId < 1 || $scope === '' || $limit < 1) {
            return [];
        }
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';

        $result = $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersion::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersion::schema_fields_AREA, $area)
            ->where(ThemeScopeVersion::schema_fields_LIFECYCLE, ThemeScopeVersion::LIFECYCLE_SEALED)
            ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        $versions = [];
        foreach (\is_array($result) ? $result : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $version = clone $this->versionModel;
            $version->setData($row);
            if ($version->getVersionId() > 0) {
                $versions[] = $version;
            }
        }

        return $versions;
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
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';

        $resolvedKey = $this->resolvedCacheKey('current', $themeId, $scope, $storeMode, $area);
        if (RequestContext::isInitialized() && RequestContext::has($resolvedKey)) {
            return $this->hydrateResolvedVersion(
                RequestContext::get($resolvedKey),
                $themeId,
                $scope,
                $storeMode,
                $area,
            );
        }

        $version = $this->resolveCurrentUncached($themeId, $scope, $storeMode, $area);
        $this->rememberResolved($resolvedKey, $version);

        return $version;
    }

    /**
     * Resolve current version id without hydrating a model when the request memo hits.
     * Use for cache keys / FK filters that only need the integer.
     */
    public function getCurrentVersionId(
        int $themeId,
        string $scope,
        string $storeMode = 'normal',
        string $area = 'frontend',
    ): int {
        $scope = $this->normalizeScope($scope);
        if ($themeId < 1 || $scope === '') {
            return 0;
        }
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';

        $resolvedKey = $this->resolvedCacheKey('current', $themeId, $scope, $storeMode, $area);
        if (RequestContext::isInitialized() && RequestContext::has($resolvedKey)) {
            $cached = RequestContext::get($resolvedKey);
            if ($cached === self::SNAPSHOT_MISS || $cached === null || $cached === false) {
                return 0;
            }

            return (int)$cached > 0 ? (int)$cached : 0;
        }

        $version = $this->resolveCurrentUncached($themeId, $scope, $storeMode, $area);
        $this->rememberResolved($resolvedKey, $version);

        return $version instanceof ThemeScopeVersion ? $version->getVersionId() : 0;
    }

    /**
     * Resolve published chrome version for this owner scope.
     *
     * Owner-local only this wave: {@see scopeAncestorChain()} returns self.
     * Ancestor inheritance remains ThemeVersionScopePropagator’s job; restoring a
     * true walk here is a separate behavior change and must not ship with read memos.
     *
     * 每个 owner 按「selection 权威优先、legacy 标志回落」解析。
     * sealed selection 在无 is_published 旗标时仍可信（v3 selection-only）；
     * 若 selection 指向 sealed+未旗标、同 owner 另有 is_published=1 旗标行，则弃旧 selection。
     * draft 被写成 published 指针时忽略，回落旗标。
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
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';

        $resolvedKey = $this->resolvedCacheKey('published', $themeId, $scope, $storeMode, $area);
        if (RequestContext::isInitialized() && RequestContext::has($resolvedKey)) {
            return $this->hydrateResolvedVersion(
                RequestContext::get($resolvedKey),
                $themeId,
                $scope,
                $storeMode,
                $area,
            );
        }

        $bagKey = $this->resolvedBagKey($resolvedKey);
        $bag = null;
        if ($bagKey !== null) {
            $bag = &DictionaryCacheNamespace::processBag(
                self::RESOLVED_BAG,
                self::$processResolved,
                self::RESOLVED_BAG_MAX,
            );
            if (\array_key_exists($bagKey, $bag)) {
                $cached = $bag[$bagKey];
                $hydrated = $this->hydrateResolvedVersion($cached, $themeId, $scope, $storeMode, $area);
                if ($cached === self::SNAPSHOT_MISS || $hydrated instanceof ThemeScopeVersion) {
                    $this->rememberResolvedId($resolvedKey, $cached);

                    return $hydrated;
                }
                // 代次未变却解析不到归属行：视为脏条目，回落权威解析。
                unset($bag[$bagKey]);
            }
        }

        $version = $this->resolvePublishedUncached($themeId, $scope, $storeMode, $area);
        $this->rememberResolved($resolvedKey, $version);
        if ($bag !== null && $bagKey !== null) {
            $bag[$bagKey] = $version instanceof ThemeScopeVersion
                ? $version->getVersionId()
                : self::SNAPSHOT_MISS;
        }

        return $version;
    }

    private function resolveCurrentUncached(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
    ): ?ThemeScopeVersion {
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

        return $this->loadFlagged($themeId, $scope, ThemeScopeVersion::schema_fields_IS_CURRENT, $storeMode, $area);
    }

    private function resolvePublishedUncached(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
    ): ?ThemeScopeVersion {
        foreach ($this->scopeAncestorChain($scope) as $candidate) {
            $selection = $this->loadSelection($themeId, $candidate, $storeMode, $area);
            $publishedId = (int)($selection['published_version_id'] ?? 0);
            if ($publishedId > 0) {
                $version = $this->loadVersionById($publishedId, $themeId, $candidate, $storeMode, $area);
                // Selection is v3 authority for sealed targets (is_published flag may lag).
                // Draft-as-published pointer is never authoritative — fall through.
                // When selection points at sealed+unflagged but a flagged sibling exists,
                // prefer the flagged row (selection drifted after unpublish/republish).
                if ($version instanceof ThemeScopeVersion
                    && $version->getLifecycle() === ThemeScopeVersion::LIFECYCLE_SEALED
                ) {
                    if ($version->isPublished()) {
                        // Common path: sealed + flagged selection row. The legacy
                        // is_published probe cannot change the answer, so skip that query.
                        return $version;
                    }
                    $flagged = $this->loadFlagged(
                        $themeId,
                        $candidate,
                        ThemeScopeVersion::schema_fields_IS_PUBLISHED,
                        $storeMode,
                        $area,
                    );
                    if (!$flagged instanceof ThemeScopeVersion
                        || $flagged->getLifecycle() !== ThemeScopeVersion::LIFECYCLE_SEALED
                        || (int)$flagged->getVersionId() === (int)$version->getVersionId()
                    ) {
                        // v3 selection-only publish: trust sealed selection when no flagged peer.
                        return $version;
                    }
                    // Superseded — stale selection, the flagged peer wins.
                    return $flagged;
                }
            }

            $flagged = $this->loadFlagged(
                $themeId,
                $candidate,
                ThemeScopeVersion::schema_fields_IS_PUBLISHED,
                $storeMode,
                $area,
            );
            if ($flagged instanceof ThemeScopeVersion && $flagged->getLifecycle() === ThemeScopeVersion::LIFECYCLE_SEALED) {
                return $flagged;
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
            ->where(ThemeScopeVersion::schema_fields_STORE_MODE, $version->getStoreMode())
            ->where(ThemeScopeVersion::schema_fields_AREA, $version->getArea())
            ->where(ThemeScopeVersion::schema_fields_IS_PUBLISHED, 1)
            ->update([ThemeScopeVersion::schema_fields_IS_PUBLISHED => 0])
            ->fetch();

        $version->setIsPublished(true)->save();
        $this->forgetFlagged($themeId, $scope, $version->getStoreMode(), $version->getArea());
        $this->forgetVersionRow($version->getVersionId());
        self::forgetChromeDecodeMemo($version->getVersionId());
        self::forgetSharedChromeBySlot($themeId, $version->getArea(), $scope);

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
        $themeId = $version->getThemeId();
        $scope = $this->normalizeScope((string)$version->getScope());
        $storeMode = $version->getStoreMode();
        $area = $version->getArea();
        $this->forgetFlagged($themeId, $scope, $storeMode, $area);
        $this->forgetResolved($themeId, $scope, $storeMode, $area);
        $this->forgetVersionRow($version->getVersionId());
        self::forgetChromeDecodeMemo($version->getVersionId());
        self::forgetSharedChromeBySlot($themeId, $area, $scope);
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
        $this->unsetCurrent($themeId, $scope, $source->getStoreMode(), $source->getArea());
        // One defensive copy for both setChromePayload and hashStructure (avoid double getChromePayload).
        $chromePayload = $source->getChromePayload();

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
            ->setChromePayload($chromePayload)
            ->setStructureKey($source->getStructureKey() !== ''
                ? $source->getStructureKey()
                : $this->hashStructure($chromePayload))
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

    /**
     * @deprecated Do not use for compile / upgrade / cache clear.
     * Storefront generation === Scope-published ThemeScopeVersion. Ops change it only by
     * clicking Publish in the theme editor. This helper invents a sealed published row and
     * is retained only for rare recovery tooling — never call from clearAll / Formal compile /
     * theme:upgrade / solidify cutover.
     *
     * Copies chrome from the current published ThemeScopeVersion into a new sealed row and
     * markPublished() it. Does NOT call createRevisionFrom() (that unsetCurrent + drafts).
     */
    public function advancePublishedStorefrontGeneration(
        int $themeId,
        string $scope,
        string $storeMode = 'normal',
        string $area = 'frontend',
        string $actor = 'theme_upgrade_storefront_generation',
    ): ?ThemeScopeVersion {
        $scope = $this->normalizeScope($scope);
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';
        if ($themeId < 1 || $scope === '') {
            return null;
        }

        $source = $this->getPublished($themeId, $scope, $storeMode, $area);
        if (!$source instanceof ThemeScopeVersion || $source->getVersionId() < 1) {
            return null;
        }

        // getPublished may walk ancestors — advance the owner that actually holds published.
        $ownerScope = $this->normalizeScope((string)$source->getScope());
        $ownerStoreMode = $source->getStoreMode() !== '' ? $source->getStoreMode() : $storeMode;
        $ownerArea = \in_array($source->getArea(), ['frontend', 'backend'], true)
            ? $source->getArea()
            : $area;
        if ($ownerScope === '') {
            return null;
        }

        $contentRevision = max(1, (int)$source->getContentRevision());
        $nextNumber = $this->nextVersionNumber($themeId, $ownerScope);

        // Capture editor draft before markPublished — some selection writes clear it.
        $selectionBefore = $this->loadSelection($themeId, $ownerScope, $ownerStoreMode, $ownerArea);
        $preservedDraftId = (int)($selectionBefore['draft_version_id'] ?? 0);
        $chromePayload = $source->getChromePayload();

        $revision = clone $this->versionModel;
        $revision->reset()
            ->clearData()
            ->setThemeId($themeId)
            ->setScope($ownerScope)
            ->setScopeKind($source->getScopeKind())
            ->setWebsiteId($source->getWebsiteId())
            ->setStoreMode($ownerStoreMode)
            ->setArea($ownerArea)
            ->setVersionNumber($nextNumber)
            ->setVersionName('v' . $nextNumber)
            ->setVersionType(ThemeScopeVersion::TYPE_PUBLISH)
            ->setLifecycle(ThemeScopeVersion::LIFECYCLE_SEALED)
            ->setContentRevision($contentRevision)
            ->setCreationSourceKind(\Weline\Theme\Api\Version\ThemeVersionPublicationInterface::CREATION_CONTINUE_CURRENT)
            ->setChromePayload($chromePayload)
            ->setStructureKey($source->getStructureKey() !== ''
                ? $source->getStructureKey()
                : $this->hashStructure($chromePayload))
            ->setParentVersionId($source->getVersionId())
            ->setIsCurrent(false)
            ->setIsPublished(false)
            ->setDescription($actor !== '' ? ('by ' . $actor) : null)
            ->save();

        $this->markPublished($revision);

        // Restore draft when it was a real editor pointer (not the newly published row).
        if ($preservedDraftId > 0 && $preservedDraftId !== (int)$revision->getVersionId()) {
            $this->persistSelectionDraft(
                $themeId,
                $ownerScope,
                $ownerStoreMode,
                $ownerArea,
                $preservedDraftId,
                (int)$revision->getVersionId(),
                false,
            );
        }

        return $revision;
    }

    /**
     * @deprecated See {@see advancePublishedStorefrontGeneration()} — not for routine paths.
     *
     * Advance published storefront generation for every frontend Scope that currently
     * holds a published ThemeScopeVersion for this theme (application owners + selection
     * / is_published rows). Dedupes by resolved published owner so Channel/Store/Website
     * probes that inherit the same published row advance once.
     *
     * @return list<int> new published version ids
     */
    public function advancePublishedStorefrontGenerationsForTheme(
        int $themeId,
        string $actor = 'theme_upgrade_storefront_generation',
    ): array {
        if ($themeId < 1) {
            return [];
        }

        $ids = [];
        $seenOwners = [];
        foreach ($this->listFrontendPublishedOwnerProbes($themeId) as $probe) {
            $scope = $probe['scope'];
            $storeMode = $probe['store_mode'];
            $area = $probe['area'];
            $source = $this->getPublished($themeId, $scope, $storeMode, $area);
            if (!$source instanceof ThemeScopeVersion || $source->getVersionId() < 1) {
                continue;
            }
            $ownerScope = $this->normalizeScope((string)$source->getScope());
            $ownerStoreMode = $source->getStoreMode() !== '' ? $source->getStoreMode() : $storeMode;
            $ownerArea = \in_array($source->getArea(), ['frontend', 'backend'], true)
                ? $source->getArea()
                : $area;
            $ownerKey = $themeId . '|' . $ownerScope . '|' . $ownerStoreMode . '|' . $ownerArea;
            if ($ownerScope === '' || isset($seenOwners[$ownerKey])) {
                continue;
            }
            $seenOwners[$ownerKey] = true;
            $advanced = $this->advancePublishedStorefrontGeneration(
                $themeId,
                $ownerScope,
                $ownerStoreMode,
                $ownerArea,
                $actor,
            );
            if ($advanced instanceof ThemeScopeVersion && $advanced->getVersionId() > 0) {
                $ids[] = (int)$advanced->getVersionId();
            }
        }

        return $ids;
    }

    /**
     * Probe scopes for storefront generation advance: application owners + the
     * Scope currently chosen by ThemePublishedVersionRuntimeResolver (Channel/Store
     * identity). Avoid scanning every historical e2e selection row.
     *
     * @return list<array{scope:string,store_mode:string,area:string}>
     */
    private function listFrontendPublishedOwnerProbes(int $themeId): array
    {
        $probes = [];
        $push = static function (string $scope, string $storeMode = 'normal') use (&$probes): void {
            $scope = \trim($scope);
            if ($scope === '') {
                return;
            }
            $storeMode = $storeMode !== '' ? $storeMode : 'normal';
            $probes[] = [
                'scope' => $scope,
                'store_mode' => $storeMode,
                'area' => 'frontend',
            ];
        };

        try {
            /** @var ThemeApplicationUsageService $usage */
            $usage = ObjectManager::getInstance(ThemeApplicationUsageService::class);
            foreach ($usage->ownSolidifyTargetsForTheme($themeId) as $row) {
                if (!\is_array($row) || ($row['area'] ?? '') !== 'frontend') {
                    continue;
                }
                $push(
                    (string)($row['version_owner_scope'] ?? ''),
                    (string)($row['store_mode'] ?? 'normal'),
                );
            }
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(ThemePublishedVersionRuntimeResolver::class)) {
                /** @var ThemePublishedVersionRuntimeResolver $resolver */
                $resolver = ObjectManager::getInstance(ThemePublishedVersionRuntimeResolver::class);
                $identity = $resolver->resolveGenerationIdentity($themeId);
                $canonical = \trim((string)($identity['canonicalScope'] ?? ''));
                if ($canonical !== '') {
                    $push($canonical, 'normal');
                }
            }
        } catch (\Throwable) {
        }

        return $probes;
    }

    private function loadFlagged(int $themeId, string $scope, string $flagField, string $storeMode, string $area): ?ThemeScopeVersion
    {
        $cacheKey = $this->flaggedCacheKey($themeId, $scope, $flagField, $storeMode, $area);
        $cached = RequestContext::get($cacheKey);
        if (\is_array($cached) && \array_key_exists('snapshot', $cached)) {
            $snapshot = $cached['snapshot'];
            if ($snapshot === null || $snapshot === self::SNAPSHOT_MISS) {
                return null;
            }
            if (\is_array($snapshot) && $snapshot !== []) {
                return $this->hydrateVersionFromSnapshot($snapshot);
            }

            return null;
        }

        $result = $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersion::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersion::schema_fields_AREA, $area)
            ->where($flagField, 1)
            ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if (!\is_array($result) || $result === []) {
            RequestContext::set($cacheKey, ['snapshot' => null]);

            return null;
        }

        $row = \is_array($result[0] ?? null) ? $result[0] : $result;
        if (!\is_array($row) || $row === []) {
            RequestContext::set($cacheKey, ['snapshot' => null]);

            return null;
        }
        $versionId = (int)($row[ThemeScopeVersion::schema_fields_ID] ?? 0);
        if ($versionId < 1) {
            RequestContext::set($cacheKey, ['snapshot' => null]);

            return null;
        }
        $this->rememberChromeFromRaw($versionId, $row[ThemeScopeVersion::schema_fields_CHROME_PAYLOAD_JSON] ?? null);
        $snapshot = $this->versionRowSnapshotFromArray($row);
        RequestContext::set($cacheKey, ['snapshot' => $snapshot]);
        $this->rememberVersionRowSnapshot($versionId, $snapshot);

        return $this->hydrateVersionFromSnapshot($snapshot);
    }

    private function flaggedCacheKey(int $themeId, string $scope, string $flagField, string $storeMode, string $area): string
    {
        return 'theme.scope_version.flag.' . $themeId . '|' . $scope . '|' . $storeMode . '|' . $area . '|' . $flagField;
    }

    private function forgetFlagged(int $themeId, string $scope, string $storeMode, string $area): void
    {
        foreach ([
            ThemeScopeVersion::schema_fields_IS_CURRENT,
            ThemeScopeVersion::schema_fields_IS_PUBLISHED,
        ] as $flagField) {
            RequestContext::set($this->flaggedCacheKey($themeId, $scope, $flagField, $storeMode, $area), null);
        }
        // Flagged miss/hit feeds getCurrent/getPublished; drop resolved with the flag bag.
        $this->forgetResolved($themeId, $scope, $storeMode, $area);
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
     * Request memo stores a scalar row snapshot; each hit hydrates a fresh model.
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
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';
        $rowKey = self::ROW_CACHE_PREFIX . $versionId;
        if (RequestContext::isInitialized() && RequestContext::has($rowKey)) {
            $cached = RequestContext::get($rowKey);
            if ($cached === self::SNAPSHOT_MISS || $cached === null) {
                return null;
            }
            if (\is_array($cached) && $cached !== []) {
                $version = $this->hydrateVersionFromSnapshot($cached);

                return $this->versionMatchesOwner($version, $themeId, $scope, $storeMode, $area)
                    ? $version
                    : null;
            }
        }

        try {
            $version = clone $this->versionModel;
            $version->reset()->clearData()->load($versionId);
        } catch (\Throwable) {
            if (RequestContext::isInitialized()) {
                RequestContext::set($rowKey, self::SNAPSHOT_MISS);
            }

            return null;
        }
        if ($version->getVersionId() !== $versionId) {
            if (RequestContext::isInitialized()) {
                RequestContext::set($rowKey, self::SNAPSHOT_MISS);
            }

            return null;
        }
        if (!$this->versionMatchesOwner($version, $themeId, $scope, $storeMode, $area)) {
            // Still memo identity+chrome for this versionId when the row exists;
            // owner mismatch is a caller filter, not a missing row.
            $this->rememberChromeFromRaw(
                $versionId,
                $version->getData(ThemeScopeVersion::schema_fields_CHROME_PAYLOAD_JSON),
            );
            $this->rememberVersionRowSnapshot($versionId, $this->versionRowSnapshot($version));

            return null;
        }
        $this->rememberChromeFromRaw(
            $versionId,
            $version->getData(ThemeScopeVersion::schema_fields_CHROME_PAYLOAD_JSON),
        );
        $this->rememberVersionRowSnapshot($versionId, $this->versionRowSnapshot($version));
        // Cold path: return the loaded model (chrome string may remain on this
        // instance). Later hits hydrate identity-only bags + chrome decode memo.

        return $version;
    }

    private function selectionCacheKey(int $themeId, string $scope, string $storeMode, string $area): string
    {
        return 'theme.scope_version.selection.' . $themeId . '|' . $scope . '|' . $storeMode . '|' . $area;
    }

    private function forgetSelection(int $themeId, string $scope, string $storeMode, string $area): void
    {
        RequestContext::set($this->selectionCacheKey($themeId, $scope, $storeMode, $area), null);
        $this->forgetResolved($themeId, $scope, $storeMode, $area);
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
                $published = max(0, $fallbackPublishedVersionId);
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

    private function unsetCurrent(int $themeId, string $scope, string $storeMode, string $area): void
    {
        $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersion::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersion::schema_fields_AREA, $area)
            ->where(ThemeScopeVersion::schema_fields_IS_CURRENT, 1)
            ->update([ThemeScopeVersion::schema_fields_IS_CURRENT => 0])
            ->fetch();
        $this->forgetFlagged($themeId, $scope, $storeMode, $area);
    }

    private function normalizeScope(string $scope): string
    {
        return \trim($scope);
    }

    /**
     * Owner-local chain only (self). Do not expand to Propagator ancestors here —
     * that changes published resolution semantics and is a separate epic.
     *
     * @return list<string>
     */
    private function scopeAncestorChain(string $scope): array
    {
        $scope = $this->normalizeScope($scope);
        return $scope === '' ? [] : [$scope];
    }

    private function resolvedCacheKey(
        string $kind,
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
    ): string {
        return self::RESOLVED_CACHE_PREFIX . $kind . '|' . $themeId . '|' . $scope . '|' . $storeMode . '|' . $area;
    }

    private function rememberResolved(string $resolvedKey, ?ThemeScopeVersion $version): void
    {
        if (!RequestContext::isInitialized()) {
            return;
        }
        RequestContext::set(
            $resolvedKey,
            $version instanceof ThemeScopeVersion && $version->getVersionId() > 0
                ? $version->getVersionId()
                : self::SNAPSHOT_MISS,
        );
    }

    private function rememberResolvedId(string $resolvedKey, mixed $cached): void
    {
        if (!RequestContext::isInitialized()) {
            return;
        }
        RequestContext::set(
            $resolvedKey,
            $cached === self::SNAPSHOT_MISS ? self::SNAPSHOT_MISS : (int)$cached,
        );
    }

    /**
     * 跨 Worker L1 键：主题代次 fingerprint + owner 解析键。
     * fingerprint 与 Phrase 共用 NamespaceGenerationSnapshot；每请求最多一次
     * 代次读取，进程快照命中时 0 查。
     */
    private function resolvedBagKey(string $resolvedKey): ?string
    {
        try {
            $fingerprint = ObjectManager::getInstance(NamespaceGenerationInterface::class)
                ->fingerprint([self::GENERATION_NAMESPACE]);
        } catch (\Throwable) {
            return null;
        }
        if ($fingerprint === '') {
            return null;
        }

        return $fingerprint . '|' . $resolvedKey;
    }

    private function hydrateResolvedVersion(
        mixed $cached,
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
    ): ?ThemeScopeVersion {
        if ($cached === self::SNAPSHOT_MISS || $cached === null || $cached === false) {
            return null;
        }
        $versionId = (int)$cached;
        if ($versionId < 1) {
            return null;
        }

        return $this->loadVersionById($versionId, $themeId, $scope, $storeMode, $area);
    }

    private function forgetResolved(int $themeId, string $scope, string $storeMode, string $area): void
    {
        if (!RequestContext::isInitialized()) {
            return;
        }
        foreach (['current', 'published'] as $kind) {
            RequestContext::remove($this->resolvedCacheKey($kind, $themeId, $scope, $storeMode, $area));
        }
    }

    private function forgetVersionRow(int $versionId): void
    {
        if ($versionId < 1 || !RequestContext::isInitialized()) {
            return;
        }
        RequestContext::remove(self::ROW_CACHE_PREFIX . $versionId);
    }

    /**
     * Version ids already present in request memos only — never hit the DB here.
     *
     * @return list<int>
     */
    private function knownVersionIdsForOwner(int $themeId, string $scope, string $storeMode, string $area): array
    {
        if (!RequestContext::isInitialized()) {
            return [];
        }
        $ids = [];
        $selectionKey = $this->selectionCacheKey($themeId, $scope, $storeMode, $area);
        $cached = RequestContext::get($selectionKey);
        if (\is_array($cached) && \array_key_exists('selection', $cached) && \is_array($cached['selection'])) {
            foreach (['draft_version_id', 'published_version_id'] as $field) {
                $id = (int)($cached['selection'][$field] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        foreach ([
            ThemeScopeVersion::schema_fields_IS_CURRENT,
            ThemeScopeVersion::schema_fields_IS_PUBLISHED,
        ] as $flagField) {
            $flagCached = RequestContext::get($this->flaggedCacheKey($themeId, $scope, $flagField, $storeMode, $area));
            if (\is_array($flagCached) && \is_array($flagCached['snapshot'] ?? null)) {
                $id = (int)($flagCached['snapshot'][ThemeScopeVersion::schema_fields_ID] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        foreach (['current', 'published'] as $kind) {
            $resolved = RequestContext::get($this->resolvedCacheKey($kind, $themeId, $scope, $storeMode, $area));
            if ($resolved !== self::SNAPSHOT_MISS && (int)$resolved > 0) {
                $ids[] = (int)$resolved;
            }
        }

        return \array_values(\array_unique($ids));
    }

    private function rememberVersionRowSnapshot(int $versionId, array $snapshot): void
    {
        if ($versionId < 1 || !RequestContext::isInitialized()) {
            return;
        }
        RequestContext::set(self::ROW_CACHE_PREFIX . $versionId, $snapshot);
    }

    /**
     * Decode chrome JSON into the request memo once (identity row bags never carry it).
     */
    private function rememberChromeFromRaw(int $versionId, mixed $raw): void
    {
        if ($versionId < 1 || !RequestContext::isInitialized()) {
            return;
        }
        $cacheKey = self::CHROME_DECODE_CACHE_PREFIX . $versionId;
        if (RequestContext::has($cacheKey)) {
            return;
        }
        if ($raw === null || $raw === '') {
            RequestContext::set($cacheKey, []);

            return;
        }
        if (\is_array($raw)) {
            RequestContext::set($cacheKey, $raw);

            return;
        }
        if (!\is_string($raw)) {
            RequestContext::set($cacheKey, []);

            return;
        }
        $decoded = \json_decode($raw, true);
        RequestContext::set($cacheKey, \is_array($decoded) ? $decoded : []);
    }

    private function versionMatchesOwner(
        ThemeScopeVersion $version,
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
    ): bool {
        if ($version->getVersionId() < 1) {
            return false;
        }
        if ($version->getThemeId() !== $themeId || $version->getScope() !== $scope) {
            return false;
        }
        if ($version->getStoreMode() !== $storeMode) {
            return false;
        }

        return $version->getArea() === $area;
    }

    /**
     * @return array<string, mixed>
     */
    private function versionRowSnapshot(ThemeScopeVersion $version): array
    {
        $data = $version->getData();

        return \is_array($data) ? $this->versionRowSnapshotFromArray($data) : [];
    }

    /**
     * Identity columns only — chrome_payload_json is excluded (lives in decode memo).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function versionRowSnapshotFromArray(array $data): array
    {
        $snapshot = [];
        foreach ($data as $key => $value) {
            if (!\is_string($key) && !\is_int($key)) {
                continue;
            }
            if ($key === ThemeScopeVersion::schema_fields_CHROME_PAYLOAD_JSON) {
                continue;
            }
            if (\is_object($value)) {
                continue;
            }
            $snapshot[$key] = $value;
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateVersionFromSnapshot(array $data): ThemeScopeVersion
    {
        $version = clone $this->versionModel;
        $version->clearData()->clearQuery()->setData($data);

        return $version;
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
