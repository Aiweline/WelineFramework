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
    public function __construct(
        private readonly ThemeScopeVersionService $scopeVersions,
        private readonly ThemeLayoutEntityMaterializer $materializer,
        private readonly ThemeLayoutEntityConfigStore $configStore,
        private readonly ThemeLayoutEntityPointerResolver $pointers,
        private readonly ThemeLayoutSlotTreeBuilder $slotTree,
        private readonly SharedChromeService $sharedChrome,
    ) {
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
    ): void {
        if ($themeId < 1 || $scope === '') {
            throw new \InvalidArgumentException('theme_layout_entity_bake_identity_invalid');
        }

        if ($this->sharedChrome->isChromeCarrierPageType($layoutType)) {
            $this->syncCarrierChromePayloadIfStale($themeId, $scope, $nodes, $published);
        }

        // 发布必须整页物化到 r{releaseId}：publish 入口常不带 ADD_NODE 变更列表（节点已在草稿），
        // 若仍走 config-only，店面硬切找不到 r{id} 会 fail-closed（禁止回退 s*）。
        $structural = $published || $this->commandsAreStructural($commands);
        $chromeTouched = $this->commandsTouchChrome($commands, $nodes);

        if ($chromeTouched) {
            $this->bakeChromeFromNodes($themeId, $scope, $nodes, $structural);
        }

        if ($structural && !$chromeTouched) {
            $this->bakePageFromNodes(
                $themeId,
                $scope,
                $identityHash,
                $layoutType,
                $nodes,
                $published,
                $releaseId,
                $draftRevisionId,
            );
        } elseif ($structural && $chromeTouched) {
            // Chrome structural bake already done; still bake page content without chrome nodes.
            $this->bakePageFromNodes(
                $themeId,
                $scope,
                $identityHash,
                $layoutType,
                $nodes,
                $published,
                $releaseId,
                $draftRevisionId,
            );
        } elseif (!$structural) {
            $this->updateConfigSidecarsOnly($themeId, $scope, $identityHash, $nodes, $published, $releaseId, $draftRevisionId);
        }

        // wave8-8s5: chrome structural bake must refresh published whole-shells under scope.
        if ($published && $chromeTouched && $structural) {
            $this->refreshPublishedWholeShellsForScope($themeId, $scope);
        }

        $this->bustPresentationCaches($themeId);
    }

    /**
     * @param array<string|int, mixed> $nodes
     */
    public function bakeChromeFromNodes(int $themeId, string $scope, array $nodes, bool $structural = true): string
    {
        // Config edits must not rewrite chrome.phtml. Sidecar + snapshot bust live in updateConfigSidecarsOnly.
        if (!$structural) {
            $version = $this->scopeVersions->ensureCurrent($themeId, $scope);
            $this->materializer->bustChromeRenderedSnapshots($version);

            return '';
        }

        $version = $this->scopeVersions->ensureCurrent($themeId, $scope);
        $chromeNodes = $this->slotTree->filterChromeNodes($nodes);
        // 布局固化与默认注入: homepage carrier required JSON → chrome structure bake.
        $chromeNodes = $this->mergeRequiredDefaultsIntoNodes($chromeNodes, $themeId, 'homepage');
        $chromeNodes = $this->slotTree->filterChromeNodes($chromeNodes);
        $this->scopeVersions->setChromePayload($version, $chromeNodes);
        $version = $this->scopeVersions->getCurrent($themeId, $scope) ?? $version;

        $path = $this->materializer->materializeChrome($version);
        if (!\is_file($path)) {
            throw new \RuntimeException('theme_layout_entity_chrome_bake_failed');
        }
        $this->pointers->rememberChromePointer($themeId, $scope, $version->getVersionId(), $path, false);
        if ($version->isPublished()) {
            $this->pointers->rememberChromePointer($themeId, $scope, $version->getVersionId(), $path, true);
        }
        $this->pointers->invalidateChrome($themeId, $scope);
        $this->bustPresentationCaches($themeId);

        return $path;
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
    ): string {
        $contentNodes = $this->slotTree->filterContentNodes($nodes);
        // 布局固化与默认注入: required JSON default_injections bake into layout.phtml nodes.
        $contentNodes = $this->mergeRequiredDefaultsIntoNodes($contentNodes, $themeId, $layoutType);
        $contentNodes = $this->slotTree->filterContentNodes($contentNodes);
        $structureKey = $this->structureKeyForNodes($contentNodes, $draftRevisionId, $releaseId);
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
        );
        if (!\is_file($path)) {
            throw new \RuntimeException('theme_layout_entity_page_bake_failed');
        }
        $this->pointers->rememberPagePointer(
            $themeId,
            $scope,
            $identityHash !== '' ? $identityHash : $identityKey,
            $structureKey,
            $path,
            $published,
            $releaseId,
        );
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        $this->writePageCurrentPointer(
            $paths,
            $themeId,
            $scope,
            $identityKey,
            $paths->pageStructureOrRelease($structureKey, $published, $releaseId),
            $published,
        );

        // wave8-8s5: published bake writes whole-shell (chrome + page) for storefront include.
        if ($published) {
            $this->writePublishedWholeShell(
                $themeId,
                $scope,
                $identityKey,
                $paths->pageStructureOrRelease($structureKey, true, $releaseId),
            );
        }

        return $path;
    }

    /**
     * Plugin / injection-collect: rematerialize involved layouts under all themes
     * (merge required JSON into structure), finalize chrome.rendered, then refresh shells
     * that echo ThemeLayoutEntityChrome::renderCurrent (never raw chrome.phtml injectors).
     *
     * @see app/code/Weline/Theme/doc/布局固化与默认注入.md §3.3
     */
    public function rebakeAfterInjectionCollect(?int $themeId = null): int
    {
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        $root = $paths->root();
        if (!\is_dir($root)) {
            return 0;
        }

        $written = 0;
        $themeDirs = $themeId !== null && $themeId > 0
            ? [$root . $themeId]
            : (\glob($root . '*', \GLOB_ONLYDIR) ?: []);
        foreach ($themeDirs as $themeDir) {
            if (!\is_string($themeDir) || !\is_dir($themeDir)) {
                continue;
            }
            $tid = (int)\basename($themeDir);
            if ($tid < 1) {
                continue;
            }
            foreach (\glob($themeDir . \DIRECTORY_SEPARATOR . '*', \GLOB_ONLYDIR) ?: [] as $scopeDir) {
                if (!\is_string($scopeDir) || !\is_dir($scopeDir)) {
                    continue;
                }
                $written += $this->rematerializePublishedPagesUnderScopeDir($tid, $scopeDir);
                $written += $this->rematerializeChromeVersionsUnderScopeDir($tid, $scopeDir);
                // Architecture: finalize chrome.rendered (nested footer-*-links promote)
                // BEFORE refreshing shell.phtml. Shell embeds renderCurrent(), which reads
                // those snapshots — never raw chrome.phtml injectors (布局固化与默认注入.md).
                $written += $this->dropChromeRenderedSnapshotsUnderScopeDir($scopeDir);
                $written += $this->resolidifyChromeRenderedUnderScopeDir($scopeDir);
                $written += $this->refreshPublishedWholeShellsUnderScopeDir($tid, $scopeDir);
            }
        }

        if ($written > 0 && ($themeId === null || $themeId < 1)) {
            $this->bustPresentationCaches(0);
        } elseif ($written > 0 && $themeId !== null && $themeId > 0) {
            $this->bustPresentationCaches($themeId);
        }

        return $written;
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
        $structureKey = $releaseId !== null
            ? ('release-' . $releaseId)
            : $this->structureKeyForNodes($nodes, 0, null);
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
    private function mergeRequiredDefaultsIntoNodes(array $nodes, int $themeId, string $pageType): array
    {
        /** @var RequiredDefaultInjectionBakeMerger $merger */
        $merger = ObjectManager::getInstance(RequiredDefaultInjectionBakeMerger::class);

        return $merger->mergeIntoNodes($nodes, $themeId, $pageType);
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
        if (@\file_put_contents($shellPath, $body) === false) {
            throw new \RuntimeException('theme_layout_entity_shell_write_failed: ' . $shellPath);
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
    ): void {
        $chromeNodes = $this->slotTree->filterChromeNodes($nodes);
        $contentNodes = $this->slotTree->filterContentNodes($nodes);

        if ($chromeNodes !== []) {
            $version = $this->scopeVersions->ensureCurrent($themeId, $scope);
            // Config-only writes may pass a partial node set; merge into payload instead of truncating.
            $merged = $this->mergeChromePayloadNodes($version->getChromePayload(), $chromeNodes);
            $this->scopeVersions->setChromePayload($version, $merged);
            $version = $this->scopeVersions->getCurrent($themeId, $scope) ?? $version;
            // Config-only: patch chrome-config sidecar entries without rebaking phtml structure.
            $config = $this->configStore->readChromeConfig($themeId, $scope, $version->getVersionId());
            foreach ($chromeNodes as $node) {
                if (!\is_array($node)) {
                    continue;
                }
                $uid = \strtolower(\trim((string)($node['node_uid'] ?? '')));
                if ($uid !== '') {
                    $config[$uid] = \is_array($node['config'] ?? null) ? $node['config'] : [];
                }
            }
            $this->configStore->writeChromeConfig($themeId, $scope, $version->getVersionId(), $config);
            $collector = ObjectManager::getInstance(ThemeLayoutEntityAssetCollector::class);
            $this->configStore->writeChromeAssets(
                $themeId,
                $scope,
                $version->getVersionId(),
                $collector->collectFromNodes($collector->withChromeRegistryBaseline($merged), true),
            );
        }

        if ($contentNodes !== []) {
            $structureKey = $this->structureKeyForNodes($contentNodes, $draftRevisionId, $releaseId);
            $identityKey = $this->pathsIdentityKey($identityHash, '');
            $structureOrRelease = ObjectManager::getInstance(ThemeLayoutEntityPaths::class)
                ->pageStructureOrRelease($structureKey, $published, $releaseId);
            $config = [];
            foreach ($contentNodes as $node) {
                if (!\is_array($node)) {
                    continue;
                }
                $uid = \strtolower(\trim((string)($node['node_uid'] ?? '')));
                if ($uid !== '') {
                    $config[$uid] = $node;
                }
            }
            $this->configStore->writePageConfig($themeId, $scope, $identityKey, $structureOrRelease, $config);
            $collector = ObjectManager::getInstance(ThemeLayoutEntityAssetCollector::class);
            $this->configStore->writePageAssets(
                $themeId,
                $scope,
                $identityKey,
                $structureOrRelease,
                $collector->collectFromNodes($contentNodes, true),
            );
        }
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
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $area = (string)($node['area'] ?? '');
            $slot = isset($node['slot_id']) ? (string)$node['slot_id'] : null;
            if ($this->sharedChrome->isChromeTarget($area, $slot)) {
                return true;
            }
        }
        foreach ($commands as $command) {
            $path = '';
            if (\is_object($command) && \method_exists($command, 'toArray')) {
                $path = (string)(($command->toArray()['path'] ?? ''));
            } elseif (\is_array($command)) {
                $path = (string)($command['path'] ?? '');
            }
            if (\preg_match('#header|footer#i', $path) === 1) {
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
        $tmp = $file . '.tmp';
        if (@\file_put_contents($tmp, $json . "\n") === false) {
            return;
        }
        @\rename($tmp, $file);
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

    private function bustPresentationCaches(int $themeId): void
    {
        try {
            /** @var ThemeRuntimeCacheCleaner $cleaner */
            $cleaner = ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class);
            if (\method_exists($cleaner, 'clearNonGlobalCaches')) {
                $cleaner->clearNonGlobalCaches($themeId > 0 ? $themeId : null, 'theme_layout_entity_bake');
            } elseif (\method_exists($cleaner, 'clearAllThemeRelatedCaches')) {
                $cleaner->clearAllThemeRelatedCaches($themeId);
            }
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
