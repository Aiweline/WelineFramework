<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\SlotBoundaryMarkers;

/**
 * Bake chrome/page entity phtml + config sidecars. Structure changes only.
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
     * Materialize chrome.phtml + chrome-config.json for a theme scope version.
     *
     * @return string Absolute chrome.phtml path
     */
    public function materializeChrome(ThemeScopeVersion $version): string
    {
        $themeId = $version->getThemeId();
        $scope = $version->getScope();
        $versionId = $version->getVersionId();
        if ($themeId < 1 || $scope === '' || $versionId < 1) {
            throw new \InvalidArgumentException('Invalid ThemeScopeVersion for chrome materialize.');
        }

        $nodes = $this->slotTree->filterChromeNodes($version->getChromePayload());
        $layout = $this->slotTree->nodesToAreaLayout($nodes);
        $bySlot = $this->slotTree->organizeWidgetsBySlot($layout);
        $configByUid = $this->extractConfigByUid($nodes);

        $document = $this->structureDocument($bySlot);
        $structureKey = 's' . hash('sha256', json_encode($document, JSON_THROW_ON_ERROR));
        $dir = $this->paths->chromeStructureDir($themeId, $scope, $structureKey);
        $path = $dir . 'chrome.phtml';
        if (!is_file($path)) {
            $this->writePhtml($path, $this->buildSlotPhtml($bySlot, 'chrome'));
            $this->opcacheCompile($path);
        }
        $this->writeStructureJson($dir . 'structure.json', $bySlot);
        $collector = ObjectManager::getInstance(ThemeLayoutEntityAssetCollector::class);
        $this->bindingStore()->publishChromeBinding($themeId, $scope, $versionId, $structureKey,
            $configByUid, $collector->collectFromNodes($collector->withChromeRegistryBaseline($nodes), true));
        // 快照以配置/结构摘要隔离；保留旧快照供正在运行的请求使用。
        return $path;
    }

    /**
     * Config-only writes must not rewrite chrome.phtml. Drop locale snapshots so the
     * next hit re-renders widgets from the existing solidified template + new sidecar.
     */
    public function bustChromeRenderedSnapshots(ThemeScopeVersion $version): void
    {
        $themeId = $version->getThemeId();
        $scope = $version->getScope();
        $versionId = $version->getVersionId();
        if ($themeId < 1 || $scope === '' || $versionId < 1) {
            return;
        }
        foreach ($this->paths->chromeRenderedHtmlSnapshots($themeId, $scope, $versionId) as $rendered) {
            @\unlink($rendered);
        }
    }

    /**
     * Materialize page layout.phtml + page-config.json for content nodes only.
     *
     * @param array<string|int, mixed> $contentNodes
     * @param array<string, mixed> $pageConfigByUid
     * @return string Absolute layout.phtml path
     */
    public function materializePage(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureKey,
        array $contentNodes,
        array $pageConfigByUid,
        bool $published,
        ?int $releaseId,
        string $pageType = '',
        int $draftRevisionId = 0,
    ): string {
        if ($themeId < 1 || \trim($scope) === '' || \trim($identityKey) === '') {
            throw new \InvalidArgumentException('Invalid page materialize identity.');
        }

        $nodes = $this->slotTree->filterContentNodes($contentNodes);
        $layout = $this->slotTree->nodesToAreaLayout($nodes);
        // Bake-time only: former request-path normalizers (product layout + footer container).
        $layout = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Theme\Service\ProductPageLayoutNormalizer::class,
        )->normalizeLayoutForRender($pageType, $layout);
        $layout = \Weline\Theme\Helper\FooterDefaultLinksHelper::ensureFooterContainerInLayout($layout);
        $bySlot = $this->slotTree->organizeWidgetsBySlot($layout);
        $configByUid = $pageConfigByUid !== []
            ? $pageConfigByUid
            : $this->extractConfigByUid($nodes);

        // 输入 structureKey 是上游结构/源布局指纹，最终槽树必须同时参与摘要。
        $structureKey = 's' . hash('sha256', json_encode([
            'source' => $structureKey,
            'structure' => $this->structureDocument($bySlot, $pageType),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $entityKey = $published && $releaseId !== null && $releaseId > 0
            ? 'r' . $releaseId : 'd' . $draftRevisionId;
        $path = $this->paths->pagePhtml($themeId, $scope, $identityKey, $structureKey);
        if (!is_file($path)) {
            $phtml = $this->buildSlotPhtml($bySlot, 'page');
            $this->writePhtml($path, $phtml);
            $this->opcacheCompile($path);
        }
        $this->writeStructureJson(
            $this->paths->pageStructureJson($themeId, $scope, $identityKey, $structureKey), $bySlot, $pageType);
        $shellPath = $this->paths->shellPhtml($themeId, $scope, $identityKey, $structureKey);
        if (!is_file($shellPath)) {
            $phtml = $phtml ?? (string)file_get_contents($path);
            // 整壳仍保留公共壳渲染，模板只绑定本次请求身份，不固化发布/配置版本。
            $pageBody = preg_replace('/^\s*<\?php\s+declare\(strict_types=1\);\s*\/\*\*.*?\*\/\s*\?>\s*/s', '', $phtml) ?? $phtml;
            $shell = "<?php\ndeclare(strict_types=1);\n"
                . "echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance("
                . "\\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityChrome::class)"
                . "->renderCurrent(\$entityBinding->themeId, \$entityBinding->scope);\n?>\n" . $pageBody;
            $this->writePhtml($shellPath, $shell);
        }
        $collector = ObjectManager::getInstance(ThemeLayoutEntityAssetCollector::class);
        $this->bindingStore()->publishPageBinding($themeId, $scope, $identityKey, $entityKey, $structureKey,
            $configByUid, $collector->collectFromNodes($nodes, true));
        return $path;
    }

    /**
     * Sidecar listing widgets per slot for storefront SlotFiller (no DB).
     *
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

        return ['page_type' => trim($pageType), 'slots' => $slots];
    }

    private function writeStructureJson(string $path, array $bySlot, string $pageType = ''): void
    {
        if (is_file($path)) {
            return;
        }
        $json = json_encode($this->structureDocument($bySlot, $pageType),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->writePhtml($path, $json . "\n");
    }

    /**
     * @param array<string, list<array<string, mixed>>> $bySlot
     */
    private function buildSlotPhtml(
        array $bySlot,
        string $configSource,
        int $themeId = 0,
        string $scopeKey = '',
        string $versionKey = '',
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
        if (!is_file($path)) {
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
