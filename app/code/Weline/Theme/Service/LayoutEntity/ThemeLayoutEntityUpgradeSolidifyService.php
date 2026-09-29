<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

/** Build and verify current PHTML before retiring any legacy derivative. */
final class ThemeLayoutEntityUpgradeSolidifyService
{
    private static bool $hasRun = false;
    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeLayoutEntityBakeCoordinator $bakeCoordinator,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
        private readonly Printing $printing,
    ) {}

    public function runOnce(string $invalidationReason): array
    {
        if (self::$hasRun) { return ['purged' => 0, 'solidified' => 0, 'purged_legacy' => 0]; }
        $result = $this->cutover(null, $invalidationReason);
        self::$hasRun = true;
        return $result;
    }
    public function solidifyAllThemes(?string $area = null): int { return $this->bakeCoordinator->rebakeAfterInjectionCollect(null, []); }
    public function solidifyTheme(int $themeId, ?string $area = null): int { return $themeId > 0 ? $this->bakeCoordinator->rebakeAfterInjectionCollect($themeId, []) : 0; }
    public function solidifyFromThemeCommand(?WelineTheme $theme): int
    {
        return $theme !== null && (int)$theme->getId() > 0 ? $this->solidifyTheme((int)$theme->getId()) : $this->solidifyAllThemes();
    }
    public function cutoverFromThemeCommand(?WelineTheme $theme = null): array { return $this->cutover($theme, 'theme_upgrade_layout_entities_cutover'); }

    private function cutover(?WelineTheme $theme, string $reason): array
    {
        $this->printing->note((string)__('正在从已记录的版本意图生成 PHTML…'));
        $solidified = $this->solidifyFromThemeCommand($theme);
        $report = $this->bakeCoordinator->getLastRebakeReport();
        if (!empty($report['unmapped'])) {
            $failures = array_map(static fn(array $item): string => 'V' . (int)($item['version_id'] ?? 0)
                . '/R' . (int)($item['content_revision'] ?? 0) . ':' . (string)($item['reason'] ?? 'unresolved'), $report['unmapped']);
            $this->printing->warning('theme_layout_upgrade_historical_sources_missing:' . implode(';', $failures));
        }
        // Named-theme commands cannot remove derivatives belonging to other themes.
        $purged = $this->removeSidecars($theme === null ? null : (int)$theme->getId());
        $legacy = $this->paths->purgeLegacyDerivedTrees($theme === null ? null : (int)$theme->getId());
        if ($theme === null) {
            $legacy += $this->paths->purgeLegacyVarEntities();
        }
        $result = $this->cacheCleaner->clearAllThemeRelatedCaches($theme?->getId(), trim($reason) ?: 'theme_layout_phtml_rebuilt');
        foreach ((array)($result['failures'] ?? []) as $step => $message) {
            if (!str_starts_with((string)$step, 'cdn_') && !str_contains((string)$message, 'cdn_')) {
                throw new \RuntimeException('layout_entities_cache_clear_partial_failure:' . $step . '=' . $message);
            }
        }
        $this->printing->success((string)__('可解析版本的主题布局 PHTML 已生成。'));
        return ['purged' => $purged, 'solidified' => $solidified, 'purged_legacy' => $legacy, 'unmapped' => $report['unmapped'] ?? []];
    }
    private function removeSidecars(?int $themeId): int
    {
        $root = rtrim($this->paths->root(), '/\\') . ($themeId !== null ? '/' . $themeId : '');
        if (!is_dir($root)) { return 0; }
        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getExtension() === 'phtml') { continue; }
            if (!unlink($file->getPathname())) { throw new \RuntimeException('theme_layout_legacy_cleanup_failed'); }
            $count++;
        }
        return $count;
    }
    public static function resetHasRunFlag(): void { self::$hasRun = false; }
}
