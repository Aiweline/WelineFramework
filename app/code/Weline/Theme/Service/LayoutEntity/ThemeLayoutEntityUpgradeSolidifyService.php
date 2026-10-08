<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeApplicationUsageService;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

/**
 * System/theme upgrade: solidify + publish (Taglib com_*) for currently effective
 * versions of website-bound themes only — not every installed design/e2e theme.
 */
final class ThemeLayoutEntityUpgradeSolidifyService
{
    private static bool $hasRun = false;

    /** @var array{migrated:int,skipped:int,unmapped:list<array<string,mixed>>,chrome_bootstrapped:int} */
    private array $lastSolidifyReport = [
        'migrated' => 0,
        'skipped' => 0,
        'unmapped' => [],
        'chrome_bootstrapped' => 0,
    ];

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeLayoutEntityBakeCoordinator $bakeCoordinator,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
        private readonly Printing $printing,
        private readonly ThemeApplicationUsageService $themeApplicationUsage,
    ) {
    }

    public function runOnce(string $invalidationReason): array
    {
        if (self::$hasRun) {
            return ['purged' => 0, 'solidified' => 0, 'purged_legacy' => 0];
        }
        $result = $this->cutover(null, $invalidationReason);
        self::$hasRun = true;
        return $result;
    }

    /**
     * setup:upgrade / theme:upgrade（无 -t）：仅站点 frontend 绑定主题
     * + 后台应用主题 + 注册 Default；每主题只固当前生效正式版。
     */
    public function solidifyAllThemes(?string $area = null): int
    {
        $themes = $this->themeApplicationUsage->themesForDefaultUpgrade();
        if ($themes === []) {
            $this->printing->warning((string)__('无站点绑定主题可预固化（websites_theme_application 为空）。'));
            $this->lastSolidifyReport = [
                'migrated' => 0,
                'skipped' => 0,
                'unmapped' => [],
                'chrome_bootstrapped' => 0,
            ];
            return 0;
        }

        $aggregate = [
            'migrated' => 0,
            'skipped' => 0,
            'unmapped' => [],
            'chrome_bootstrapped' => 0,
        ];
        $this->printing->note(sprintf(
            '%s bound_themes=%d',
            (string)__('主题布局预固化仅处理站点已绑定主题'),
            count($themes),
        ));
        foreach ($themes as $theme) {
            $themeId = (int)$theme->getId();
            if ($themeId < 1) {
                continue;
            }
            $this->bakeCoordinator->rebakeAfterInjectionCollect(
                $themeId,
                [],
                $this->progress(...),
                false,
            );
            $report = $this->bakeCoordinator->getLastRebakeReport();
            $aggregate['migrated'] += (int)($report['migrated'] ?? 0);
            $aggregate['skipped'] += (int)($report['skipped'] ?? 0);
            $aggregate['chrome_bootstrapped'] += (int)($report['chrome_bootstrapped'] ?? 0);
            foreach ((array)($report['unmapped'] ?? []) as $item) {
                if (is_array($item)) {
                    $aggregate['unmapped'][] = $item;
                }
            }
        }
        $this->lastSolidifyReport = $aggregate;

        return $aggregate['migrated'];
    }

    public function solidifyTheme(int $themeId, ?string $area = null): int
    {
        if ($themeId <= 0) {
            $this->lastSolidifyReport = [
                'migrated' => 0,
                'skipped' => 0,
                'unmapped' => [],
                'chrome_bootstrapped' => 0,
            ];
            return 0;
        }
        $migrated = $this->bakeCoordinator->rebakeAfterInjectionCollect(
            $themeId,
            [],
            $this->progress(...),
            false,
        );
        $this->lastSolidifyReport = $this->bakeCoordinator->getLastRebakeReport();

        return (int)($this->lastSolidifyReport['migrated'] ?? $migrated);
    }

    private function progress(int $index, int $total, object $identity): void
    {
        $this->printing->note(sprintf(
            '%s [%d/%d] theme=%d V%d R%d %s',
            (string)__('主题布局预固化'),
            $index,
            $total,
            (int)($identity->themeId ?? 0),
            (int)($identity->themeVersionId ?? 0),
            (int)($identity->contentRevision ?? 0),
            (string)($identity->canonicalScope ?? ''),
        ));
    }

    public function solidifyFromThemeCommand(?WelineTheme $theme): int
    {
        return $theme !== null && (int)$theme->getId() > 0
            ? $this->solidifyTheme((int)$theme->getId())
            : $this->solidifyAllThemes();
    }

    public function cutoverFromThemeCommand(?WelineTheme $theme = null): array
    {
        return $this->cutover($theme, 'theme_upgrade_layout_entities_cutover');
    }

    private function cutover(?WelineTheme $theme, string $reason): array
    {
        $this->printing->note((string)__('正在从已记录的版本意图生成 PHTML…'));
        $solidified = $this->solidifyFromThemeCommand($theme);
        $report = $this->lastSolidifyReport;
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
        $this->printing->success(sprintf(
            '%s migrated=%d skipped=%d',
            (string)__('可解析版本的主题布局 PHTML 已生成。'),
            (int)($report['migrated'] ?? $solidified),
            (int)($report['skipped'] ?? 0),
        ));
        return [
            'purged' => $purged,
            'solidified' => $solidified,
            'purged_legacy' => $legacy,
            'unmapped' => $report['unmapped'] ?? [],
        ];
    }

    private function removeSidecars(?int $themeId): int
    {
        $root = rtrim($this->paths->root(), '/\\') . ($themeId !== null ? '/' . $themeId : '');
        if (!is_dir($root)) {
            return 0;
        }
        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getExtension() === 'phtml') {
                continue;
            }
            if (!unlink($file->getPathname())) {
                throw new \RuntimeException('theme_layout_legacy_cleanup_failed');
            }
            $count++;
        }
        return $count;
    }

    public static function resetHasRunFlag(): void
    {
        self::$hasRun = false;
    }
}
