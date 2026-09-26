<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Version;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Api\Version\ThemeVersionPublicationInterface;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionResourceSnapshot;
use Weline\Theme\Model\ThemeScopeVersionRevision;
use Weline\Theme\Model\ThemeScopeVersionSelection;

/**
 * UC-08 写入端作用范围传播：把父 owner 的发布结果按「逐值继承」规则推给后代 owner。
 *
 * 职责边界：本类只做 DB 读写与分类决策，**不写文件、不翻发布指针**。
 * 产物烘焙仍由 ThemeLayoutEntityBakeCoordinator 负责；后代 selection 指针由控制器
 * 在全部候选就绪后统一翻转（先备齐再切，避免后代读到指向空目录的版本）。
 *
 * 三桶语义（分类结果交给 ThemeVersionPublicationService::planDescendantPropagation 判定）：
 *  - fallback：本级无正式覆盖 → 直接回落祖先 owner，不生成后代版本；
 *  - updates：本级有覆盖但有效值受父变更影响 → 生成系统派生 C'，固定新父来源与本地意图；
 *  - conflicts：本级覆盖与父新结构冲突 → 保留完整 C（页面与 chrome 都不动，不做半迁移）。
 */
final class ThemeVersionScopePropagator
{
    /** 系统派生版本使用的来源标记，便于审计区分人工与自动产生的版本。 */
    public const ACTOR_SYSTEM = 'system:scope-propagation';

    public function __construct(
        private readonly ThemeVersionPublicationService $publication,
        private readonly ?ScopeHierarchyInterface $scopes = null,
    ) {
    }

    /**
     * 近→远祖先链（含自身），与 ThemeScopeVersionService 的解析口径一致。
     *
     * @return list<string>
     */
    public function scopeAncestorChain(string $scope): array
    {
        $scope = \trim($scope);
        if ($scope === '') {
            return [];
        }

        $chain = [];
        try {
            $scopes = $this->scopeHierarchy();
            $identity = $scopes->fromStorageScope($scope, true);
            if ($identity !== null) {
                foreach ($scopes->chainFromIdentity($identity) as $candidate) {
                    $candidate = \trim((string)$candidate);
                    if ($candidate !== '' && !\in_array($candidate, $chain, true)) {
                        $chain[] = $candidate;
                    }
                }
            }
        } catch (\Throwable) {
            // 层级不可用时退化为点号裁剪，保证仍然能给出祖先候选。
        }

        if ($chain === []) {
            $current = $scope;
            while ($current !== '') {
                $chain[] = $current;
                $pos = \strrpos($current, '.');
                if ($pos === false) {
                    break;
                }
                $current = \substr($current, 0, $pos);
            }
        }

        return $chain;
    }

    /**
     * candidate 是否为 parent 的严格后代（祖先链包含 parent，且两者不相等）。
     */
    public function isStrictDescendant(string $parentScope, string $candidateScope): bool
    {
        $parentScope = \trim($parentScope);
        $candidateScope = \trim($candidateScope);
        if ($parentScope === '' || $candidateScope === '' || $parentScope === $candidateScope) {
            return false;
        }

        return \in_array($parentScope, $this->scopeAncestorChain($candidateScope), true);
    }

    /**
     * 写入端祖先基准继承：本级无已发布选择时，沿祖先链取最近的已发布版本作为基准。
     *
     * 只回落 published，**不回落 draft**：草稿承载用户意图，跨 scope 继承会把祖先草稿
     * 伪装成本级 patch，违反「跨 Scope 不把祖先值伪装成子级用户 patch」。
     *
     * @return array{version_id:int,source_scope:string,inherited:bool}
     */
    public function resolveAncestorBaseVersion(ThemeVersionIdentity $owner): array
    {
        $local = $this->loadSelection($owner);
        $localPublished = (int)($local['published_version_id'] ?? 0);
        if ($localPublished > 0) {
            return [
                'version_id' => $localPublished,
                'source_scope' => $owner->canonicalScope,
                'inherited' => false,
            ];
        }

        foreach ($this->scopeAncestorChain($owner->canonicalScope) as $candidateScope) {
            if ($candidateScope === $owner->canonicalScope) {
                continue;
            }
            $ancestor = $owner->withOwnerScope($candidateScope, $owner->storeMode);
            $selection = $this->loadSelection($ancestor);
            $publishedId = (int)($selection['published_version_id'] ?? 0);
            if ($publishedId < 1 || $this->loadOwnedVersionRow($publishedId, $ancestor) === null) {
                continue;
            }

            return [
                'version_id' => $publishedId,
                'source_scope' => $candidateScope,
                'inherited' => true,
            ];
        }

        return ['version_id' => 0, 'source_scope' => '', 'inherited' => false];
    }

    /**
     * 枚举后代 owner：同 theme/store_mode/area，scope 为父 scope 的严格后代，
     * 且该 owner 至少有一行版本记录。
     *
     * @return list<ThemeVersionIdentity>
     */
    public function enumerateDescendantOwners(ThemeVersionIdentity $owner): array
    {
        $out = [];
        try {
            /** @var ThemeScopeVersion $model */
            $model = ObjectManager::getInstance(ThemeScopeVersion::class);
            $rows = $model->reset()->clearData()
                ->where(ThemeScopeVersion::schema_fields_THEME_ID, $owner->themeId)
                ->where(ThemeScopeVersion::schema_fields_STORE_MODE, $owner->storeMode)
                ->where(ThemeScopeVersion::schema_fields_AREA, $owner->area)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($rows) || $rows === []) {
            return [];
        }

        $seen = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $scope = \trim((string)($row[ThemeScopeVersion::schema_fields_SCOPE] ?? ''));
            if ($scope === '' || isset($seen[$scope])) {
                continue;
            }
            $seen[$scope] = true;
            if (!$this->isStrictDescendant($owner->canonicalScope, $scope)) {
                continue;
            }
            try {
                $out[] = $owner->withOwnerScope($scope, $owner->storeMode);
            } catch (\Throwable) {
                continue;
            }
        }

        return $out;
    }

    /**
     * 分类后代并准备 C' 候选（不翻指针）。
     *
     * @param list<string> $publishedResources 父版本本次发布的资源键
     * @param array<string,string> $parentPrevFingerprints 父旧版本 P 的「资源键 => 产物指纹」
     * @param array<string,string> $parentNewFingerprints 父新版本 P' 的「资源键 => 产物指纹」
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   plan:array{updates:list<string>,conflicts:list<string>,fallback:list<string>},
     *   updates:list<array<string,mixed>>,
     *   conflicts:list<array<string,mixed>>,
     *   fallback:list<string>,
     *   prepared:list<array<string,mixed>>,
     *   changed_resources:list<string>
     * }
     */
    public function planAndPrepareCandidates(
        ThemeVersionIdentity $owner,
        int $parentVersionId,
        int $parentPrevVersionId,
        array $publishedResources,
        array $parentPrevFingerprints,
        array $parentNewFingerprints,
    ): array {
        $changedResources = self::detectChangedResources(
            $publishedResources,
            $parentPrevFingerprints,
            $parentNewFingerprints,
        );
        $parentStructureChanged = $this->structureKeyChanged($owner, $parentPrevVersionId, $parentVersionId);
        // 父（本次发布的那个版本）自己的 chrome 结构摘要。C' 继承 chrome 时，它的结构就等于父的结构，
        // 因此必须把这个键交给 allocateDerivedVersion()，否则 C' 会是一个没有 structure_key 的
        // 「半成品已发布版本」。$parentVersionId 属于 $owner（发布者）自己，这里读它不越 owner 边界。
        $parentStructureKey = (string)(
            ($this->loadOwnedVersionRow($parentVersionId, $owner) ?? [])[ThemeScopeVersion::schema_fields_STRUCTURE_KEY] ?? ''
        );

        $rows = [];
        $descriptors = [];
        foreach ($this->enumerateDescendantOwners($owner) as $descendant) {
            $selection = $this->loadSelection($descendant);
            $publishedId = (int)($selection['published_version_id'] ?? 0);
            if ($publishedId < 1) {
                // 无本级正式覆盖：直接回落祖先 owner，不生成后代版本。
                $rows[] = [
                    'owner_hash' => $descendant->ownerHash(),
                    'has_local_override' => false,
                    'effective_changed' => false,
                    'conflict' => false,
                ];
                $descriptors[$descendant->ownerHash()] = [
                    'owner' => $descendant,
                    'published_version_id' => 0,
                    'inherited_resources' => [],
                    'overridden_resources' => [],
                ];
                continue;
            }

            $classified = self::classifyDescendant(
                $publishedId,
                $changedResources,
                $parentPrevFingerprints,
                $this->resourceFingerprintsOfVersion($publishedId),
                $parentStructureChanged,
            );
            $rows[] = [
                'owner_hash' => $descendant->ownerHash(),
                'has_local_override' => $classified['has_local_override'],
                'effective_changed' => $classified['effective_changed'],
                'conflict' => $classified['conflict'],
            ];
            $descriptors[$descendant->ownerHash()] = [
                'owner' => $descendant,
                'published_version_id' => $publishedId,
                'inherited_resources' => $classified['inherited_resources'],
                'overridden_resources' => $classified['overridden_resources'],
            ];
        }

        // 真正接入既有规划器（三桶），不再让它只被自单测调用。
        $plan = $this->publication->planDescendantPropagation($rows);

        $updates = [];
        $conflicts = [];
        $prepared = [];
        foreach ($plan['updates'] as $ownerHash) {
            $descriptor = $descriptors[$ownerHash] ?? null;
            if ($descriptor === null) {
                continue;
            }
            $allocated = $this->allocateDerivedVersion(
                $descriptor['owner'],
                (int)$descriptor['published_version_id'],
                $parentVersionId,
                \is_array($descriptor['inherited_resources'] ?? null) ? $descriptor['inherited_resources'] : [],
                $parentStructureKey,
            );
            if ($allocated === null) {
                continue;
            }
            $prepared[] = $allocated;
            $updates[] = [
                'owner_hash' => $ownerHash,
                'scope' => $descriptor['owner']->canonicalScope,
                'identity' => $descriptor['owner']->withVersion(
                    (int)$allocated['theme_version_id'],
                    ThemeVersionIdentity::MODE_FORMAL,
                    (int)$allocated['content_revision'],
                )->toArray(),
                'theme_version_id' => (int)$allocated['theme_version_id'],
                'source_version_id' => (int)$descriptor['published_version_id'],
                'scope_source_version_id' => $parentVersionId,
                'inherited_resources' => $descriptor['inherited_resources'],
                'overridden_resources' => $descriptor['overridden_resources'],
            ];
        }
        foreach ($plan['conflicts'] as $ownerHash) {
            $descriptor = $descriptors[$ownerHash] ?? null;
            if ($descriptor === null) {
                continue;
            }
            $conflicts[] = [
                'owner_hash' => $ownerHash,
                'scope' => $descriptor['owner']->canonicalScope,
                'kept_version_id' => (int)$descriptor['published_version_id'],
                'reason' => 'structural_conflict_with_new_parent',
                'overridden_resources' => $descriptor['overridden_resources'],
            ];
        }

        return [
            'rows' => $rows,
            'plan' => $plan,
            'updates' => $updates,
            'conflicts' => $conflicts,
            'fallback' => $plan['fallback'],
            'prepared' => $prepared,
            'changed_resources' => $changedResources,
        ];
    }

    /**
     * 纯分类：判定一个后代 owner 走哪一桶（不触库，便于单测）。
     *
     * 「本地是否覆盖」用指纹比对判定，且要求后代确实有自己的非空产物：
     *  - 后代该资源指纹非空且与父旧版本不同 → 后代有自己的内容，父变更被它挡住；
     *  - 否则（指纹为空，或与父旧版本相同）→ 该资源是继承来的，父变更会穿透。
     *
     * 只比「不同」是不够的：后代从未烘焙该资源时指纹为空，若也算「覆盖」，
     * 就会把纯继承的普通情况误判成「本级有覆盖」，永远生成不出 C'。
     *
     * 结构冲突只在「父 chrome 结构变了 + 后代确实本地覆盖了 chrome」时成立 ——
     * 此时把后代的 chrome 换成 C' 会与它自己的结构打架，按计划保留完整 C。
     *
     * @param list<string> $changedResources
     * @param array<string,string> $parentPrevFingerprints
     * @param array<string,string> $descendantFingerprints
     * @return array{
     *   has_local_override:bool,
     *   effective_changed:bool,
     *   conflict:bool,
     *   inherited_resources:list<string>,
     *   overridden_resources:list<string>
     * }
     */
    public static function classifyDescendant(
        int $publishedVersionId,
        array $changedResources,
        array $parentPrevFingerprints,
        array $descendantFingerprints,
        bool $parentStructureChanged,
    ): array {
        if ($publishedVersionId < 1) {
            return [
                'has_local_override' => false,
                'effective_changed' => false,
                'conflict' => false,
                'inherited_resources' => [],
                'overridden_resources' => [],
            ];
        }

        $overridden = [];
        $inherited = [];
        foreach ($changedResources as $resourceKey) {
            $descendantFingerprint = (string)($descendantFingerprints[$resourceKey] ?? '');
            $parentPrevFingerprint = (string)($parentPrevFingerprints[$resourceKey] ?? '');
            if ($descendantFingerprint !== '' && $descendantFingerprint !== $parentPrevFingerprint) {
                $overridden[] = $resourceKey;
            } else {
                $inherited[] = $resourceKey;
            }
        }
        $effectiveChanged = $inherited !== [];
        $conflict = $effectiveChanged
            && $parentStructureChanged
            && \in_array('chrome', $overridden, true);

        return [
            'has_local_override' => true,
            'effective_changed' => $effectiveChanged,
            'conflict' => $conflict,
            'inherited_resources' => $inherited,
            'overridden_resources' => $overridden,
        ];
    }

    /**
     * 本次发布中「父产物指纹发生变化」的资源键。
     *
     * 两侧都为空时无法判定，视为未变化 —— 宁可少生成一个后代版本，
     * 也不因为未知指纹凭空造版本。
     *
     * @param list<string> $publishedResources
     * @param array<string,string> $prev
     * @param array<string,string> $next
     * @return list<string>
     */
    public static function detectChangedResources(array $publishedResources, array $prev, array $next): array
    {
        $keys = [];
        foreach ($publishedResources as $resourceKey) {
            $resourceKey = \is_string($resourceKey) ? \trim($resourceKey) : '';
            if ($resourceKey !== '' && $resourceKey !== '*') {
                $keys[$resourceKey] = true;
            }
        }
        if ($keys === []) {
            foreach (\array_keys($next) as $resourceKey) {
                $keys[(string)$resourceKey] = true;
            }
        }

        $changed = [];
        foreach (\array_keys($keys) as $resourceKey) {
            $before = (string)($prev[$resourceKey] ?? '');
            $after = (string)($next[$resourceKey] ?? '');
            if ($before === $after) {
                continue;
            }
            $changed[] = (string)$resourceKey;
        }

        return $changed;
    }

    /**
     * 父版本 chrome 结构是否变化（P 与 P' 的 structure_key 不同）。
     *
     * 用版本行上已有的 structure_key，而不是重新解析工作区载荷：
     * 该列就是「chrome 结构摘要」，跨版本可比且不依赖请求上下文。
     */
    private function structureKeyChanged(ThemeVersionIdentity $owner, int $prevVersionId, int $newVersionId): bool
    {
        if ($prevVersionId < 1 || $newVersionId < 1) {
            return false;
        }
        $prev = $this->loadOwnedVersionRow($prevVersionId, $owner);
        $next = $this->loadOwnedVersionRow($newVersionId, $owner);
        if ($prev === null || $next === null) {
            return false;
        }
        $prevKey = (string)($prev[ThemeScopeVersion::schema_fields_STRUCTURE_KEY] ?? '');
        $nextKey = (string)($next[ThemeScopeVersion::schema_fields_STRUCTURE_KEY] ?? '');

        return $prevKey !== '' && $nextKey !== '' && $prevKey !== $nextKey;
    }

    /**
     * 分配系统派生版本 C'：封存态、内容修订 1，修订头固定「新父来源 + 本地意图基准」。
     *
     * C' 不复制一套空壳目录：它的内容完全由 P' 与本地意图合成，因此只记录来源引用，
     * 产物在首次访问时定点生成（与「无本级正式覆盖的范围可直接使用祖先 owner 的产物」一致）。
     *
     * **但 `structure_key` 必须落库**（本轮仅改 Service，版本门禁仍为 2.2.638）：C' 立即成为该 owner 的 published，
     * 而 structure_key 是「父 chrome 结构是否变化」的**唯一跨版本可比信号**。
     * 只省目录、不省摘要 —— 省掉摘要会让持有 C' 的 owner 再发布时被判成「结构未变化」。
     *
     * @param list<string> $inheritedResources
     * @return array<string,mixed>|null
     */
    private function allocateDerivedVersion(
        ThemeVersionIdentity $descendant,
        int $sourceVersionId,
        int $parentVersionId,
        array $inheritedResources,
        string $inheritedChromeStructureKey = '',
    ): ?array {
        if ($sourceVersionId < 1 || $parentVersionId < 1) {
            return null;
        }
        $source = $this->loadOwnedVersionRow($sourceVersionId, $descendant);
        if ($source === null) {
            return null;
        }

        try {
            $nextNumber = $this->nextVersionNumber($descendant);
            // ★ C' 会**立即**成为该后代 owner 的 published_version_id，因此它必须是一个
            // 「结构上完整」的已发布版本，而不是半成品：structure_key 必须可比。
            // 它继承 chrome 时，chrome 结构就是父 P' 的结构 ⇒ 用父的键；
            // chrome 未被继承（本级覆盖）时，chrome 仍来自本地基准 C ⇒ 用 C 的键。
            // 缺了它，structureKeyChanged() 的 `$prevKey !== '' && $nextKey !== ''` 守卫会把
            // 「该 owner 的上一已发布版本」判成「结构未变化」，导致覆盖 chrome 的冲突子
            // 被误判为可自动前进（本级 chrome 覆盖被 C' 顶掉）。
            $derivedStructureKey = \in_array('chrome', $inheritedResources, true)
                ? $inheritedChromeStructureKey
                : (string)($source[ThemeScopeVersion::schema_fields_STRUCTURE_KEY] ?? '');
            /** @var ThemeScopeVersion $version */
            $version = ObjectManager::getInstance(ThemeScopeVersion::class);
            $version->reset()->clearData()
                ->setThemeId($descendant->themeId)
                ->setScope($descendant->canonicalScope)
                ->setScopeKind((string)($source[ThemeScopeVersion::schema_fields_SCOPE_KIND] ?? 'website'))
                ->setStoreMode($descendant->storeMode)
                ->setArea($descendant->area)
                ->setVersionNumber($nextNumber)
                ->setVersionName('v' . $nextNumber . ' ' . (string)__('继承父版本'))
                ->setVersionType(ThemeScopeVersion::TYPE_SCOPE_REBASE)
                ->setLifecycle(ThemeScopeVersion::LIFECYCLE_SEALED)
                ->setContentRevision(1)
                ->setCreationSourceKind(ThemeVersionPublicationInterface::CREATION_CONTINUE_CURRENT)
                ->setCreationSourceVersionId($sourceVersionId)
                ->setDescription((string)__('父版本发布后系统派生'))
                ->setStructureKey($derivedStructureKey)
                ->setIsCurrent(false)
                ->setIsPublished(false)
                ->save();
            $derivedId = $version->getVersionId();
            if ($derivedId < 1) {
                return null;
            }

            /** @var ThemeScopeVersionRevision $revision */
            $revision = ObjectManager::getInstance(ThemeScopeVersionRevision::class);
            $revision->reset()->clearData()
                ->setData(ThemeScopeVersionRevision::schema_fields_THEME_VERSION_ID, $derivedId)
                ->setData(ThemeScopeVersionRevision::schema_fields_CONTENT_REVISION, 1)
                // 本地意图基准：后代原来那个 C，保证本级覆盖不因父发布而丢失。
                ->setData(ThemeScopeVersionRevision::schema_fields_BASE_VERSION_ID, $sourceVersionId)
                ->setData(ThemeScopeVersionRevision::schema_fields_KIND, ThemeScopeVersionRevision::KIND_SEALED)
                // 新父来源：固定成 P'，历史不会随父再次发布而漂移（H 不追今日父版）。
                ->setData(ThemeScopeVersionRevision::schema_fields_SCOPE_SOURCE_VERSION_ID, $parentVersionId)
                ->setData(
                    ThemeScopeVersionRevision::schema_fields_MANIFEST_DIGEST,
                    \hash('sha256', \implode("\0", [
                        (string)$derivedId,
                        (string)$sourceVersionId,
                        (string)$parentVersionId,
                        \implode(',', $inheritedResources),
                    ])),
                )
                ->setData(ThemeScopeVersionRevision::schema_fields_ACTOR_ID, self::ACTOR_SYSTEM)
                ->save();

            return [
                'theme_version_id' => $derivedId,
                'content_revision' => 1,
                'scope' => $descendant->canonicalScope,
                'version_number' => $nextNumber,
                'source_version_id' => $sourceVersionId,
                'scope_source_version_id' => $parentVersionId,
                'revision_row_id' => $revision->getId(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function nextVersionNumber(ThemeVersionIdentity $owner): int
    {
        /** @var ThemeScopeVersion $model */
        $model = ObjectManager::getInstance(ThemeScopeVersion::class);
        $rows = $model->reset()->clearData()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $owner->themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $owner->canonicalScope)
            ->where(ThemeScopeVersion::schema_fields_STORE_MODE, $owner->storeMode)
            ->where(ThemeScopeVersion::schema_fields_AREA, $owner->area)
            ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();
        $current = 0;
        if (\is_array($rows) && $rows !== []) {
            $row = \array_is_list($rows) ? ($rows[0] ?? []) : $rows;
            if (\is_array($row)) {
                $current = (int)($row[ThemeScopeVersion::schema_fields_VERSION_NUMBER] ?? 0);
            }
        }

        return $current + 1;
    }

    /**
     * @return array{selection_id:int,published_version_id:int,draft_version_id:?int,selection_revision:int}
     */
    private function loadSelection(ThemeVersionIdentity $owner): array
    {
        $empty = ['selection_id' => 0, 'published_version_id' => 0, 'draft_version_id' => null, 'selection_revision' => 0];
        try {
            /** @var ThemeScopeVersionSelection $model */
            $model = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
            $rows = $model->reset()->clearData()
                ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $owner->themeId)
                ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $owner->canonicalScope)
                ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $owner->storeMode)
                ->where(ThemeScopeVersionSelection::schema_fields_AREA, $owner->area)
                ->limit(1)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return $empty;
        }
        if (!\is_array($rows) || $rows === []) {
            return $empty;
        }
        $row = \array_is_list($rows) ? ($rows[0] ?? null) : $rows;
        if (!\is_array($row)) {
            return $empty;
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
     * 读取版本行并校验其属于目标 owner，避免跨 owner 误用。
     *
     * @return array<string,mixed>|null
     */
    private function loadOwnedVersionRow(int $versionId, ThemeVersionIdentity $owner): ?array
    {
        if ($versionId < 1) {
            return null;
        }
        try {
            /** @var ThemeScopeVersion $model */
            $model = ObjectManager::getInstance(ThemeScopeVersion::class);
            $model->reset()->clearData()->load($versionId);
        } catch (\Throwable) {
            return null;
        }
        if ($model->getVersionId() !== $versionId
            || $model->getThemeId() !== $owner->themeId
            || $model->getScope() !== $owner->canonicalScope
            || $model->getStoreMode() !== $owner->storeMode
            || $model->getArea() !== $owner->area
        ) {
            return null;
        }

        return $model->getData();
    }

    /**
     * 版本占有的资源指纹：资源键 => 产物指纹。
     *
     * 按 resource_key_json.resource 取值而不是 identity_hash —— 身份哈希内嵌 scope，
     * 跨 owner 比较会永远不相等，无法判断「后代是否本地覆盖了该资源」。
     *
     * @return array<string,string>
     */
    private function resourceFingerprintsOfVersion(int $versionId): array
    {
        $out = [];
        try {
            /** @var ThemeScopeVersionResourceSnapshot $model */
            $model = ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class);
            $rows = $model->reset()->clearData()
                ->where(ThemeScopeVersionResourceSnapshot::schema_fields_THEME_VERSION_ID, $versionId)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($rows) || $rows === []) {
            return [];
        }
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $keyJson = $row[ThemeScopeVersionResourceSnapshot::schema_fields_RESOURCE_KEY_JSON] ?? '';
            $decoded = \is_string($keyJson) ? \json_decode($keyJson, true) : $keyJson;
            $resourceKey = \is_array($decoded) ? \trim((string)($decoded['resource'] ?? '')) : '';
            if ($resourceKey === '') {
                continue;
            }
            $out[$resourceKey] = (string)($row[ThemeScopeVersionResourceSnapshot::schema_fields_SOURCE_FINGERPRINT] ?? '');
        }

        return $out;
    }

    private function scopeHierarchy(): ScopeHierarchyInterface
    {
        return $this->scopes ?? ObjectManager::getInstance(ScopeHierarchyInterface::class);
    }
}
