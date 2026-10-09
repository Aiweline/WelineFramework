<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeApplicationUsageService;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;


/**
 * System/theme upgrade: solidify + publish (Taglib com_*) for website-bound themes.
 * Default versions: currently published + selected draft. --all expands to every version row.
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

    /**
     * @param array{scope?:string,all_versions?:bool} $options
     */
    public function runOnce(string $invalidationReason, array $options = []): array
    {
        if (self::$hasRun) {
            return ['purged' => 0, 'solidified' => 0, 'purged_legacy' => 0];
        }
        $result = $this->cutover(null, $invalidationReason, $options);
        self::$hasRun = true;
        return $result;
    }

    /**
     * setup:upgrade / theme:upgrade（无 -t）：仅站点 frontend 绑定主题
     * + 后台应用主题 + 注册 Default；默认每范围正式版+草稿。
     *
     * @param array{scope?:string,all_versions?:bool} $options
     */
    public function solidifyAllThemes(?string $area = null, array $options = []): int
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

        $themeIds = [];
        foreach ($themes as $theme) {
            $themeId = (int)$theme->getId();
            if ($themeId >= 1) {
                $themeIds[] = $themeId;
            }
        }
        $this->printing->note(sprintf(
            '%s bound_themes=%d pipeline=global_flat%s%s',
            (string)__('主题布局预固化仅处理站点已绑定主题'),
            count($themeIds),
            !empty($options['all_versions']) ? ' versions=all' : ' versions=current+draft',
            ($options['scope'] ?? '') !== '' ? ' scope=' . (string)$options['scope'] : '',
        ));
        // One solidify/compile pipeline across all bound themes (not per-theme serial).
        // Different themeId ⇒ different ownerHash ⇒ pools fill across themes; same scope draft/formal still mutex.
        // Progress bar is owned by the pipeline (shows live s=/c=/themes=); do not pass a single-theme bar.
        $this->bakeCoordinator->rebakeThemesAfterInjectionCollect(
            $themeIds,
            [],
            null,
            true,
            $this->bakeOptions($options),
        );
        $this->lastSolidifyReport = $this->bakeCoordinator->getLastRebakeReport();

        return (int)($this->lastSolidifyReport['migrated'] ?? 0);
    }

    /**
     * @param array{scope?:string,all_versions?:bool} $options
     */
    public function solidifyTheme(int $themeId, ?string $area = null, array $options = []): int
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
            null,
            true,
            $this->bakeOptions($options),
        );
        $this->lastSolidifyReport = $this->bakeCoordinator->getLastRebakeReport();

        return (int)($this->lastSolidifyReport['migrated'] ?? $migrated);
    }

    /**
     * @param array{scope?:string,all_versions?:bool} $options
     */
    public function solidifyFromThemeCommand(?WelineTheme $theme, array $options = []): int
    {
        return $theme !== null && (int)$theme->getId() > 0
            ? $this->solidifyTheme((int)$theme->getId(), null, $options)
            : $this->solidifyAllThemes(null, $options);
    }

    /**
     * @param array{scope?:string,all_versions?:bool} $options
     */
    public function cutoverFromThemeCommand(?WelineTheme $theme = null, array $options = []): array
    {
        return $this->cutover($theme, 'theme_upgrade_layout_entities_cutover', $options);
    }

    /**
     * @param array{scope?:string,all_versions?:bool} $options
     */
    private function cutover(?WelineTheme $theme, string $reason, array $options = []): array
    {
        $this->printing->note((string)__('正在从已记录的版本意图生成 PHTML…'));
        try {
            $solidified = $this->solidifyFromThemeCommand($theme, $options);
        } catch (\Throwable $error) {
            $this->printing->finishProgressLine();
            throw $error;
        }
        $this->printing->finishProgressLine();
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

    /**
     * @param array{scope?:string,all_versions?:bool} $options
     * @return array{scope?:string,all_versions?:bool}
     */
    private function bakeOptions(array $options): array
    {
        $out = [];
        $scope = trim((string)($options['scope'] ?? ''));
        if ($scope !== '') {
            $out['scope'] = $scope;
        }
        if (!empty($options['all_versions'])) {
            $out['all_versions'] = true;
        }

        return $out;
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
