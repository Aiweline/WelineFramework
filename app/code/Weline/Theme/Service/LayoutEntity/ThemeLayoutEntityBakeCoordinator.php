<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;

/** Save-time projection: database intent + current defaults -> ordinary PHTML. */
final class ThemeLayoutEntityBakeCoordinator
{
    private array $lastRebakeReport = ['migrated' => 0, 'unmapped' => [], 'chrome_bootstrapped' => 0];

    public function __construct(
        private readonly ThemeScopeVersionService $scopeVersions,
        private readonly ThemeLayoutEntityMaterializer $materializer,
        private readonly ThemeLayoutEntityConfigStore $configStore,
        private readonly ThemeLayoutEntityPointerResolver $pointers,
        private readonly ThemeLayoutSlotTreeBuilder $slotTree,
        private readonly SharedChromeService $sharedChrome,
    ) {}

    public function getLastRebakeReport(): array { return $this->lastRebakeReport; }

    /** Database R has committed; the caller still owns the same owner write lock. */
    public function afterResourceWrite(ThemeEditorContext $context, array $saved, array $changes = []): void
    {
        $identity = isset($saved['version_identity'])
            ? ThemeVersionIdentity::fromArray($saved['version_identity'])
            : $this->resolveBakeIdentity($context->themeId, $context->scope->storageScope,
                !empty($saved['release_id']), (int)($saved['theme_version_id'] ?? 0), $context->area, $context->scope->storeMode);
        ThemeLayoutEntityOwnerLock::write($identity, function () use ($identity, $context, $changes): void {
            $version = $this->loadVersion($identity);
            if ($version->getContentRevision() !== $identity->contentRevision) {
                throw new \RuntimeException('theme_layout_saved_revision_superseded');
            }
            $candidates = $this->candidateWorkset($identity, $context, $changes);
            $this->publish($identity, $candidates);
        });
        $this->bustPresentationCaches($identity->themeId, $identity->canonicalScope, $identity->storeMode);
    }

    /** Pure candidates for a pinned historical R; callers may render these in memory. */
    public function candidateForIdentity(ThemeVersionIdentity $identity, string $layoutType, string $layoutOption = 'default', string $targetType = 'global', ?int $targetId = null, array $changes = []): array
    {
        $chromeCandidates = [];
        return $this->candidateWithChromeReuse($identity, $layoutType, $layoutOption, $targetType, $targetId, $changes, $chromeCandidates);
    }

    /** Reuse only byte-equivalent common inputs inside this one synchronous workset. */
    private function candidateWithChromeReuse(ThemeVersionIdentity $identity, string $layoutType, string $layoutOption, string $targetType, ?int $targetId, array $changes, array &$chromeCandidates): array
    {
        $changes = array_values(array_filter($changes, static fn($change): bool => is_array($change) && (isset($change['before']) || isset($change['after']))));
        $context = $this->context($identity, $layoutType, $layoutOption, $targetType, $targetId);
        $snapshot = $this->snapshotService()->read($identity, $context);
        if (empty($snapshot['resolved'])) { throw new \RuntimeException((string)$snapshot['reason']); }
        $payload = $snapshot['payload'];
        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : $payload;
        $meta = $this->snapshotService()->read($identity, $context->withResource(ThemeEditorContext::RESOURCE_META));
        if (empty($meta['resolved'])) { throw new \RuntimeException((string)$meta['reason']); }
        $configuration = $this->configuration($identity);
        $params = array_replace((array)($configuration['params']['layouts.' . $layoutType . '.' . $layoutOption] ?? []), (array)($meta['payload']['values'] ?? []));
        $version = $this->loadVersion($identity);
        $head = $this->snapshotService()->head($identity);
        $chrome = json_decode((string)($head['chrome_intent_json'] ?? '[]'), true);
        $version->setChromePayload(is_array($chrome) ? $chrome : []);
        $version->setContentRevision($identity->contentRevision)->setLifecycle($identity->mode === 'draft' ? ThemeScopeVersion::LIFECYCLE_DRAFT : ThemeScopeVersion::LIFECYCLE_SEALED);
        $localeNodes = ObjectManager::getInstance(RequiredDefaultInjectionBakeMerger::class)->mergeIntoNodes(
            array_replace($nodes, $version->getChromePayload()), $identity->themeId, $context->layoutType,
            $identity->themeVersionId, $changes, $this->frozenOmissions($identity, $context->layoutType), $context->layoutOption);
        $localeNodes = $this->materializer->discoverPageNativeOwners($identity, $context->layoutType, $context->layoutOption, $localeNodes);
        $locales = $this->localeOverrides($identity, $context, $localeNodes, $params);
        $options = $this->partialOptions($identity, $context);
        $candidates = $this->pageCandidates($identity, $context, $nodes, !empty($snapshot['has_intent']) || !empty($meta['has_intent']), $locales, $changes, $params);
        $partialParams = [];
        foreach ($options as $type => $option) {
            $key = 'partials.' . $type . '.' . $option;
            $partialParams[$type] = (array)($configuration['params'][$key] ?? []);
            foreach ($configuration['locale_params'] ?? [] as $locale => $configs) {
                if (isset($configs[$key])) { $locales[$locale]['partials.' . $type] = (new ThemeLayoutEntityInputResolver())->localizedConfig($partialParams[$type], $configs[$key]); }
            }
        }
        $chromeKey = hash('sha256', serialize([$identity->toArray(), $version->getChromePayload(), $locales, $options, $changes, $partialParams]));
        if (!array_key_exists($chromeKey, $chromeCandidates)) {
            $chromeCandidates[$chromeKey] = $this->chromeCandidates($version, $locales, $options, $changes, $partialParams);
        }
        return array_replace($candidates, $chromeCandidates[$chromeKey]);
    }

    private function pageCandidates(ThemeVersionIdentity $identity, ThemeEditorContext $context, array $nodes, bool $hasIntent, array $locales = [], array $changes = [], array $params = []): array
    {
        $merger = ObjectManager::getInstance(RequiredDefaultInjectionBakeMerger::class);
        $omissions = $this->frozenOmissions($identity, $context->layoutType);
        $defaults = $this->contentNodesForLayout(
            $context->layoutType,
            $merger->mergeIntoNodes([], $identity->themeId, $context->layoutType, $identity->themeVersionId, $changes, $omissions, $context->layoutOption),
        );
        $clearAll = array_filter($nodes, static fn($node): bool => is_array($node) && ($node['widget_code'] ?? '') === '__no_widget_placements__') !== [];
        $nodes = $this->contentNodesForLayout(
            $context->layoutType,
            $clearAll ? $nodes : $merger->mergeIntoNodes($nodes, $identity->themeId, $context->layoutType, $identity->themeVersionId, $changes, $omissions, $context->layoutOption),
        );
        if (!$clearAll) { $nodes = $this->materializer->discoverPageNativeOwners($identity, $context->layoutType, $context->layoutOption, $nodes); }
        $nodes = $this->themeConfiguredNodes($identity, (new ThemeLayoutEntityInputResolver())->placements($nodes));
        $path = $this->paths()->pageLayoutPhtml($identity, $context->layoutType, $context->layoutOption, $context->targetType, $context->targetId ?: null);
        $hasLocaleParams = array_filter($locales, static fn(array $values): bool => array_key_exists('layout', $values)) !== [];
        if (!$hasIntent && $defaults === [] && $params === [] && !$hasLocaleParams) { return [$path => null]; }
        return $this->materializer->candidatePage($identity, $context->identityHash(),
            hash('sha256', json_encode([$nodes, $locales], JSON_THROW_ON_ERROR)), $nodes, $nodes,
            $context->layoutType, $context->layoutOption, $context->targetType, $context->targetId ?: null, $locales, $params);
    }

    private function chromeCandidates(ThemeScopeVersion $version, array $locales = [], array $options = [], array $changes = [], array $partialParams = []): array
    {
        $merger = ObjectManager::getInstance(RequiredDefaultInjectionBakeMerger::class);
        $original = $version->getChromePayload();
        $identity = $version->toVersionIdentity();
        // Homepage owns root chrome; mini-cart drawer footer-extras (coupon/留言) is a nested
        // chrome extension declared layout_type=mini-cart. Homepage merge alone never seeds it,
        // and runtime SlotFiller is a no-op under pure-PHTML bake — so chrome must merge both.
        $nodes = $merger->mergeIntoNodes(
            $original,
            $version->getThemeId(),
            'homepage',
            $version->getVersionId(),
            $changes,
            $this->frozenOmissions($identity, 'homepage'),
        );
        $nodes = $merger->mergeIntoNodes(
            $nodes,
            $version->getThemeId(),
            'mini-cart',
            $version->getVersionId(),
            $changes,
            $this->frozenOmissions($identity, 'mini-cart'),
        );
        $nodes = $this->slotTree->filterChromeNodes($nodes);
        $options = $options ?: ['header' => 'default', 'footer' => 'default', 'sidebar' => 'default'];
        $nodes = $this->attachHeaderNativeMiniCartOwners($identity, $nodes, (string)($options['header'] ?? 'default'));
        $version = clone $version;
        $version->setChromePayload($this->themeConfiguredNodes($identity, (new ThemeLayoutEntityInputResolver())->placements($nodes)));
        $partialLocaleKeys = array_fill_keys(array_map(static fn(string $type): string => 'partials.' . $type, array_keys($options)), true);
        $hasLocaleParams = array_filter($locales, static fn(array $values): bool => array_intersect_key($values, $partialLocaleKeys) !== []) !== [];
        if ($original === [] && $nodes === [] && !array_filter($partialParams) && !$hasLocaleParams && !array_filter($options, static fn($option): bool => $option !== 'default')) {
            $out = [];
            foreach ($options as $type => $option) { $out[$this->paths()->partialPhtml($version->toVersionIdentity(), $type, $option)] = null; }
            return $out;
        }
        return $this->materializer->candidateChrome($version, $locales, $options, $partialParams);
    }

    /**
     * Keep mini-cart layout preview slots that are chrome-classified by name/area
     * (footer-extras → footer-* heuristic) so editor/page bake still writes coupon/留言.
     *
     * @param array<string|int, mixed> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function contentNodesForLayout(string $layoutType, array $nodes): array
    {
        $content = $this->slotTree->filterContentNodes($nodes);
        if (\trim($layoutType) !== 'mini-cart') {
            return $content;
        }
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }
            if (\trim((string)($node['slot_id'] ?? '')) !== 'footer-extras') {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? $key)));
            if ($uid === '') {
                continue;
            }
            $node['node_uid'] = $uid;
            $content[$uid] = $node;
        }

        return $content;
    }

    /**
     * Parent footer-extras default injections under source-native mini-cart-icon in header.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function attachHeaderNativeMiniCartOwners(ThemeVersionIdentity $identity, array $nodes, string $headerOption): array
    {
        $hasExtras = false;
        foreach ($nodes as $node) {
            if (\is_array($node) && \trim((string)($node['slot_id'] ?? '')) === 'footer-extras') {
                $hasExtras = true;
                break;
            }
        }
        if (!$hasExtras) {
            return $nodes;
        }
        $headerOption = \trim($headerOption) !== '' ? $headerOption : 'default';
        try {
            $theme = (clone ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class))
                ->clearData()->clearQuery()->load($identity->themeId);
            $resources = ObjectManager::getInstance(\Weline\Theme\Service\ThemeResourceCatalog::class)
                ->getResources('partials', $identity->area, $theme);
            $resource = $resources['partials/header/' . $headerOption]
                ?? $resources['partials/header/default']
                ?? null;
            $origin = \is_array($resource) ? (string)($resource['file_path'] ?? '') : '';
            if ($origin === '' || !\is_file($origin)) {
                return $nodes;
            }
            $source = (string)\file_get_contents($origin);

            return (new LayoutRelationCompiler(null, $theme))->discoverNativeOwners($source, $nodes, true);
        } catch (\Throwable) {
            return $nodes;
        }
    }

    /** Resolve complete locale configs once at generation time. */
    private function localeOverrides(ThemeVersionIdentity $identity, ThemeEditorContext $context, array $nodes, array $params = []): array
    {
        $out = [];
        $configs = $this->materializer->resolveNodeConfigurations($this->themeConfiguredNodes($identity, $nodes), $identity, $context->layoutOption, $context->targetType, $context->targetId ?: null);
        $inputs = new ThemeLayoutEntityInputResolver();
        $configuration = $this->configuration($identity);
        foreach ($configuration['locale_params'] ?? [] as $locale => $overrides) {
            $localized = $this->materializer->resolveNodeConfigurations($this->themeConfiguredNodes($identity, $nodes, $locale), $identity, $context->layoutOption, $context->targetType, $context->targetId ?: null);
            foreach ($localized as $uid => $config) {
                if ($config !== ($configs[$uid] ?? [])) { $out[$locale][$uid] = $config; }
            }
            $layoutKey = 'layouts.' . $context->layoutType . '.' . $context->layoutOption;
            if (isset($overrides[$layoutKey])) { $out[$locale]['layout'] = $inputs->localizedConfig($params, $overrides[$layoutKey]); }
        }
        foreach ($this->snapshotService()->resources($identity) as $row) {
            if (($row['resource_type'] ?? '') !== ThemeEditorContext::RESOURCE_I18N) { continue; }
            $key = json_decode((string)($row['resource_key_json'] ?? '{}'), true);
            if (($key['layout_type'] ?? '') !== $context->layoutType || ($key['layout_option'] ?? '') !== $context->layoutOption
                || ($key['target_type'] ?? 'global') !== $context->targetType || (int)($key['target_id'] ?? 0) !== $context->targetId) { continue; }
            $locale = (string)($key['locale'] ?? 'default');
            $read = $this->snapshotService()->read($identity, $context->withResource(ThemeEditorContext::RESOURCE_I18N)->withLocale($locale));
            if (empty($read['resolved'])) { throw new \RuntimeException((string)$read['reason']); }
            foreach ((array)($read['payload']['translations'] ?? []) as $uid => $overlay) {
                if (!is_array($overlay)) { continue; }
                $base = $out[$locale][$uid] ?? ($uid === 'layout' ? $params : (array)($configs[$uid] ?? []));
                $out[$locale][$uid] = $inputs->localizedConfig($base, $overlay);
            }
        }
        return $out;
    }

    private function configuration(ThemeVersionIdentity $identity): array
    {
        $head = $this->snapshotService()->head($identity);
        $descriptor = json_decode((string)($head['package_default_json'] ?? '{}'), true);
        return (array)($descriptor['configuration'] ?? []);
    }
    private function frozenOmissions(ThemeVersionIdentity $identity, string $type): array
    {
        $head = $this->snapshotService()->head($identity);
        $descriptor = json_decode((string)($head['package_default_json'] ?? '{}'), true);
        return (array)($descriptor['omissions'][$type] ?? []);
    }
    private function themeConfiguredNodes(ThemeVersionIdentity $identity, array $nodes, string $locale = ''): array
    {
        $configuration = $this->configuration($identity);
        foreach ($nodes as &$node) {
            $key = ($node['widget_module'] ?? '') === 'Weline_Theme' && ($node['widget_type'] ?? '') === 'theme_component'
                ? 'components.' . str_replace('/', '.', (string)($node['widget_code'] ?? ''))
                : 'widgets.' . (string)($node['widget_module'] ?? '') . '.' . (string)($node['widget_code'] ?? '');
            $base = (array)($configuration['params'][$key] ?? []);
            if ($locale !== '') { $base = (new ThemeLayoutEntityInputResolver())->localizedConfig($base, (array)($configuration['locale_params'][$locale][$key] ?? [])); }
            $node['config'] = array_replace($base, (array)($node['config'] ?? []));
        }
        unset($node);
        return $nodes;
    }
    private function partialOptions(ThemeVersionIdentity $identity, ThemeEditorContext $context): array
    {
        return (array)($this->configuration($identity)['partial_options'] ?? ['header'=>'default','footer'=>'default','sidebar'=>'default']);
    }

    public function refreshResourceArtifacts(ThemeEditorContext $context, string $status, int $versionId = 0): array
    {
        $identity = $this->resolveBakeIdentity($context->themeId, $context->scope->storageScope, $status === 'published', $versionId, $context->area, $context->scope->storeMode);
        // 页产物路径：与 pageCandidates()/candidatePage() 同源（pageLayoutPhtml 内部会对路由段做幂等规范化）。
        $pagePath = $this->paths()->pageLayoutPhtml($identity, $context->layoutType, $context->layoutOption, $context->targetType, $context->targetId ?: null);
        $candidates = ThemeLayoutEntityOwnerLock::write($identity, function () use ($identity, $context): array {
            $candidates = $this->candidateForIdentity($identity, $context->layoutType, $context->layoutOption, $context->targetType, $context->targetId);
            $this->publish($identity, $candidates);
            return $candidates;
        });
        $artifacts = [];
        // 回执按页/部件分型：消费方据 type==='page' 判定页面产物是否落地，不能统一报成 phtml。
        foreach ($candidates as $path => $bytes) {
            if ($bytes === null) { continue; }
            $artifacts[] = $this->resourceArtifactReceipt($path === $pagePath ? 'page' : 'chrome', $path);
        }
        $this->bustPresentationCaches($identity->themeId, $identity->canonicalScope, $identity->storeMode);
        $workspace = ObjectManager::getInstance(\Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface::class)->load($context, true);
        $entityKey = $status === 'published'
            ? 'r' . (int)($workspace['effective_release_id'] ?? $workspace['published_release_id'] ?? 0)
            : 'd' . (int)($workspace['draft_revision_id'] ?? 0);
        // version_id 必须回显调用方选定的版本（未选定时为 null），不能冒充实际烘焙版本。
        return ['status' => $status, 'version_id' => $versionId > 0 ? $versionId : null, 'content_revision' => $identity->contentRevision, 'entity_key' => $entityKey, 'artifacts' => $artifacts];
    }

    public function bakePublishArtifactsForVersion(ThemeEditorContext $context, int $themeVersionId, array $options = []): array
    {
        $identity = $this->resolveBakeIdentity($context->themeId, $context->scope->storageScope, true, $themeVersionId, $context->area, $context->scope->storeMode);
        return ThemeLayoutEntityOwnerLock::write($identity, function () use ($identity, $context, $options): array {
            $candidates = $this->candidateWorkset($identity, $context);
            if (is_array($options['draft_prime_identity'] ?? null)) {
                $prime = ThemeVersionIdentity::fromArray($options['draft_prime_identity']);
                if ($prime->ownerHash() !== $identity->ownerHash()) { throw new \InvalidArgumentException('theme_publication_remainder_owner_mismatch'); }
                $candidates = array_replace($candidates, $this->candidateWorkset($prime, $context));
            }
            $this->publish($identity, $candidates);
            return ['ok'=>true, 'theme_version_id'=>$identity->themeVersionId, 'content_revision'=>$identity->contentRevision,
                'fingerprints'=>array_map(static fn($bytes): string => hash('sha256', (string)$bytes), $candidates)];
        });
    }

    private function candidateWorkset(ThemeVersionIdentity $identity, ThemeEditorContext $context, array $changes = []): array
    {
        $targets = [$context->toArray(), ...$this->declaredPageTargets($identity, $changes), ...$this->existingPageTargets($identity)];
        if ($identity->mode === 'formal') {
            $targets = array_merge($targets, $this->existingPageTargets($identity->withVersion($identity->themeVersionId, 'draft', $identity->contentRevision)));
        }
        foreach ($this->snapshotService()->resources($identity) as $row) {
            if (($row['resource_type'] ?? '') === 'layout') { $targets[] = json_decode((string)$row['resource_key_json'], true); }
        }
        $seen = []; $candidates = []; $chromeCandidates = [];
        foreach ($targets as $key) {
            if (!is_array($key)) { continue; }
            $type = (string)($key['layout_type'] ?? 'default'); $option = (string)($key['layout_option'] ?? 'default');
            $target = (string)($key['target_type'] ?? 'global'); $targetId = (int)($key['target_id'] ?? 0);
            $hash = json_encode([$type,$option,$target,$targetId]);
            if (isset($seen[$hash])) { continue; }
            $seen[$hash] = true;
            $candidates = array_replace($candidates, $this->candidateWithChromeReuse($identity,$type,$option,$target,$targetId,$changes,$chromeCandidates));
        }
        return $candidates;
    }

    public function afterLayoutWrite(int $themeId, string $scope, string $layoutType, string $identityHash, array $nodes, array $commands, bool $published, ?int $releaseId, int $draftRevisionId = 0, string $layoutOption = 'default', string $area = 'frontend', ?int $versionId = null, string $targetType = 'global', ?int $targetId = null, string $storeMode = 'normal'): void
    {
        $identity = $this->resolveBakeIdentity($themeId, $scope, $published, $versionId, $area, $storeMode);
        $this->bakePageFromNodes($identity->themeId, $identity->canonicalScope, $identityHash, $layoutType, $nodes, $published, $releaseId, $draftRevisionId, $identity->themeVersionId, [], true, $layoutOption, $area, true, $targetType, $targetId, $commands !== [], $identity->storeMode);
        $this->bustPresentationCaches($identity->themeId, $identity->canonicalScope, $identity->storeMode);
    }

    public function bakePageFromNodes(int $themeId, string $scope, string $identityHash, string $layoutType, array $nodes, bool $published, ?int $releaseId, int $draftRevisionId = 0, ?int $versionId = null, array $changes = [], bool $updateCurrent = true, string $layoutOption = 'default', string $area = 'frontend', bool $mergeDefaults = true, string $targetType = 'global', ?int $targetId = null, ?bool $hasIntent = null, string $storeMode = 'normal'): string
    {
        $identity = $this->resolveBakeIdentity($themeId, $scope, $published, $versionId, $area, $storeMode);
        return ThemeLayoutEntityOwnerLock::write($identity, function () use ($identity, $layoutType, $layoutOption, $targetType, $targetId, $nodes, $hasIntent, $draftRevisionId, $releaseId, $changes): string {
            $context = $this->context($identity, $layoutType, $layoutOption, $targetType, $targetId);
            $candidates = $this->pageCandidates($identity, $context, $nodes, $hasIntent ?? ($draftRevisionId > 0 || $releaseId !== null), [], $changes);
            $candidates = array_replace($candidates, $this->chromeCandidates($this->loadVersion($identity), [], [], $changes));
            $this->publish($identity, $candidates);
            $path = $this->paths()->pageLayoutPhtml($identity, $layoutType, $layoutOption, $targetType, $targetId);
            return is_file($path) ? $path : '';
        });
    }

    public function bakeChromeFromNodes(int $themeId, string $scope, array $nodes, bool $structural = true, bool $invalidate = true, ?int $versionId = null, string $storeMode = 'normal'): string
    {
        $identity = $this->resolveBakeIdentity($themeId, $scope, false, $versionId, 'frontend', $storeMode);
        return ThemeLayoutEntityOwnerLock::write($identity, function () use ($identity, $nodes, $invalidate): string {
            $version = $this->loadVersion($identity);
            $version->setChromePayload($this->slotTree->filterChromeNodes($nodes));
            $candidates = $this->chromeCandidates($version);
            $this->publish($identity, $candidates);
            if ($invalidate) { $this->bustPresentationCaches($identity->themeId, $identity->canonicalScope, $identity->storeMode); }
            foreach ($candidates as $path => $bytes) { if ($bytes !== null) { return $path; } }
            return '';
        });
    }

    /** Upgrade/default-plan changes affect the union of before and after targets in every proven version. */
    public function rebakeAfterInjectionCollect(?int $themeId = null, array $changes = []): int
    {
        $this->lastRebakeReport = ['migrated' => 0, 'unmapped' => [], 'chrome_bootstrapped' => 0];
        $query = (clone ObjectManager::getInstance(ThemeScopeVersion::class))->clearData()->clearQuery();
        if ($themeId !== null) { $query->where('theme_id', $themeId); }
        $rows = $query->select()->fetchArray();
        $rows = !is_array($rows) || $rows === [] ? [] : (array_is_list($rows) ? $rows : [$rows]);
        foreach ($rows as $row) {
            $version = (clone ObjectManager::getInstance(ThemeScopeVersion::class))->clearData()->setData($row);
            $identity = $version->toVersionIdentity();
            // Every D shares the owner's one draft directory. Historical D is
            // still available to candidateForIdentity, but never owns that path.
            if (!$this->ownsPublicationDirectory($identity)) { continue; }
            $head = $this->snapshotService()->head($identity);
            $descriptor = json_decode((string)($head['package_default_json'] ?? '{}'), true);
            if ($identity->contentRevision < 1 || $head === null || empty($descriptor['current_package_defaults'])) {
                $current = $this->scopeVersions->getCurrent($identity->themeId, $identity->canonicalScope, $identity->storeMode, $identity->area);
                $published = $this->scopeVersions->getPublished($identity->themeId, $identity->canonicalScope, $identity->storeMode, $identity->area);
                if ($current?->getVersionId() === $identity->themeVersionId || $published?->getVersionId() === $identity->themeVersionId) {
                    try {
                        ThemeLayoutEntityOwnerLock::write($identity, function () use ($version, &$identity): void {
                            $initial = $version->getContentRevision() < 1;
                            if ($initial) { $version->setContentRevision(1)->save(); }
                            $identity = $version->toVersionIdentity();
                            $this->snapshotService()->captureCurrent($version, $this->context($identity, 'homepage', 'default', 'global', null), $initial);
                            $identity = $version->toVersionIdentity();
                        });
                    } catch (\Throwable $error) {
                        $this->reportMissingSourceOrThrow($identity, $error);
                        continue;
                    }
                } else {
                    $this->lastRebakeReport['unmapped'][] = ['version_id' => $identity->themeVersionId, 'content_revision' => $identity->contentRevision, 'reason' => 'historical_revision_head_missing'];
                    continue;
                }
            }
            $targets = [];
            foreach ($this->snapshotService()->resources($identity) as $resource) {
                if (($resource['resource_type'] ?? '') !== 'layout') { continue; }
                $key = json_decode((string)$resource['resource_key_json'], true);
                if (is_array($key)) { $targets[] = $key; }
            }
            // The injection plan can add a layout that never had an editor workspace.
            foreach ($changes as $change) {
                foreach (array_merge($change['before'] ?? [], $change['after'] ?? []) as $declaration) {
                    $type = (string)($declaration['layout_type'] ?? '*');
                    if ($type !== '' && $type !== '*') { $targets[] = ['layout_type' => $type, 'layout_option' => ($declaration['layout_option'] ?? 'default') === '*' ? 'default' : ($declaration['layout_option'] ?? 'default')]; }
                }
            }
            $targets = array_merge($targets, $this->existingPageTargets($identity), $this->declaredPageTargets($identity, $changes));
            if ($targets === []) { $targets[] = ['layout_type' => 'homepage', 'layout_option' => 'default']; }
            try {
                ThemeLayoutEntityOwnerLock::write($identity, function () use (&$identity, $targets, $changes): void {
                    if (!$this->ownsPublicationDirectory($identity)) { return; }
                    if ($identity->mode === ThemeVersionIdentity::MODE_DRAFT) {
                        $version = $this->loadVersion($identity);
                        $identity = $version->toVersionIdentity();
                        $identity = $this->snapshotService()->captureUnresolvedCurrentIntent($version,
                            $this->context($identity, 'homepage', 'default', 'global', null)) ?? $identity;
                    }
                    $candidates = [];
                    foreach ($targets as $key) {
                        $type = (string)($key['layout_type'] ?? 'default'); $option = (string)($key['layout_option'] ?? 'default');
                        if (!ThemeLayoutEntityInjectionTargets::affects($changes, $type, $option)) { continue; }
                        $candidates = array_replace($candidates, $this->candidateForIdentity($identity, $type, $option,
                            (string)($key['target_type'] ?? 'global'), (int)($key['target_id'] ?? 0), $changes));
                    }
                    $this->publish($identity, $candidates);
                    $this->lastRebakeReport['migrated'] += count($candidates);
                });
                $this->bustPresentationCaches($identity->themeId, $identity->canonicalScope, $identity->storeMode);
            } catch (\Throwable $error) {
                $this->reportMissingSourceOrThrow($identity, $error);
            }
        }
        return $this->lastRebakeReport['migrated'];
    }

    private function ownsPublicationDirectory(ThemeVersionIdentity $identity): bool
    {
        if ($identity->mode !== ThemeVersionIdentity::MODE_DRAFT) { return true; }
        return $this->scopeVersions->getCurrent($identity->themeId, $identity->canonicalScope, $identity->storeMode, $identity->area)
            ?->getVersionId() === $identity->themeVersionId;
    }

    private function reportMissingSourceOrThrow(ThemeVersionIdentity $identity, \Throwable $error): void
    {
        if ($error instanceof \InvalidArgumentException && $error->getMessage() === 'system_config_website_scope_not_found') {
            $scope = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutScopeNormalizer::class)
                ->identityFromEncodedScope($identity->canonicalScope);
            try {
                // A legacy version row can outlive its deleted Website. Zero
                // is a valid existing website ID and must never mean absent.
                ObjectManager::getInstance(\Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface::class)
                    ->websiteIdForCode((string)$scope->websiteCode);
            } catch (\InvalidArgumentException $missing) {
                if ($missing->getMessage() !== 'system_config_website_scope_not_found') { throw $missing; }
                $this->lastRebakeReport['unmapped'][] = ['version_id'=>$identity->themeVersionId,
                    'content_revision'=>$identity->contentRevision, 'theme_id'=>$identity->themeId,
                    'canonical_scope'=>$identity->canonicalScope, 'store_mode'=>$identity->storeMode, 'area'=>$identity->area,
                    'website_code'=>(string)$scope->websiteCode, 'reason'=>'historical_scope_owner_missing',
                    'source_error'=>$error->getMessage()];
                return;
            }
        }
        if (!in_array($error->getMessage(), [
            'historical_revision_head_missing',
            'historical_resource_snapshot_missing',
            'historical_intent_reference_missing',
            'historical_release_reference_missing',
            'historical_resource_reference_missing',
        ], true)) {
            throw $error;
        }
        $this->lastRebakeReport['unmapped'][] = ['version_id'=>$identity->themeVersionId,
            'content_revision'=>$identity->contentRevision, 'reason'=>$error->getMessage()];
    }

    public function ensurePublishedChromeForScope(int $themeId, string $scope, array $nodes = []): string
    {
        $version = $this->scopeVersions->getPublished($themeId, $scope);
        if ($version === null) { return ''; }
        $path = $this->paths()->partialPhtml($version->toVersionIdentity(), 'header', 'default');
        return is_file($path) ? $path : '';
    }

    public function bootstrapMissingChromeScopes(?int $themeId = null): int { return 0; }
    public function commandsAreStructural(array $commands): bool
    {
        foreach ($commands as $command) {
            $row = is_object($command) && method_exists($command, 'toArray') ? $command->toArray() : $command;
            $path = (string)($row['path'] ?? '');
            if (str_contains($path, '/config')) { continue; }
            return true;
        }
        return $commands === [];
    }
    public function syncCarrierChromePayloadIfStale(int $themeId, string $scope, array $nodes, bool $published): bool { return false; }
    public function writePublishedWholeShell(ThemeVersionIdentity $identity, string $layoutIdentityHash, string $structureKey): string { return ''; }
    public function dynamicSolidifyPublishedPage(int $themeId, string $scope, string $identityHash, string $layoutType, array $nodes, ?int $releaseId = null, string $layoutOption = 'default', string $area = 'frontend'): string
    {
        return $this->rematerializePublishedPageAt($themeId, $scope, $identityHash, '', $layoutType, $layoutOption, $area);
    }
    public function rematerializePublishedPageAt(int $themeId, string $scope, string $identityKey, string $structureOrRelease, string $pageType, string $layoutOption = 'default', string $area = 'frontend'): string
    {
        $identity = $this->resolveBakeIdentity($themeId, $scope, true, null, $area);
        return ThemeLayoutEntityOwnerLock::write($identity, function () use ($identity, $pageType, $layoutOption): string {
            $this->publish($identity, $this->candidateForIdentity($identity, $pageType, $layoutOption));
            $path = $this->paths()->pageLayoutPhtml($identity, $pageType, $layoutOption);
            return is_file($path) ? $path : '';
        });
    }

    private function existingPageTargets(ThemeVersionIdentity $identity): array
    {
        $root = $this->paths()->versionModeDir($identity) . 'pages';
        if (!is_dir($root)) { return []; }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'phtml' || str_starts_with($file->getFilename(), '.')) { continue; }
            $meta = ThemeLayoutSourceSnapshot::metadata((string)file_get_contents($file->getPathname()));
            if ($meta === null || empty($meta['layout_type'])) { continue; }
            try { if (ThemeVersionIdentity::fromArray($meta['identity'])->ownerHash() !== $identity->ownerHash()) { continue; } }
            catch (\Throwable) { continue; }
            $out[] = $meta;
        }
        return $out;
    }

    /** Package catalog expands wildcard injection targets without creating a sidecar index. */
    private function declaredPageTargets(ThemeVersionIdentity $identity, array $changes): array
    {
        $theme = (clone ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class))->clearData()->clearQuery()->load($identity->themeId);
        $catalog = \Weline\Theme\Helper\LayoutScanner::scanLayouts($theme, $identity->area);
        $targets = [];
        foreach ($catalog as $type => $options) {
            foreach ($options as $option) {
                $option = is_array($option) ? (string)($option['value'] ?? 'default') : (string)$option;
                $targets[$type . '|' . $option] = ['layout_type' => $type, 'layout_option' => $option];
            }
        }
        $merger = ObjectManager::getInstance(RequiredDefaultInjectionBakeMerger::class);
        $declarations = $merger->loadDeclarations();
        foreach ($changes as $change) {
            if (!is_array($change)) { continue; }
            $declarations[] = ['default_injections' => array_merge($change['before'] ?? [], $change['after'] ?? [])];
        }
        foreach ($declarations as $declaration) {
            $injections = (array)($declaration['default_injections'] ?? []);
            if ($injections !== [] && !array_is_list($injections)) { $injections = [$injections]; }
            foreach ($injections as $injection) {
                $type = (string)($injection['layout_type'] ?? '*');
                $option = (string)($injection['layout_option'] ?? 'default');
                if ($type !== '' && $type !== '*' && $option !== '*') {
                    $targets[$type . '|' . $option] = ['layout_type' => $type, 'layout_option' => $option];
                }
            }
        }
        return array_values($targets);
    }

    private function context(ThemeVersionIdentity $identity, string $type, string $option, string $target, ?int $targetId): ThemeEditorContext
    {
        // 固化任务已有准确 owner/V/R，不依赖 HTTP 的应用选择。
        // 结构资源使用 default 身份；语言差异继续由同一版本资源快照供应。
        $scope = ThemeContentScope::fromStoredOwner($identity->canonicalScope, $identity->storeMode, 'default');
        $application = new ThemeApplicationContext(
            provider: $scope->provider,
            scopeKey: $scope->scopeKey,
            storeMode: $identity->storeMode,
            area: $identity->area,
            themeId: $identity->themeId,
            versionOwnerScope: $identity->canonicalScope,
            versionOwnerStoreMode: $identity->storeMode,
            themeVersionId: $identity->themeVersionId,
            contentRevision: $identity->contentRevision,
            defaultLocale: 'default',
            purpose: 'asset',
        );
        // 不安装请求上下文；继承来源由 read(identity, context) 的历史引用决定。
        return new ThemeEditorContext(
            scope: $scope,
            area: $identity->area,
            themeId: $identity->themeId,
            layoutType: $type,
            layoutOption: $option,
            targetType: $target,
            targetId: $targetId ?? 0,
            application: $application,
        );
    }
    private function resolveBakeIdentity(int $themeId, string $scope, bool $published, ?int $versionId = null, string $area = 'frontend', string $storeMode = 'normal'): ThemeVersionIdentity
    {
        $version = $versionId > 0 ? (clone ObjectManager::getInstance(ThemeScopeVersion::class))->clearData()->clearQuery()->load($versionId)
            : ($published ? $this->scopeVersions->getPublished($themeId, $scope, $storeMode, $area)
                : $this->scopeVersions->getCurrent($themeId, $scope, $storeMode, $area));
        if (!$version || $version->getVersionId() < 1 || $version->getThemeId() !== $themeId || $version->getArea() !== $area || $version->getStoreMode() !== $storeMode) {
            throw new \RuntimeException('theme_layout_entity_version_missing_or_mismatched');
        }
        $identity = $version->toVersionIdentity();
        return $identity->withVersion($identity->themeVersionId, $published ? 'formal' : 'draft', max(1, $identity->contentRevision));
    }
    private function loadVersion(ThemeVersionIdentity $identity): ThemeScopeVersion
    {
        $version = (clone ObjectManager::getInstance(ThemeScopeVersion::class))->clearData()->clearQuery()->load($identity->themeVersionId);
        if ($version->getVersionId() < 1 || $version->toVersionIdentity()->ownerHash() !== $identity->ownerHash()) { throw new \RuntimeException('theme_layout_entity_version_owner_mismatch'); }
        return $version;
    }
    private function publish(ThemeVersionIdentity $identity, array $candidates): void { (new ThemeLayoutEntityBatchPublisher())->publish($identity, $candidates); }
    private function snapshotService(): ThemeVersionResourceSnapshotService { return ObjectManager::getInstance(ThemeVersionResourceSnapshotService::class); }
    private function paths(): ThemeLayoutEntityPaths { return ObjectManager::getInstance(ThemeLayoutEntityPaths::class); }
    /** Return verifiable output evidence without exposing a host filesystem path. */
    private function resourceArtifactReceipt(string $type, string $path): array
    {
        $digest = is_file($path) ? hash_file('sha256', $path) : false;
        if ($digest === false) { throw new \RuntimeException('theme_layout_entity_' . $type . '_bake_failed'); }
        $receipt = ['type' => $type, 'artifact_id' => $digest, 'exists' => true];
        $root = defined('BP') ? rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR : '';
        if ($root !== '' && str_starts_with($path, $root)) {
            $receipt['relative_path'] = substr($path, strlen($root));
        }
        return $receipt;
    }
    private function bustPresentationCaches(int $themeId, ?string $scope = null, string $storeMode = 'normal'): void
    {
        ObjectManager::getInstance(\Weline\Theme\Service\ThemeRuntimeCacheCleaner::class)->clearLayoutEntityCaches($themeId, $scope, $storeMode);
    }
}
