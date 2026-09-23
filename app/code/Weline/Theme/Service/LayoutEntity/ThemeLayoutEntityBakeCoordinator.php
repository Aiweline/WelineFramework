<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * Write-path bake gate: structural changes must materialize; config updates sidecar only.
 * Success requires bake OK. Busts presentation caches after writes.
 */
final class ThemeLayoutEntityBakeCoordinator
{
    private array $lastRebakeReport = ['migrated' => 0, 'unmapped' => [], 'chrome_bootstrapped' => 0];

    public function getLastRebakeReport(): array
    {
        return $this->lastRebakeReport;
    }

    public function __construct(
        private readonly ThemeScopeVersionService $scopeVersions,
        private readonly ThemeLayoutEntityMaterializer $materializer,
        private readonly ThemeLayoutEntityConfigStore $configStore,
        private readonly ThemeLayoutEntityPointerResolver $pointers,
        private readonly ThemeLayoutSlotTreeBuilder $slotTree,
        private readonly SharedChromeService $sharedChrome,
    ) {
    }

    /** Rebuild the selected editor artifacts without changing a release or draft payload. */
    public function refreshResourceArtifacts(\Weline\Theme\Api\Scoped\ThemeEditorContext $context, string $status, int $versionId = 0): array
    {
        $scope = $context->scope->storageScope;
        $published = $status === 'published';
        $chromeVersion = 0;
        $chromeScope = $scope;
        if ($versionId > 0) {
            $selection = ObjectManager::getInstance(\Weline\Theme\Service\ThemeVersionPreviewResolver::class)->resolve(
                $context->themeId, $context->layoutType, $context->area,
                ['scope' => $scope, 'layout_option' => $context->layoutOption, 'target_type' => $context->targetType, 'target_id' => $context->targetId], $versionId,
            );
            if (empty($selection['resolved'])) { throw new \RuntimeException((string)($selection['reason'] ?? 'preview_version_unresolved')); }
            $nodes = $selection['nodes'];
            $entityKey = $selection['entity_key'];
            $published = str_starts_with($entityKey, 'r');
            $releaseId = $published ? (int)substr($entityKey, 1) : null;
            $revisionId = $published ? 0 : (int)substr($entityKey, 1);
            $chromeVersion = (int)$selection['chrome_version_id'];
            $chromeScope = (string)$selection['chrome_scope'];
        } else {
            $workspace = ObjectManager::getInstance(\Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface::class)->load($context, true);
            $payload = $workspace[$published ? 'published_payload' : 'draft_payload'] ?? [];
            $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : $payload;
            $releaseId = $published ? (int)($workspace['effective_release_id'] ?? $workspace['published_release_id'] ?? 0) : null;
            $revisionId = $published ? 0 : (int)($workspace['draft_revision_id'] ?? 0);
            if ($published && !empty($workspace['published_source_scope']) && $workspace['published_source_scope'] !== $scope) {
                $hierarchy = ObjectManager::getInstance(\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class);
                $identity = $hierarchy->fromStorageScope($workspace['published_source_scope'], true);
                if ($identity !== null) { $context = $context->withScope($hierarchy->contextFromIdentity($identity)); $scope = $context->scope->storageScope; }
            }
            $entityKey = $published && $releaseId > 0 ? 'r' . $releaseId : 'd' . $revisionId;
            $chrome = $published ? $this->pointers->resolvePublishedChrome($context->themeId, $scope) : $this->pointers->resolveCurrentChrome($context->themeId, $scope);
            $chromeVersion = (int)($chrome['version_id'] ?? 0);
            $chromeScope = (string)($chrome['scope'] ?? $scope);
        }
        // Workspace payloads need the same required-injection projection as ordinary bakes.
        // Pass the selected layout version so its explicit uninstall records still win.
        $path = $this->bakePageFromNodes($context->themeId, $scope, $context->identityHash(), $context->layoutType,
            $nodes, $published, $releaseId, $revisionId, $versionId ?: null, [], false, $context->layoutOption, $context->area);
        $artifacts = [$this->resourceArtifactReceipt('page', $path)];
        if ($chromeVersion > 0) {
            $version = clone ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeVersion::class);
            $version->load($chromeVersion);
            if ($version->getThemeId() !== $context->themeId || $version->getScope() !== $chromeScope) { throw new \RuntimeException('preview_chrome_identity_mismatch'); }
            $chromePath = $this->materializer->materializeChrome($version);
            $artifacts[] = $this->resourceArtifactReceipt('chrome', $chromePath);
        }
        $this->bustPresentationCaches($context->themeId, $scope);
        return ['status' => $status, 'version_id' => $versionId ?: null, 'entity_key' => $entityKey, 'artifacts' => $artifacts];
    }

    /** Return verifiable output evidence without exposing a host filesystem path. */
    private function resourceArtifactReceipt(string $type, string $path): array
    {
        $digest = is_file($path) ? hash_file('sha256', $path) : false;
        if ($digest === false) {
            throw new \RuntimeException('theme_layout_entity_' . $type . '_bake_failed');
        }
        $receipt = ['type' => $type, 'artifact_id' => $digest, 'exists' => true];
        $root = defined('BP') ? rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR : '';
        if ($root !== '' && str_starts_with($path, $root)) {
            $receipt['relative_path'] = substr($path, strlen($root));
        }
        return $receipt;
    }

    /**
     * @param list<\Weline\Theme\Api\Scoped\ThemePatchCommand>|list<array<string,mixed>> $commands
     */
    public function afterLayoutWrite(
        int $themeId,
        string $scope,
        string $layoutType,
        string $identityHash,
        array $nodes,
        array $commands,
        bool $published,
        ?int $releaseId,
        int $draftRevisionId = 0,
        string $layoutOption = 'default',
        string $area = 'frontend',
        ?int $versionId = null,
    ): void {
        if ($themeId < 1 || $scope === '') {
            throw new \InvalidArgumentException('theme_layout_entity_bake_identity_invalid');
        }

        $structural = $this->commandsAreStructural($commands);
        $chromeTouched = $this->commandsTouchChrome($commands, $nodes);
        if ($chromeTouched) {
            $this->bakeChromeFromNodes($themeId, $scope, $nodes, $structural, false, $versionId);
        }

        if ($structural) {
            $this->bakePageFromNodes($themeId, $scope, $identityHash, $layoutType,
                $nodes, $published, $releaseId, $draftRevisionId, versionId: $versionId, layoutOption: $layoutOption, area: $area);
        } else {
            $this->updateConfigSidecarsOnly($themeId, $scope, $identityHash, $nodes,
                $published, $releaseId, $draftRevisionId, $layoutType, $layoutOption, $area, $versionId);
        }

        // Global chrome must exist for the scope even when this write only touched page content.
        $this->ensurePublishedChromeForScope($themeId, $scope, $chromeTouched ? $nodes : []);
        if ($chromeTouched && $this->sharedChrome->isChromeCarrierPageType($layoutType)) {
            $this->syncCarrierChromePayloadIfStale($themeId, $scope, $nodes, $published);
        }

        // 一次布局提交只通知一次展示依赖；不再清空所有框架缓存池。
        $this->bustPresentationCaches($themeId, $scope);
    }

    /**
     * Ensure shared chrome is materialized + published for theme+scope.
     * Creates ThemeScopeVersion when missing (DaoCharms / orphan page-only solidify).
     *
     * @param array<string|int, mixed> $nodes
     */
    public function ensurePublishedChromeForScope(int $themeId, string $scope, array $nodes = []): string
    {
        $scope = \trim($scope);
        if ($themeId < 1 || $scope === '') {
            throw new \InvalidArgumentException('theme_layout_entity_chrome_ensure_identity_invalid');
        }

        $published = $this->pointers->resolvePublishedChrome($themeId, $scope);
        if ($published !== null && \is_file((string)($published['path'] ?? ''))) {
            if ($nodes !== []) {
                $this->syncCarrierChromePayloadIfStale($themeId, $scope, $nodes, true);
                $again = $this->pointers->resolvePublishedChrome($themeId, $scope);
                if ($again !== null && \is_file((string)($again['path'] ?? ''))) {
                    return (string)$again['path'];
                }
            }

            return (string)$published['path'];
        }

        // Current version may already have chrome.phtml but never marked published.
        $current = $this->scopeVersions->ensureCurrent($themeId, $scope);
        $binding = ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class)
            ->readChromeBinding($themeId, $scope, $current->getVersionId());
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        $currentPath = $binding?->templatePath
            ?? $paths->chromePhtml($themeId, $scope, $current->getVersionId());
        if (\is_file($currentPath) && $nodes === []) {
            if (!$current->isPublished()) {
                $this->scopeVersions->markPublished($current);
            }
            $this->pointers->invalidateChrome($themeId, $scope);
            $this->pointers->rememberChromePointer($themeId, $scope, $current->getVersionId(), $currentPath, true);

            return $currentPath;
        }

        $path = $this->bakeChromeFromNodes($themeId, $scope, $nodes, true, false);
        $version = $this->scopeVersions->getCurrent($themeId, $scope);
        if ($version === null) {
            throw new \RuntimeException('theme_layout_entity_chrome_ensure_version_missing');
        }
        if (!$version->isPublished()) {
            $this->scopeVersions->markPublished($version);
        }
        $this->pointers->invalidateChrome($themeId, $scope);
        $this->pointers->rememberChromePointer($themeId, $scope, $version->getVersionId(), $path, true);

        return $path;
    }

    /**
     * Bootstrap shared chrome for scopes that have page shells but no published chrome.
     */
    public function bootstrapMissingChromeScopes(?int $themeId = null): int
    {
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        $root = $paths->root();
        if (!\is_dir($root)) {
            return 0;
        }

        $bootstrapped = 0;
        $themeDirs = \glob($root . ($themeId !== null && $themeId > 0 ? (string)$themeId : '*'), GLOB_ONLYDIR) ?: [];
        foreach ($themeDirs as $themeDir) {
            $tid = (int)\basename($themeDir);
            if ($tid < 1) {
                continue;
            }
            if ($themeId !== null && $themeId > 0 && $tid !== $themeId) {
                continue;
            }
            $scopeDirs = \glob($themeDir . \DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
            foreach ($scopeDirs as $scopeDir) {
                $scopeKey = \basename($scopeDir);
                if ($scopeKey === '' || $scopeKey === '.' || $scopeKey === '..') {
                    continue;
                }
                $pagesDir = $scopeDir . \DIRECTORY_SEPARATOR . 'pages';
                if (!\is_dir($pagesDir)) {
                    continue;
                }
                // Directory name is the sanitized storage scope (equals raw scope for storefront keys).
                $scope = $scopeKey;
                $existing = $this->pointers->resolvePublishedChrome($tid, $scope);
                if ($existing !== null && \is_file((string)($existing['path'] ?? ''))) {
                    continue;
                }
                try {
                    $this->ensurePublishedChromeForScope($tid, $scope, []);
                    ++$bootstrapped;
                } catch (\Throwable $e) {
                    if (\function_exists('w_log_warning')) {
                        w_log_warning(
                            'theme_layout_entity_chrome_bootstrap_failed: ' . $e->getMessage(),
                            ['theme_id' => $tid, 'scope' => $scope],
                            'theme_layout_entity',
                        );
                    }
                }
            }
        }

        return $bootstrapped;
    }

    /**
     * @param array<string|int, mixed> $nodes
     */
    public function bakeChromeFromNodes(int $themeId, string $scope, array $nodes, bool $structural = true, bool $invalidate = true, ?int $versionId = null): string
    {
        $version = $this->scopeVersions->ensureCurrent($themeId, $scope);
        $chromeNodes = $this->slotTree->filterChromeNodes($nodes);
        if (!$structural) {
            // 配置提交合并完整节点，不能截掉默认注入或未出现在局部提交中的节点。
            $chromeNodes = $this->mergeChromePayloadNodes($version->getChromePayload(), $chromeNodes);
        } else {
            $chromeNodes = $this->preserveChromeUserRemovals($chromeNodes, $version->getChromePayload());
            $chromeNodes = $this->mergeRequiredDefaultsIntoNodes($chromeNodes, $themeId, 'homepage', $versionId);
            $chromeNodes = $this->slotTree->filterChromeNodes($chromeNodes);
        }
        if ($chromeNodes !== $version->getChromePayload()) {
            $this->scopeVersions->setChromePayload($version, $chromeNodes);
            $version = $this->scopeVersions->getCurrent($themeId, $scope) ?? $version;
        }
        // Materializer 按最终结构摘要复用模板；配置只产生新的绑定。
        $path = $this->materializer->materializeChrome($version);
        if (!\is_file($path)) {
            throw new \RuntimeException('theme_layout_entity_chrome_bake_failed');
        }
        $this->pointers->invalidateChrome($themeId, $scope);
        $this->pointers->rememberChromePointer($themeId, $scope, $version->getVersionId(), $path, false);
        if ($version->isPublished()) {
            $this->pointers->rememberChromePointer($themeId, $scope, $version->getVersionId(), $path, true);
        }
        if ($invalidate) {
            $this->bustPresentationCaches($themeId, $scope);
        }
        return $path;
    }

    /** Keep explicit draft removals when automatic/workspace projections are rebuilt. */
    private function preserveChromeUserRemovals(array $incoming, array $current): array
    {
        foreach ($current as $uid => $removed) {
            if (!is_array($removed) || ($removed['source'] ?? '') !== 'user_deleted'
                || !array_key_exists('is_active', $removed) || !empty($removed['is_active'])) {
                continue;
            }
            $matches = [];
            foreach ($incoming as $key => $node) {
                if (!is_array($node)) {
                    continue;
                }
                $samePlacement = true;
                foreach (['widget_module', 'widget_type', 'widget_code', 'area', 'slot_id'] as $field) {
                    if ((string)($node[$field] ?? '') !== (string)($removed[$field] ?? '')) {
                        $samePlacement = false;
                        break;
                    }
                }
                if (!$samePlacement && (string)($node['node_uid'] ?? $key) !== (string)($removed['node_uid'] ?? $uid)) {
                    continue;
                }
                $matches[] = $key;
            }
            // Input payloads may be stale workspace projections. An active flag
            // (regardless of source) is not evidence of an explicit restore command.
            foreach ($matches as $key) {
                unset($incoming[$key]);
            }
            $incoming[$uid] = $removed;
        }
        return $incoming;
    }

    /**
     * @param array<string|int, mixed> $nodes
     */
    public function bakePageFromNodes(
        int $themeId,
        string $scope,
        string $identityHash,
        string $layoutType,
        array $nodes,
        bool $published,
        ?int $releaseId,
        int $draftRevisionId = 0,
        ?int $versionId = null,
        array $changes = [],
        bool $updateCurrent = true,
        string $layoutOption = 'default',
        string $area = 'frontend',
        bool $mergeDefaults = true,
    ): string {
        $contentNodes = $this->slotTree->filterContentNodes($nodes);
        // 布局固化与默认注入: required JSON default_injections bake into layout.phtml nodes.
        if ($mergeDefaults) {
            $contentNodes = $this->mergeRequiredDefaultsIntoNodes($contentNodes, $themeId, $layoutType, $versionId, $changes, $layoutOption);
        }
        $contentNodes = $this->slotTree->filterContentNodes($contentNodes);
        $structureKey = hash('sha256', $this->structureKeyForNodes($contentNodes, $draftRevisionId, $releaseId)
            . '|' . $this->sourceLayoutFingerprint($themeId, $layoutType, $layoutOption, $area));
        $identityKey = $this->pathsIdentityKey($identityHash, $layoutType);
        $configByUid = [];
        foreach ($contentNodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? '')));
            if ($uid === '') {
                continue;
            }
            // 完整节点入 sidecar：WidgetRenderer 需要 widget_module/code；仅存 config 会丢图片等字段。
            $configByUid[$uid] = $node;
        }

        $path = $this->materializer->materializePage(
            $themeId,
            $scope,
            $identityKey,
            $structureKey,
            $contentNodes,
            $configByUid,
            $published,
            $releaseId,
            $layoutType,
            $draftRevisionId,
        );
        if (!\is_file($path)) {
            throw new \RuntimeException('theme_layout_entity_page_bake_failed');
        }
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        $entityKey = $published && $releaseId !== null && $releaseId > 0
            ? 'r' . $releaseId : 'd' . $draftRevisionId;
        $binding = ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class)
            ->readPageBinding($themeId, $scope, $identityKey, $entityKey);
        if ($updateCurrent) {
            $this->writePageCurrentPointer($paths, $themeId, $scope, $identityKey, $entityKey, $published);
        $this->pointers->invalidatePage($themeId, $scope,
            $identityHash !== '' ? $identityHash : $identityKey, $structureKey, $published, $releaseId);
        $this->pointers->rememberPagePointer($themeId, $scope,
            $identityHash !== '' ? $identityHash : $identityKey,
            $binding?->structureKey ?? $structureKey, $path, $published, $releaseId);

        }

        // Page solidify must not leave the scope without shared chrome (header/footer).
        $this->ensurePublishedChromeForScope($themeId, $scope, []);

        return $path;
    }

    /**
     * Plugin / injection-collect: rematerialize involved layouts under all themes
     * (merge required JSON into structure), finalize chrome.rendered, then refresh shells
     * that echo ThemeLayoutEntityChrome::renderCurrent (never raw chrome.phtml injectors).
     *
     * @see app/code/Weline/Theme/doc/布局固化与默认注入.md §3.3
     */
    public function rebakeAfterInjectionCollect(?int $themeId = null, array $changes = []): int
    {
        // A disappeared definition changes future draft defaults, not saved
        // publications/history. Ordinary declaration migrations keep their reach.
        $changesForDraft = static fn(bool $currentDraft): array => $currentDraft ? $changes
            : array_values(array_filter($changes, static fn(array $change): bool => empty($change['definition_retired'])));
        $enumerator = ObjectManager::getInstance(ThemeLayoutEntityInjectionTargets::class);
        $targets = $enumerator->resolve($changes, $themeId);
        $report = $enumerator->reportForTargets($targets);
        $this->lastRebakeReport = ['migrated' => 0, 'unmapped' => $report['unresolved'], 'chrome_bootstrapped' => 0];
        $merger = ObjectManager::getInstance(RequiredDefaultInjectionBakeMerger::class);
        $seen = $scopes = [];
        foreach ($targets as $target) {
            $targetChanges = $changesForDraft(!empty($target['current']) && empty($target['published']));
            if ($changes !== [] && $targetChanges === []) {
                continue;
            }
            if (empty($target['version_resolved'])
                && ($changes !== [] || ($target['reason'] ?? '') === 'historical_draft_baseline_missing')) {
                continue;
            }
            $key = implode('|', [$target['theme_id'], $target['scope'], $target['identity_hash'],
                $target['published'] ? 'r' . $target['release_id'] : 'd' . $target['draft_revision_id']]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ($merger->unresolvedRetiredNodes($target['nodes'], $target['layout_type'], $targetChanges) as $unresolved) {
                $this->lastRebakeReport['unmapped'][] = ['identity' => $key] + $unresolved;
            }
            $this->bakePageFromNodes($target['theme_id'], $target['scope'], $target['identity_hash'],
                $target['layout_type'], $target['nodes'], $target['published'], $target['release_id'],
                $target['draft_revision_id'], $target['version_id'], $targetChanges, $target['current'],
                $target['layout_option'], $target['area'], !empty($target['version_resolved']));
            ++$this->lastRebakeReport['migrated'];
            $scopes[$target['theme_id'] . '|' . $target['scope']] = [$target['theme_id'], $target['scope']];
        }
        // Chrome has its own version authority; enumerate it even when no page artifact exists.
        $chromeAffected = $changes === [];
        foreach ($changes as $change) {
            foreach (array_merge($change['before'] ?? [], $change['after'] ?? []) as $declaration) {
                $chromeAffected = $chromeAffected || $this->sharedChrome->isChromeTarget((string)($declaration['area'] ?? ''), (string)($declaration['slot'] ?? ''));
            }
        }
        if ($chromeAffected) {
            $query = (clone ObjectManager::getInstance(ThemeScopeVersion::class))->clearQuery()->clearData();
            if ($themeId !== null && $themeId > 0) {
                $query->where('theme_id', $themeId);
            }
            $rows = $query->select()->fetchArray();
            $rows = !is_array($rows) || $rows === [] ? [] : (array_is_list($rows) ? $rows : [$rows]);
            foreach ($rows as $row) {
                $version = clone ObjectManager::getInstance(ThemeScopeVersion::class);
                $version->load((int)$row['version_id']);
                $versionChanges = $changesForDraft($version->isCurrent() && !$version->isPublished());
                if ($changes !== [] && $versionChanges === []) {
                    continue;
                }
                $tid = $version->getThemeId();
                $scope = $version->getScope();
                // Scope-version 与布局版本分别拥有身份，按快照映射人工卸载决定。
                $omissionBinding = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutVersionBindingResolver::class)
                    ->resolveChromeOmissions($tid, $scope, $version->getChromePayload());
                if (empty($omissionBinding['resolved'])) {
                    $this->lastRebakeReport['unmapped'][] = [
                        'theme_id' => $tid, 'scope' => $scope, 'chrome_version_id' => $version->getVersionId(),
                        'reason' => (string)($omissionBinding['reason'] ?? 'chrome_layout_version_unmapped'),
                    ];
                    if ($changes !== []) {
                        continue;
                    }
                }
                // 迁移可原样绑定自身权威快照；只有重放注入差异才需要卸载版本映射。
                $nodes = empty($omissionBinding['resolved']) ? $version->getChromePayload()
                    : $merger->mergeIntoNodes($version->getChromePayload(), $tid, 'homepage', 0,
                        $versionChanges, $omissionBinding['omissions']);
                $nodes = $this->slotTree->filterChromeNodes($nodes);
                if ($nodes !== $version->getChromePayload()) {
                    $this->scopeVersions->setChromePayload($version, $nodes);
                }
                $this->materializer->materializeChrome($version);
                $this->pointers->invalidateChrome($tid, $scope);
                $scopes[$tid . '|' . $scope] = [$tid, $scope];
                ++$this->lastRebakeReport['migrated'];
            }
        }
        foreach ($scopes as [$tid, $scope]) {
            $this->bustPresentationCaches($tid, $scope);
        }
        // Scopes with page shells but never-created ThemeScopeVersion get chrome here.
        $bootstrapped = $this->bootstrapMissingChromeScopes($themeId);
        $this->lastRebakeReport['chrome_bootstrapped'] = $bootstrapped;
        $this->lastRebakeReport['migrated'] += $bootstrapped;
        if ($changes !== [] && $this->lastRebakeReport['unmapped'] !== [] && function_exists('w_log_warning')) {
            w_log_warning('theme_layout_injection_versions_unmapped', $this->lastRebakeReport, 'theme_layout_entity');
        }
        return $this->lastRebakeReport['migrated'];
    }

    /**
     * Runtime dynamic solidify for the active theme when published layout.phtml is missing.
     * Merges required default_injections then materializes; returns absolute layout.phtml or ''.
     */
    public function dynamicSolidifyPublishedPage(
        int $themeId,
        string $scope,
        string $identityHash,
        string $layoutType,
        array $nodes,
        ?int $releaseId = null,
        string $layoutOption = 'default',
        string $area = 'frontend',
    ): string {
        if ($themeId < 1 || \trim($scope) === '' || \trim($layoutType) === '') {
            return '';
        }
        try {
            return $this->bakePageFromNodes(
                $themeId,
                $scope,
                $identityHash,
                $layoutType,
                $nodes,
                true,
                $releaseId,
                0,
                layoutOption: $layoutOption,
                area: $area,
            );
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                w_log_warning(
                    'theme_layout_entity_dynamic_solidify_failed: ' . $e->getMessage(),
                    [
                        'theme_id' => $themeId,
                        'scope' => $scope,
                        'layout_type' => $layoutType,
                    ],
                    'theme_layout_entity',
                );
            }

            return '';
        }
    }

    /**
     * Rematerialize an existing published page dir from page-config + required merge.
     */
    public function rematerializePublishedPageAt(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
        string $pageType,
        string $layoutOption = 'default',
        string $area = 'frontend',
    ): string {
        if ($themeId < 1 || $scope === '' || $identityKey === '' || $structureOrRelease === '' || $pageType === '') {
            return '';
        }
        $config = $this->configStore->readPageConfig($themeId, $scope, $identityKey, $structureOrRelease);
        if ($config === []) {
            return '';
        }
        $nodes = [];
        foreach ($config as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? $uid)));
            if ($uid === '') {
                continue;
            }
            $node['node_uid'] = $uid;
            // Legacy/param-only page-config: fill identity from structure before merge.
            $node = $this->configStore->hydratePageNodeFromStructure(
                $node,
                $uid,
                $themeId,
                $scope,
                $identityKey . '/' . $structureOrRelease,
            );
            $nodes[$uid] = $node;
        }
        // Structure slot listing is placement authority when the same uid drifted to content.
        $nodes = $this->healNodeSlotsFromStructure(
            $nodes,
            $themeId,
            $scope,
            $identityKey,
            $structureOrRelease,
        );
        $nodes = $this->mergeRequiredDefaultsIntoNodes($nodes, $themeId, $pageType);
        $nodes = $this->slotTree->filterContentNodes($nodes);
        $releaseId = null;
        if (\preg_match('/^r(\d+)$/', $structureOrRelease, $m) === 1) {
            $releaseId = (int)$m[1];
        }
        $structureKey = hash('sha256', $this->structureKeyForNodes($nodes, 0, null)
            . '|' . $this->sourceLayoutFingerprint($themeId, $pageType, $layoutOption, $area));
        $configByUid = [];
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? '')));
            if ($uid !== '') {
                $configByUid[$uid] = $node;
            }
        }
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        $path = $this->materializer->materializePage(
            $themeId,
            $scope,
            $identityKey,
            $structureKey,
            $nodes,
            $configByUid,
            true,
            $releaseId,
            $pageType,
        );
        if (!\is_file($path)) {
            return '';
        }
        $this->writePublishedWholeShell(
            $themeId,
            $scope,
            $identityKey,
            $paths->pageStructureOrRelease($structureKey, true, $releaseId),
        );

        return $path;
    }

    /**
     * @param array<string|int, mixed> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function mergeRequiredDefaultsIntoNodes(array $nodes, int $themeId, string $pageType, ?int $versionId = null, array $changes = [], string $layoutOption = 'default'): array
    {
        /** @var RequiredDefaultInjectionBakeMerger $merger */
        $merger = ObjectManager::getInstance(RequiredDefaultInjectionBakeMerger::class);

        return $merger->mergeIntoNodes($nodes, $themeId, $pageType, $versionId, $changes, null, $layoutOption);
    }

    /**
     * When structure.json lists a node under slot S but page-config says another slot
     * (commonly parent `content`), prefer structure placement before required merge.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function healNodeSlotsFromStructure(
        array $nodes,
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): array {
        $path = ObjectManager::getInstance(ThemeLayoutEntityPaths::class)
            ->pageStructureJson($themeId, $scope, $identityKey, $structureOrRelease);
        if ($path === '' || !\is_file($path)) {
            return $nodes;
        }
        try {
            $decoded = \json_decode((string)\file_get_contents($path), true);
        } catch (\Throwable) {
            return $nodes;
        }
        if (!\is_array($decoded)) {
            return $nodes;
        }
        $slots = $decoded['slots'] ?? $decoded;
        if (!\is_array($slots)) {
            return $nodes;
        }
        foreach ($slots as $slotId => $widgets) {
            $slotId = \trim((string)$slotId);
            if ($slotId === '' || !\is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                if ($uid === '' || !isset($nodes[$uid]) || !\is_array($nodes[$uid])) {
                    continue;
                }
                $current = \trim((string)($nodes[$uid]['slot_id'] ?? ''));
                if ($current !== '' && $current !== $slotId) {
                    $nodes[$uid]['slot_id'] = $slotId;
                } elseif ($current === '') {
                    $nodes[$uid]['slot_id'] = $slotId;
                }
                foreach (['widget_module', 'widget_code', 'widget_type', 'area'] as $key) {
                    $value = \trim((string)($widget[$key] ?? ''));
                    if ($value !== '' && \trim((string)($nodes[$uid][$key] ?? '')) === '') {
                        $nodes[$uid][$key] = $value;
                    }
                }
            }
        }

        return $nodes;
    }

    private function rematerializePublishedPagesUnderScopeDir(int $themeId, string $scopeDir): int
    {
        $scopeDir = \rtrim($scopeDir, '/\\') . \DIRECTORY_SEPARATOR;
        $scopeKey = \basename(\rtrim($scopeDir, '/\\'));
        if ($scopeKey === '' || $scopeKey === '.' || $scopeKey === '..') {
            return 0;
        }
        $layoutFiles = \glob($scopeDir . 'pages' . \DIRECTORY_SEPARATOR . '*' . \DIRECTORY_SEPARATOR
            . 'r*' . \DIRECTORY_SEPARATOR . 'layout.phtml') ?: [];
        if ($layoutFiles === []) {
            return 0;
        }
        $written = 0;
        foreach ($layoutFiles as $layoutPath) {
            if (!\is_string($layoutPath) || !\is_file($layoutPath)) {
                continue;
            }
            $structureOrRelease = \basename(\dirname($layoutPath));
            $identityKey = \basename(\dirname(\dirname($layoutPath)));
            $pageType = $this->readPageTypeFromStructureJson(\dirname($layoutPath) . \DIRECTORY_SEPARATOR . 'structure.json');
            if ($pageType === '') {
                $pageType = $this->inferPageTypeFromIdentity($themeId, $scopeKey, $identityKey);
            }
            if ($pageType === '') {
                continue;
            }
            try {
                $path = $this->rematerializePublishedPageAt(
                    $themeId,
                    $scopeKey,
                    $identityKey,
                    $structureOrRelease,
                    $pageType,
                );
                if ($path !== '' && \is_file($path)) {
                    ++$written;
                }
            } catch (\Throwable) {
                // Soft: shell refresh + chrome.rendered still run.
            }
        }

        return $written;
    }

    private function rematerializeChromeVersionsUnderScopeDir(int $themeId, string $scopeDir): int
    {
        $scopeDir = \rtrim($scopeDir, '/\\') . \DIRECTORY_SEPARATOR;
        $written = 0;
        foreach ($this->chromeDirsUnderScope($scopeDir) as $chromeDir) {
            $phtml = $chromeDir . \DIRECTORY_SEPARATOR . 'chrome.phtml';
            if (!\is_file($phtml)) {
                continue;
            }
            $versionId = 0;
            if (\preg_match('#/tv(\d+)/chrome#', $chromeDir, $m) === 1) {
                $versionId = (int)$m[1];
            }
            if ($versionId < 1) {
                continue;
            }
            try {
                $version = clone ObjectManager::getInstance(ThemeScopeVersion::class);
                $version->load($versionId);
                if ((int)$version->getVersionId() !== $versionId || (int)$version->getThemeId() !== $themeId) {
                    continue;
                }
                $nodes = $version->getChromePayload();
                if (!\is_array($nodes) || $nodes === []) {
                    continue;
                }
                $merged = $this->mergeRequiredDefaultsIntoNodes($nodes, $themeId, 'homepage');
                $merged = $this->slotTree->filterChromeNodes($merged);
                $this->scopeVersions->setChromePayload($version, $merged);
                $version = clone ObjectManager::getInstance(ThemeScopeVersion::class);
                $version->load($versionId);
                if ((int)$version->getVersionId() !== $versionId) {
                    continue;
                }
                $path = $this->materializer->materializeChrome($version);
                if (\is_file($path)) {
                    ++$written;
                }
            } catch (\Throwable) {
                // Soft: chrome.rendered resolidify still applies Overlay.
            }
        }

        return $written;
    }

    private function rematerializeChromeUnderScopeDir(int $themeId, string $scopeDir): int
    {
        return $this->rematerializeChromeVersionsUnderScopeDir($themeId, $scopeDir);
    }

    private function readPageTypeFromStructureJson(string $path): string
    {
        if (!\is_file($path)) {
            return '';
        }
        $decoded = \json_decode((string)\file_get_contents($path), true);
        if (!\is_array($decoded)) {
            return '';
        }

        return \trim((string)($decoded['page_type'] ?? ''));
    }

    private function inferPageTypeFromIdentity(int $themeId, string $scopeKey, string $identityKey): string
    {
        /** @var RequiredDefaultInjectionBakeMerger $merger */
        $merger = ObjectManager::getInstance(RequiredDefaultInjectionBakeMerger::class);
        $candidates = $merger->involvedExactLayoutTypes();
        if ($merger->hasWildcardRequired()) {
            $candidates = \array_values(\array_unique(\array_merge($candidates, [
                'homepage', 'category', 'product', 'products', 'cart', 'checkout',
                'account/login', 'cms_page',
            ])));
        }
        if ($candidates === []) {
            $candidates = ['homepage'];
        }
        foreach ($candidates as $pageType) {
            $expected = $this->identityKeyForPageType($themeId, $pageType, $scopeKey);
            if ($expected !== '' && $expected === $identityKey) {
                return $pageType;
            }
        }

        return '';
    }

    private function identityKeyForPageType(int $themeId, string $pageType, string $scope): string
    {
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        try {
            /** @var \Weline\Theme\Service\ThemeRuntimeLayoutResolver $resolver */
            $resolver = ObjectManager::getInstance(\Weline\Theme\Service\ThemeRuntimeLayoutResolver::class);
            $context = $resolver->buildContext($themeId, $pageType, 'frontend', [
                'layout_option' => 'default',
                'scope' => $scope,
                'target_type' => 'global',
                'target_id' => 0,
                'locale_code' => 'default',
            ]);

            return $paths->identityKey($context->identityHash());
        } catch (\Throwable) {
            return $paths->identityKey(\hash('sha256', $pageType . '|' . $scope));
        }
    }

    /**
     * Drop durable chrome.rendered.* under a scope dir so runtime re-solidifies
     * with required default_injections (plugin install / injection-collect).
     */
    private function dropChromeRenderedSnapshotsUnderScopeDir(string $scopeDir): int
    {
        $scopeDir = \rtrim($scopeDir, '/\\') . \DIRECTORY_SEPARATOR;
        if (!\is_dir($scopeDir)) {
            return 0;
        }
        $dropped = 0;
        foreach ($this->chromeDirsUnderScope($scopeDir) as $chromeDir) {
            foreach (\glob($chromeDir . \DIRECTORY_SEPARATOR . 'chrome.rendered*.html') ?: [] as $snapshot) {
                if (!\is_string($snapshot) || !\is_file($snapshot)) {
                    continue;
                }
                if (@\unlink($snapshot)) {
                    ++$dropped;
                }
            }
        }

        return $dropped;
    }

    /**
     * Eager chrome.rendered solidify after injection-collect (zh + en baseline).
     * Other locales still dynamic-solidify on first hit (§3.1).
     */
    private function resolidifyChromeRenderedUnderScopeDir(string $scopeDir): int
    {
        $scopeDir = \rtrim($scopeDir, '/\\') . \DIRECTORY_SEPARATOR;
        if (!\is_dir($scopeDir)) {
            return 0;
        }
        $written = 0;
        /** @var ThemeLayoutEntityChrome $chrome */
        $chrome = ObjectManager::getInstance(ThemeLayoutEntityChrome::class);
        foreach ($this->chromeDirsUnderScope($scopeDir) as $chromeDir) {
            $phtml = $chromeDir . \DIRECTORY_SEPARATOR . 'chrome.phtml';
            if (!\is_file($phtml)) {
                continue;
            }
            foreach (['zh_Hans_CN', 'en_US'] as $locale) {
                try {
                    $html = $chrome->forceResolidifyRenderedSnapshot($phtml, $locale);
                    if ($html !== '') {
                        ++$written;
                    }
                } catch (\Throwable) {
                    // Soft: next storefront hit still dynamic-solidifies.
                }
            }
        }

        return $written;
    }

    /**
     * @return list<string>
     */
    private function chromeDirsUnderScope(string $scopeDir): array
    {
        $dirs = \glob($scopeDir . 'tv*' . \DIRECTORY_SEPARATOR . 'chrome', \GLOB_ONLYDIR) ?: [];
        if (\is_dir($scopeDir . 'chrome')) {
            $dirs[] = $scopeDir . 'chrome';
        }
        $out = [];
        foreach ($dirs as $dir) {
            if (\is_string($dir) && \is_dir($dir)) {
                $out[] = $dir;
            }
        }

        return $out;
    }

    /**
     * Concat finalized chrome (via renderCurrent → chrome.rendered) + layout.phtml → shell.phtml.
     *
     * Do NOT paste raw chrome.phtml injectors into the shell: nested footer-*-links stay blank
     * under CTX_SOLIDIFYING, while language/currency hooks still render — the live half-footer bug.
     */
    public function writePublishedWholeShell(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
    ): string {
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        $binding = ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class)
            ->readPageBinding($themeId, $scope, $identityKey, $structureOrRelease);
        if ($binding !== null) {
            return is_file($binding->shellPath) ? $binding->shellPath : '';
        }
        $pagePath = $paths->pagePhtml($themeId, $scope, $identityKey, $structureOrRelease);
        $shellPath = $paths->shellPhtml($themeId, $scope, $identityKey, $structureOrRelease);
        if (!\is_file($pagePath)) {
            return '';
        }

        $pageSrc = (string)\file_get_contents($pagePath);
        $body = "<?php\ndeclare(strict_types=1);\n"
            . "/** Auto-generated whole-shell: chrome via ThemeLayoutEntityChrome::renderCurrent (chrome.rendered). */\n"
            . "?>\n";
        if ($themeId > 0 && \trim($scope) !== '') {
            $body .= $this->buildPublishedShellChromeEchoStub($themeId, $scope);
        }
        $pageBody = \preg_replace(
            '/^\s*<\?php\s+declare\(strict_types=1\);\s*\/\*\*.*?\*\/\s*\?>\s*/s',
            '',
            $pageSrc,
        ) ?? $pageSrc;
        $body .= $pageBody;

        $dir = \dirname($shellPath);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('theme_layout_entity_shell_dir_failed: ' . $dir);
        }
        if (!is_file($shellPath) || file_get_contents($shellPath) !== $body) {
            ObjectManager::getInstance(\Weline\Framework\Compilation\AtomicCompiledFilePublisher::class)
                ->publish($shellPath, $body);
        }
        if (\function_exists('opcache_compile_file')) {
            @\opcache_compile_file($shellPath);
        }

        return $shellPath;
    }

    /**
     * Request-time stub: echo finalized chrome.rendered for the active locale (not raw chrome.phtml).
     */
    private function buildPublishedShellChromeEchoStub(int $themeId, string $scope): string
    {
        $scopeExport = \var_export(\trim($scope), true);

        return "<?php\n"
            . "echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\n"
            . "    \\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityChrome::class\n"
            . ")->renderCurrent({$themeId}, {$scopeExport});\n"
            . "?>\n";
    }

    private function refreshPublishedWholeShellsForScope(int $themeId, string $scope): int
    {
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        $scopeDir = $paths->themeScopeDir($themeId, $scope);

        return $this->refreshPublishedWholeShellsUnderScopeDir($themeId, $scopeDir);
    }

    private function refreshPublishedWholeShellsUnderScopeDir(int $themeId, string $scopeDir): int
    {
        $scopeDir = \rtrim($scopeDir, '/\\') . \DIRECTORY_SEPARATOR;
        if (!\is_dir($scopeDir)) {
            return 0;
        }
        $layoutFiles = \glob($scopeDir . 'pages' . \DIRECTORY_SEPARATOR . '*' . \DIRECTORY_SEPARATOR
            . 'r*' . \DIRECTORY_SEPARATOR . 'layout.phtml') ?: [];
        if ($layoutFiles === []) {
            return 0;
        }

        $scope = \basename(\rtrim($scopeDir, '/\\'));
        if ($scope === '' || $themeId < 1) {
            return 0;
        }

        $written = 0;
        foreach ($layoutFiles as $layoutPath) {
            if (!\is_string($layoutPath) || !\is_file($layoutPath)) {
                continue;
            }
            $shellPath = \dirname($layoutPath) . \DIRECTORY_SEPARATOR . 'shell.phtml';
            $pageSrc = (string)\file_get_contents($layoutPath);
            $body = "<?php\ndeclare(strict_types=1);\n"
                . "/** Auto-generated whole-shell: chrome via ThemeLayoutEntityChrome::renderCurrent (chrome.rendered). */\n"
                . "?>\n";
            $body .= $this->buildPublishedShellChromeEchoStub($themeId, $scope);
            $pageBody = \preg_replace(
                '/^\s*<\?php\s+declare\(strict_types=1\);\s*\/\*\*.*?\*\/\s*\?>\s*/s',
                '',
                $pageSrc,
            ) ?? $pageSrc;
            $body .= $pageBody;
            if (@\file_put_contents($shellPath, $body) !== false) {
                ++$written;
                if (\function_exists('opcache_compile_file')) {
                    @\opcache_compile_file($shellPath);
                }
            }
        }

        return $written;
    }

    /**
     * @param array<string|int, mixed> $nodes
     */
    private function updateConfigSidecarsOnly(
        int $themeId,
        string $scope,
        string $identityHash,
        array $nodes,
        bool $published,
        ?int $releaseId,
        int $draftRevisionId,
        string $layoutType = '',
        string $layoutOption = 'default',
        string $area = 'frontend',
        ?int $versionId = null,
    ): void {
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        $store = ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class);
        $identityKey = $this->pathsIdentityKey($identityHash, $layoutType);
        $entityKey = $published && $releaseId !== null && $releaseId > 0
            ? 'r' . $releaseId : 'd' . $draftRevisionId;
        $binding = $store->readPageBinding($themeId, $scope, $identityKey, $entityKey);
        // 发布身份缺失时只能从该发布快照重建，不能借用未发布草稿。
        if ($binding === null && !$published) {
            $file = $paths->pageCurrentJson($themeId, $scope, $identityKey);
            $current = \is_file($file) ? \json_decode((string)\file_get_contents($file), true) : [];
            $previous = (string)($current['draft'] ?? $current['published'] ?? '');
            if ($previous !== '') {
                $binding = $store->readPageBinding($themeId, $scope, $identityKey, $previous);
            }
        }
        if ($binding === null) {
            // 首次生成或旧格式迁移才需要固化；正常配置写入只复用已有结构。
            $this->bakePageFromNodes($themeId, $scope, $identityHash, $layoutType,
                $nodes, $published, $releaseId, $draftRevisionId, versionId: $versionId, layoutOption: $layoutOption, area: $area);
            return;
        }
        $config = $this->configStore->readBoundConfig($binding);
        foreach ($this->slotTree->filterContentNodes($nodes) as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? $uid)));
            if ($uid !== '') {
                $config[$uid] = \array_replace(\is_array($config[$uid] ?? null) ? $config[$uid] : [], $node);
                $config[$uid]['node_uid'] = $uid;
            }
        }
        $collector = ObjectManager::getInstance(ThemeLayoutEntityAssetCollector::class);
        $store->publishPageBinding($themeId, $scope, $identityKey, $entityKey,
            $binding->structureKey, $config, $collector->collectFromNodes($config, true));
        $this->writePageCurrentPointer($paths, $themeId, $scope, $identityKey, $entityKey, $published);
    }

    /**
     * @param list<\Weline\Theme\Api\Scoped\ThemePatchCommand>|list<array<string,mixed>> $commands
     */
    public function commandsAreStructural(array $commands): bool
    {
        if ($commands === []) {
            return true;
        }
        foreach ($commands as $command) {
            $op = '';
            $path = '';
            if (\is_object($command) && \method_exists($command, 'toArray')) {
                $arr = $command->toArray();
                $op = (string)($arr['op'] ?? $arr['operation'] ?? '');
                $path = (string)($arr['path'] ?? '');
            } elseif (\is_array($command)) {
                $op = (string)($command['op'] ?? $command['operation'] ?? '');
                $path = (string)($command['path'] ?? '');
            }
            $op = \strtoupper($op);
            if (\in_array($op, ['ADD_NODE', 'REMOVE_NODE', 'MOVE_NODE', 'ADD', 'REMOVE', 'MOVE'], true)) {
                return true;
            }
            if ($op === 'SET' || $op === 'OP_SET') {
                if (\preg_match('#/(area|slot_id|sort_order|is_active)(/|$)#', $path) === 1) {
                    return true;
                }
                if (\str_ends_with($path, '/config') || \str_contains($path, '/config/')) {
                    continue;
                }
                // Unknown SET path — treat as structural to be safe for bake gate
                if ($path !== '' && !\str_contains($path, '/config')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<\Weline\Theme\Api\Scoped\ThemePatchCommand>|list<array<string,mixed>> $commands
     * @param array<string|int, mixed> $nodes
     */
    private function commandsTouchChrome(array $commands, array $nodes): bool
    {
        if ($commands === []) {
            foreach ($nodes as $node) {
                if (\is_array($node) && $this->sharedChrome->isChromeTarget(
                    (string)($node['area'] ?? ''), isset($node['slot_id']) ? (string)$node['slot_id'] : null,
                )) {
                    return true;
                }
            }
            return false;
        }
        $byUid = [];
        foreach ($nodes as $uid => $node) {
            if (\is_array($node)) {
                $byUid[(string)($node['node_uid'] ?? $uid)] = $node;
            }
        }
        foreach ($commands as $command) {
            $entry = \is_object($command) && \method_exists($command, 'toArray') ? $command->toArray() : $command;
            if (!\is_array($entry)) {
                continue;
            }
            $path = (string)($entry['path'] ?? '');
            if (\preg_match('#header|footer#i', $path) === 1 || $path === '/nodes') {
                return true;
            }
            \preg_match('#^/nodes/([a-f0-9]{32})(?:/|$)#', $path, $match);
            $uid = (string)($entry['node_uid'] ?? $match[1] ?? '');
            $node = $byUid[$uid] ?? (\is_array($entry['value'] ?? null) ? $entry['value'] : null);
            if ($node === null) {
                // 被删除的节点不在结果中；交给最终结构摘要判断，不能漏掉公共壳删除。
                return true;
            }
            if ($this->sharedChrome->isChromeTarget((string)($node['area'] ?? ''),
                isset($node['slot_id']) ? (string)$node['slot_id'] : null)) {
                return true;
            }
        }
        return false;
    }

    private function writePageCurrentPointer(
        ThemeLayoutEntityPaths $paths,
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
        bool $published,
    ): void {
        if ($structureOrRelease === '' || $identityKey === '') {
            return;
        }
        $file = $paths->pageCurrentJson($themeId, $scope, $identityKey);
        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }
        $publisher = ObjectManager::getInstance(\Weline\Framework\Compilation\AtomicCompiledFilePublisher::class);
        $acquired = $publisher->acquireDirectoryLock($dir);
        try {
            $existing = [];
            if (\is_file($file)) {
                $decoded = \json_decode((string)\file_get_contents($file), true);
                if (\is_array($decoded)) {
                    $existing = $decoded;
                }
            }
            $existing[$published ? 'published' : 'draft'] = $structureOrRelease;
            $json = \json_encode($existing, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }
            if (!is_file($file) || file_get_contents($file) !== $json . "\n") {
                $publisher->publish($file, $json . "\n");
            }
        } finally {
            if ($acquired) {
                $publisher::releaseDirectoryLock($dir);
            }
        }
    }

    /** 写路径按既有主题继承顺序查找一个布局文件，不扫描资源目录。 */
    private function sourceLayoutFingerprint(int $themeId, string $layoutType, string $layoutOption, string $area): string
    {
        $theme = clone ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
        $theme->load($themeId);
        $directories = ObjectManager::getInstance(\Weline\Theme\Service\ThemeDirectoryResolver::class)
            ->getAreaDirectories($area, $theme);
        $sourceHash = '';
        foreach ($directories as $directory) {
            $path = rtrim((string)($directory['path'] ?? ''), '/\\')
                . '/layouts/' . $layoutType . '/' . $layoutOption . '.phtml';
            if (is_file($path)) {
                $sourceHash = (string)hash_file('sha256', $path);
                break;
            }
        }
        return hash('sha256', json_encode([$area, $layoutType, $layoutOption, $sourceHash], JSON_THROW_ON_ERROR));
    }

    /** @param array<string|int, mixed> $nodes */
    private function structureKeyForNodes(array $nodes, int $draftRevisionId, ?int $releaseId): string
    {
        $structural = [];
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $structural[] = [
                'node_uid' => (string)($node['node_uid'] ?? ''),
                'area' => (string)($node['area'] ?? ''),
                'slot_id' => $node['slot_id'] ?? null,
                'widget_code' => (string)($node['widget_code'] ?? ''),
                'widget_module' => (string)($node['widget_module'] ?? ''),
                'widget_type' => (string)($node['widget_type'] ?? ''),
                'sort_order' => (int)($node['sort_order'] ?? 0),
                'is_active' => (bool)($node['is_active'] ?? true),
            ];
        }
        \usort($structural, static fn(array $a, array $b): int => strcmp($a['node_uid'], $b['node_uid']));
        $payload = \json_encode([
            'nodes' => $structural,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \hash('sha256', \is_string($payload) ? $payload : '');
    }

    private function pathsIdentityKey(string $identityHash, string $layoutType): string
    {
        $hash = \strtolower(\trim($identityHash));
        if ($hash !== '' && \preg_match('/^[a-f0-9]{16,64}$/', $hash) === 1) {
            return \substr($hash, 0, 16);
        }
        $layoutType = \trim($layoutType);

        return $layoutType !== '' ? \substr(\hash('sha256', $layoutType), 0, 16) : 'page';
    }

    private function bustPresentationCaches(int $themeId, ?string $scope = null): void
    {
        try {
            /** @var ThemeRuntimeCacheCleaner $cleaner */
            $cleaner = ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class);
            $cleaner->clearLayoutEntityCaches($themeId > 0 ? $themeId : null, $scope);
        } catch (\Throwable $e) {
            throw new \RuntimeException('theme_layout_entity_presentation_bust_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Homepage carrier owns global chrome. If layout has chrome nodes missing from
     * ThemeScopeVersion payload (e.g. after config-only partial write), merge + rebake.
     *
     * @param array<string|int, mixed> $nodes
     */
    public function syncCarrierChromePayloadIfStale(
        int $themeId,
        string $scope,
        array $nodes,
        bool $published,
    ): bool {
        $expected = $this->slotTree->filterChromeNodes($nodes);
        if ($expected === []) {
            return false;
        }

        $version = $this->scopeVersions->ensureCurrent($themeId, $scope);
        $current = $version->getChromePayload();
        $hasMissing = false;
        foreach ($expected as $uid => $node) {
            if (!isset($current[$uid])) {
                $hasMissing = true;
                break;
            }
        }
        if (!$hasMissing) {
            return false;
        }

        $merged = $this->mergeChromePayloadNodes($current, $expected);
        $this->scopeVersions->setChromePayload($version, $merged);
        $version = $this->scopeVersions->getCurrent($themeId, $scope) ?? $version;
        $path = $this->materializer->materializeChrome($version);
        if (!\is_file($path)) {
            throw new \RuntimeException('theme_layout_entity_chrome_sync_rebake_failed');
        }
        $this->pointers->rememberChromePointer($themeId, $scope, $version->getVersionId(), $path, false);
        if ($published || $version->isPublished()) {
            $this->pointers->rememberChromePointer($themeId, $scope, $version->getVersionId(), $path, true);
        }
        $this->bustPresentationCaches($themeId);

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $existing
     * @param array<string, array<string, mixed>> $incoming
     * @return array<string, array<string, mixed>>
     */
    private function mergeChromePayloadNodes(array $existing, array $incoming): array
    {
        $merged = $existing;
        foreach ($incoming as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $key = \strtolower(\trim((string)$uid));
            if ($key === '') {
                continue;
            }
            if (isset($merged[$key]) && \is_array($merged[$key])) {
                $merged[$key] = \array_replace($merged[$key], $node);
                $merged[$key]['node_uid'] = $key;
            } else {
                $node['node_uid'] = $key;
                $merged[$key] = $node;
            }
        }

        return $merged;
    }
}
