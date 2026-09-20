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

        return $path;
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
            'draft_revision_id' => $draftRevisionId,
            'release_id' => $releaseId,
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
