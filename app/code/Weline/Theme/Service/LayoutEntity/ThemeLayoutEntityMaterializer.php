<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

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

        $scopeKey = $this->paths->scopeKey($scope);
        $versionKey = (string)$versionId;
        $phtml = $this->buildSlotPhtml($bySlot, 'chrome', $themeId, $scopeKey, $versionKey);

        $path = $this->paths->chromePhtml($themeId, $scope, $versionId);
        $this->writePhtml($path, $phtml);
        $this->configStore->writeChromeConfig($themeId, $scope, $versionId, $configByUid);
        // Drop stale request-time snapshots (legacy bare + every locale variant)
        // so the next storefront hit re-renders under the request language.
        foreach ($this->paths->chromeRenderedHtmlSnapshots($themeId, $scope, $versionId) as $rendered) {
            @\unlink($rendered);
        }
        $this->opcacheCompile($path);

        return $path;
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

        $structureOrRelease = $this->paths->pageStructureOrRelease($structureKey, $published, $releaseId);
        $scopeKey = $this->paths->scopeKey($scope);
        $versionKey = $identityKey . '/' . $structureOrRelease;
        $phtml = $this->buildSlotPhtml($bySlot, 'page', $themeId, $scopeKey, $versionKey);

        $path = $this->paths->pagePhtml($themeId, $scope, $identityKey, $structureOrRelease);
        $this->writePhtml($path, $phtml);
        $this->configStore->writePageConfig($themeId, $scope, $identityKey, $structureOrRelease, $configByUid);
        $this->writeStructureJson(
            $this->paths->pageStructureJson($themeId, $scope, $identityKey, $structureOrRelease),
            $bySlot,
        );
        $this->opcacheCompile($path);

        return $path;
    }

    /**
     * Sidecar listing widgets per slot for storefront SlotFiller (no DB).
     *
     * @param array<string, list<array<string, mixed>>> $bySlot
     */
    private function writeStructureJson(string $path, array $bySlot): void
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
                    'sort_order' => (int)($widget['sort_order'] ?? 0),
                    'is_active' => (bool)($widget['is_active'] ?? true),
                    'area' => (string)($widget['area'] ?? ''),
                    'slot_id' => (string)($widget['slot_id'] ?? $slotId),
                ];
            }
            $slots[(string)$slotId] = $list;
        }

        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Failed to create layout entity structure dir: ' . $dir);
        }
        $json = \json_encode(
            ['slots' => $slots],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        );
        if ($json === false || @\file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException('Failed to write layout entity structure.json: ' . $path);
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $bySlot
     */
    private function buildSlotPhtml(
        array $bySlot,
        string $configSource,
        int $themeId,
        string $scopeKey,
        string $versionKey,
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
                    . '\\' . $rendererClass . '::class)->render('
                    . \var_export($uid, true) . ', '
                    . \var_export($configSource, true) . ', '
                    . (int)$themeId . ', '
                    . \var_export($scopeKey, true) . ', '
                    . \var_export($versionKey, true)
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
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Failed to create layout entity dir: ' . $dir);
        }
        if (@\file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Failed to write layout entity phtml: ' . $path);
        }
    }

    private function opcacheCompile(string $path): void
    {
        if (\function_exists('opcache_compile_file') && \is_file($path)) {
            @\opcache_compile_file($path);
        }
    }
}
