<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\SlotBoundaryMarkers;

/**
 * Bake chrome/page entity phtml + config sidecars into a typed ThemeVersionIdentity tree.
 * Structure writes only create missing PHTML; config-only changes go through BindingStore.
 */
final class ThemeLayoutEntityMaterializer
{
    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeLayoutSlotTreeBuilder $slotTree,
        private readonly ThemeLayoutEntityConfigStore $configStore,
    ) {
    }

    /**
     * Materialize chrome.phtml + binding for a theme scope version.
     *
     * @return string Absolute chrome.phtml path
     */
    public function materializeChrome(ThemeScopeVersion $version): string
    {
        $identity = $version->toVersionIdentity();
        if ($identity->themeVersionId < 1) {
            throw new \InvalidArgumentException('Invalid ThemeScopeVersion for chrome materialize.');
        }

        $nodes = $this->slotTree->filterChromeNodes($version->getChromePayload());
        $layout = $this->slotTree->nodesToAreaLayout($nodes);
        $bySlot = $this->slotTree->organizeWidgetsBySlot($layout);
        $configByUid = $this->extractConfigByUid($nodes);

        $document = $this->structureDocument($bySlot);
        $structureKey = \hash('sha256', \json_encode($document, \JSON_THROW_ON_ERROR));
        $path = $this->paths->chromePhtml($identity, $structureKey);
        if (!\is_file($path)) {
            $this->writePhtml($path, $this->buildSlotPhtml($bySlot, 'chrome'));
            $this->opcacheCompile($path);
        }
        $this->writeStructureJson(
            $this->paths->chromeStructureDir($identity, $structureKey) . 'structure.json',
            $bySlot,
        );
        $collector = ObjectManager::getInstance(ThemeLayoutEntityAssetCollector::class);
        $this->bindingStore()->publishChromeBinding(
            $identity,
            $structureKey,
            $configByUid,
            $collector->collectFromNodes($collector->withChromeRegistryBaseline($nodes), true),
        );

        return $path;
    }

    /**
     * Config-only writes must not rewrite chrome.phtml. Drop locale snapshots so the
     * next hit re-renders widgets from the existing solidified template + new sidecar.
     */
    public function bustChromeRenderedSnapshots(ThemeScopeVersion $version): void
    {
        $identity = $version->toVersionIdentity();
        if ($identity->themeVersionId < 1) {
            return;
        }
        $renderedRoot = $this->paths->chromeRoot($identity) . 'rendered' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($renderedRoot)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($renderedRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
    }

    /**
     * Materialize page layout.phtml + shell + binding for content nodes only.
     *
     * @param array<string|int, mixed> $contentNodes
     * @param array<string, mixed> $pageConfigByUid
     * @return string Absolute layout.phtml path
     */
    public function materializePage(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $structureKey,
        array $contentNodes,
        array $pageConfigByUid,
        string $pageType = '',
    ): string {
        if ($identity->themeVersionId < 1 || \trim($layoutIdentityHash) === '') {
            throw new \InvalidArgumentException('Invalid page materialize identity.');
        }

        $nodes = $this->slotTree->filterContentNodes($contentNodes);
        $layout = $this->slotTree->nodesToAreaLayout($nodes);
        $layout = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Theme\Service\ProductPageLayoutNormalizer::class,
        )->normalizeLayoutForRender($pageType, $layout);
        $layout = \Weline\Theme\Helper\FooterDefaultLinksHelper::ensureFooterContainerInLayout($layout);
        $bySlot = $this->slotTree->organizeWidgetsBySlot($layout);
        $configByUid = $pageConfigByUid !== []
            ? $pageConfigByUid
            : $this->extractConfigByUid($nodes);

        // Source structureKey + final slot tree participate in the digest.
        $structureKey = \hash('sha256', \json_encode([
            'source' => $structureKey,
            'structure' => $this->structureDocument($bySlot, $pageType),
            'owner' => $identity->ownerKey(),
            'mode' => $identity->mode,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        $layoutIdentityHash = $this->paths->identityKey($layoutIdentityHash);
        $path = $this->paths->pagePhtml($identity, $layoutIdentityHash, $structureKey);
        if (!\is_file($path)) {
            $phtml = $this->buildSlotPhtml($bySlot, 'page');
            $this->writePhtml($path, $phtml);
            $this->opcacheCompile($path);
        }
        $this->writeStructureJson(
            $this->paths->pageStructureJson($identity, $layoutIdentityHash, $structureKey),
            $bySlot,
            $pageType,
        );
        $shellPath = $this->paths->shellPhtml($identity, $layoutIdentityHash, $structureKey);
        if (!\is_file($shellPath)) {
            $phtml = $phtml ?? (string)\file_get_contents($path);
            $pageBody = \preg_replace('/^\s*<\?php\s+declare\(strict_types=1\);\s*\/\*\*.*?\*\/\s*\?>\s*/s', '', $phtml) ?? $phtml;
            $shell = "<?php\ndeclare(strict_types=1);\n"
                . "echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance("
                . "\\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityChrome::class)"
                . "->renderCurrent(\$entityBinding->identity->themeId, \$entityBinding->identity->canonicalScope,"
                . " \$entityBinding->identity->themeVersionId);\n?>\n" . $pageBody;
            $this->writePhtml($shellPath, $shell);
        }
        $collector = ObjectManager::getInstance(ThemeLayoutEntityAssetCollector::class);
        $this->bindingStore()->publishPageBinding(
            $identity,
            $layoutIdentityHash,
            $structureKey,
            $configByUid,
            $collector->collectFromNodes($nodes, true),
        );

        return $path;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $bySlot
     */
    private function structureDocument(array $bySlot, string $pageType = ''): array
    {
        $slots = [];
        \ksort($bySlot);
        foreach ($bySlot as $slotId => $widgets) {
            $list = [];
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                if (\array_key_exists('is_active', $widget) && empty($widget['is_active'])) {
                    continue;
                }
                $list[] = [
                    'node_uid' => \strtolower(\trim((string)($widget['node_uid'] ?? ''))),
                    'widget_module' => (string)($widget['widget_module'] ?? ''),
                    'widget_code' => (string)($widget['widget_code'] ?? ''),
                    'widget_type' => (string)($widget['widget_type'] ?? ''),
                    'layout_source' => (string)($widget['layout_source'] ?? $widget['config']['_layout_source'] ?? ''),
                    'source' => (string)($widget['source'] ?? $widget['config']['_source'] ?? ''),
                    'source_position' => (string)($widget['source_position'] ?? $widget['config']['_source_position'] ?? 'head'),
                    'sort_order' => (int)($widget['sort_order'] ?? 0),
                    'is_active' => (bool)($widget['is_active'] ?? true),
                    'area' => (string)($widget['area'] ?? ''),
                    'slot_id' => (string)($widget['slot_id'] ?? $slotId),
                ];
            }
            $slots[(string)$slotId] = $list;
        }

        return ['page_type' => \trim($pageType), 'slots' => $slots];
    }

    private function writeStructureJson(string $path, array $bySlot, string $pageType = ''): void
    {
        if (\is_file($path)) {
            return;
        }
        $json = \json_encode(
            $this->structureDocument($bySlot, $pageType),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );
        $this->writePhtml($path, $json . "\n");
    }

    /**
     * @param array<string, list<array<string, mixed>>> $bySlot
     */
    private function buildSlotPhtml(
        array $bySlot,
        string $configSource,
    ): string {
        $rendererClass = ThemeLayoutEntityWidgetRenderer::class;
        $lines = [];
        $lines[] = '<?php';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '/** Auto-generated theme layout entity — do not edit. */';
        $lines[] = '?>';

        \ksort($bySlot);
        foreach ($bySlot as $slotId => $widgets) {
            $slotId = (string)$slotId;
            try {
                $open = SlotBoundaryMarkers::open($slotId);
                $close = SlotBoundaryMarkers::close($slotId);
            } catch (\InvalidArgumentException) {
                $safe = \preg_replace('/[^\\w.-]+/', '-', $slotId) ?: 'slot';
                $open = SlotBoundaryMarkers::open($safe);
                $close = SlotBoundaryMarkers::close($safe);
            }

            $lines[] = $open;
            $lines[] = '<div class="theme-layout-entity-slot" data-slot-id="'
                . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">';

            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                if (\array_key_exists('is_active', $widget) && empty($widget['is_active'])) {
                    continue;
                }
                $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                if ($uid === '' || \preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
                    continue;
                }
                $lines[] = '<?= \\Weline\\Framework\\Manager\\ObjectManager::getInstance('
                    . '\\' . $rendererClass . '::class)->renderBound('
                    . \var_export($uid, true) . ', '
                    . \var_export($configSource, true) . ', '
                    . '$entityBinding'
                    . ') ?>';
            }

            $lines[] = '</div>';
            $lines[] = $close;
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function extractConfigByUid(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $key = \strtolower(\trim((string)($node['node_uid'] ?? $uid)));
            if ($key === '') {
                continue;
            }
            $out[$key] = $node;
        }

        return $out;
    }

    private function writePhtml(string $path, string $contents): void
    {
        if (!\is_file($path)) {
            (new AtomicCompiledFilePublisher())->publish($path, $contents);
        }
    }

    private function bindingStore(): ThemeLayoutEntityBindingStore
    {
        return new ThemeLayoutEntityBindingStore($this->paths);
    }

    private function opcacheCompile(string $path): void
    {
        if (\function_exists('opcache_compile_file') && \is_file($path)) {
            @\opcache_compile_file($path);
        }
    }
}
