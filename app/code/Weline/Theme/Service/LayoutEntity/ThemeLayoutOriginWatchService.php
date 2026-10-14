<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\Env;
use Weline\Theme\Console\Theme\Upgrade as ThemeUpgradeCommand;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeApplicationUsageService;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * Dev-only: watch design-theme origin files and rebake bound scopes when hashes change.
 * Non-dev callers must not invoke {@see run()} (Cron gates with skip:non_dev).
 */
final class ThemeLayoutOriginWatchService
{
    private const MANIFEST_NAME = '.origin-watch.json';
    private const MANIFEST_VERSION = 1;

    public function __construct(
        private readonly ThemeApplicationUsageService $themeApplicationUsage,
        private readonly ThemeScopeVersionService $scopeVersions,
        private readonly ThemeLayoutEntityUpgradeSolidifyService $solidify,
        private readonly ThemeLayoutEntityBakeCoordinator $bakeCoordinator,
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeUpgradeCommand $themeUpgrade,
    ) {
    }

    /**
     * @return array{
     *   themes_scanned:int,
     *   changed:int,
     *   solidified:int,
     *   chrome_healed:int,
     *   static_published:int,
     *   skipped_files:int,
     *   bootstrapped:int,
     *   messages:list<string>
     * }
     */
    public function run(): array
    {
        $report = [
            'themes_scanned' => 0,
            'changed' => 0,
            'solidified' => 0,
            'chrome_healed' => 0,
            'static_published' => 0,
            'skipped_files' => 0,
            'bootstrapped' => 0,
            'messages' => [],
        ];

        foreach ($this->themeApplicationUsage->themesForDefaultUpgrade() as $theme) {
            ++$report['themes_scanned'];
            $themeId = (int)$theme->getId();
            if ($themeId < 1) {
                continue;
            }
            try {
                $result = $this->processTheme($theme);
            } catch (\Throwable $e) {
                $report['messages'][] = 'theme_' . $themeId . '_error:' . $e->getMessage();
                continue;
            }
            if (!empty($result['bootstrapped'])) {
                ++$report['bootstrapped'];
            }
            if (!empty($result['changed'])) {
                ++$report['changed'];
            }
            $report['solidified'] += (int)($result['solidified'] ?? 0);
            $report['chrome_healed'] += (int)($result['chrome_healed'] ?? 0);
            $report['static_published'] += (int)($result['static_published'] ?? 0);
            $report['skipped_files'] += (int)($result['skipped_files'] ?? 0);
            foreach ((array)($result['messages'] ?? []) as $msg) {
                $report['messages'][] = (string)$msg;
            }
        }

        return $report;
    }

    /**
     * @return array{
     *   changed:bool,
     *   bootstrapped:bool,
     *   solidified:int,
     *   chrome_healed:int,
     *   static_published:int,
     *   skipped_files:int,
     *   messages:list<string>
     * }
     */
    private function processTheme(WelineTheme $theme): array
    {
        $themeId = (int)$theme->getId();
        $previous = $this->readManifest($themeId);
        $snapshot = $this->buildSnapshot($theme, $previous);
        $out = [
            'changed' => false,
            'bootstrapped' => false,
            'solidified' => 0,
            'chrome_healed' => 0,
            'static_published' => 0,
            'skipped_files' => 0,
            'messages' => [],
        ];

        if ($previous === null) {
            $this->writeManifest($themeId, $snapshot);
            $out['bootstrapped'] = true;

            return $out;
        }

        $diff = $this->diffSnapshot($previous, $snapshot);
        if ($diff['changed_paths'] === []) {
            // Refresh mtime/size cache even when content unchanged (mtime-coarse reuse).
            if ($diff['manifest_dirty']) {
                $this->writeManifest($themeId, $snapshot);
            }

            return $out;
        }

        $out['changed'] = true;
        $classified = $this->classifyChangedPaths($diff['changed_paths']);
        $out['skipped_files'] = \count($classified['skipped']);
        foreach ($classified['skipped'] as $path) {
            $out['messages'][] = 'skip_unmapped:' . $path;
        }

        $targets = $this->themeApplicationUsage->ownSolidifyTargetsForTheme($themeId);
        $ensured = $this->ensureBoundScopes($themeId, $targets);

        if ($classified['chrome'] && $ensured !== []) {
            $healed = $this->healChrome($themeId, $ensured);
            $out['chrome_healed'] = $healed;
        }

        if ($classified['layouts'] !== []) {
            $out['solidified'] = $this->solidifyLayoutChanges($themeId, $classified['layouts']);
        }

        if ($classified['assets']) {
            $this->themeUpgrade->publishStaticAssets($theme);
            $out['static_published'] = 1;
        }

        $this->writeManifest($themeId, $snapshot);

        return $out;
    }

    /**
     * @param list<array{
     *   theme_id:int,
     *   area:string,
     *   store_mode:string,
     *   scope_key:string,
     *   scope_kind:string,
     *   website_id:int|null,
     *   version_owner_scope:string
     * }> $targets
     * @return list<array{scope:string,area:string,store_mode:string,version_id:int}>
     */
    private function ensureBoundScopes(int $themeId, array $targets): array
    {
        $ensured = [];
        $seen = [];
        foreach ($targets as $target) {
            $scope = \trim((string)($target['version_owner_scope'] ?? ''));
            $area = (string)($target['area'] ?? 'frontend');
            $storeMode = (string)($target['store_mode'] ?? 'normal');
            $key = $scope . '|' . $area . '|' . $storeMode;
            if ($scope === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $version = $this->scopeVersions->ensureCurrent(
                $themeId,
                $scope,
                (string)($target['scope_kind'] ?? 'website'),
                $target['website_id'] ?? null,
                $storeMode,
                $area,
            );
            $ensured[] = [
                'scope' => $scope,
                'area' => $area,
                'store_mode' => $storeMode,
                'version_id' => (int)$version->getVersionId(),
            ];
        }

        return $ensured;
    }

    /**
     * @param list<array{scope:string,area:string,store_mode:string,version_id:int}> $ensured
     */
    private function healChrome(int $themeId, array $ensured): int
    {
        $healed = 0;
        foreach ($ensured as $row) {
            if ($this->bakeCoordinator->rematerializeChromeIfStale(
                $themeId,
                $row['scope'],
                $row['version_id'],
                $row['area'],
                $row['store_mode'],
            )) {
                ++$healed;
            }
        }

        return $healed;
    }

    /**
     * @param list<array{type:string,option:string}> $layouts
     */
    private function solidifyLayoutChanges(int $themeId, array $layouts): int
    {
        $byType = [];
        foreach ($layouts as $layout) {
            $type = \trim($layout['type']);
            $option = \trim($layout['option']);
            if ($type === '') {
                continue;
            }
            $byType[$type][$option !== '' ? $option : 'default'] = true;
        }
        if ($byType === []) {
            return 0;
        }

        $migrated = 0;
        $types = \array_keys($byType);
        if (\count($types) === 1) {
            $type = $types[0];
            $options = \array_keys($byType[$type]);
            $filter = ['layout_type' => $type];
            if (\count($options) === 1) {
                $filter['layout_option'] = $options[0];
            }
            $migrated += $this->solidify->solidifyTheme($themeId, null, $filter);

            return $migrated;
        }

        // Multiple layout types changed: cascade per type (never unfiltered full-theme bake).
        foreach ($byType as $type => $optionMap) {
            $options = \array_keys($optionMap);
            $filter = ['layout_type' => (string)$type];
            if (\count($options) === 1) {
                $filter['layout_option'] = $options[0];
            }
            $migrated += $this->solidify->solidifyTheme($themeId, null, $filter);
        }

        return $migrated;
    }

    /**
     * @param list<string> $changedPaths
     * @return array{
     *   layouts:list<array{type:string,option:string}>,
     *   chrome:bool,
     *   assets:bool,
     *   skipped:list<string>
     * }
     */
    private function classifyChangedPaths(array $changedPaths): array
    {
        $layouts = [];
        $layoutKeys = [];
        $chrome = false;
        $assets = false;
        $skipped = [];
        foreach ($changedPaths as $path) {
            $class = $this->classifyRelativePath($path);
            if ($class === null) {
                continue;
            }
            if ($class['kind'] === 'layout') {
                $key = $class['type'] . '|' . $class['option'];
                if (!isset($layoutKeys[$key])) {
                    $layoutKeys[$key] = true;
                    $layouts[] = ['type' => $class['type'], 'option' => $class['option']];
                }
                continue;
            }
            if ($class['kind'] === 'chrome') {
                $chrome = true;
                continue;
            }
            if ($class['kind'] === 'assets') {
                $assets = true;
                continue;
            }
            if ($class['kind'] === 'skip') {
                $skipped[] = $path;
            }
        }

        return [
            'layouts' => $layouts,
            'chrome' => $chrome,
            'assets' => $assets,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{kind:string,type?:string,option?:string}|null
     */
    private function classifyRelativePath(string $relative): ?array
    {
        $relative = \str_replace('\\', '/', \ltrim($relative, '/'));
        if ($relative === '') {
            return null;
        }
        if (\preg_match('#(?:^|/)layouts/(.+)\.phtml$#D', $relative, $match) === 1) {
            $rest = (string)$match[1];
            $parts = \array_values(\array_filter(\explode('/', $rest), static fn(string $p): bool => $p !== ''));
            if ($parts === []) {
                return ['kind' => 'skip'];
            }
            if (\count($parts) === 1) {
                return ['kind' => 'layout', 'type' => 'default', 'option' => $parts[0]];
            }
            $option = (string)\array_pop($parts);

            return ['kind' => 'layout', 'type' => \implode('/', $parts), 'option' => $option];
        }
        if (\preg_match('#(?:^|/)partials/(header|footer|sidebar)(?:/|$)#D', $relative) === 1
            && \str_ends_with($relative, '.phtml')
        ) {
            return ['kind' => 'chrome'];
        }
        if (\preg_match('#(?:^|/)assets/#', $relative) === 1) {
            return ['kind' => 'assets'];
        }
        if (\str_ends_with($relative, '.phtml')) {
            return ['kind' => 'skip'];
        }

        return null;
    }

    /**
     * @param array<string, array{sha256:string,mtime:int,size:int}>|null $previous
     * @return array<string, array{sha256:string,mtime:int,size:int}>
     */
    private function buildSnapshot(WelineTheme $theme, ?array $previous = null): array
    {
        $themePath = \rtrim(\str_replace('\\', '/', (string)$theme->getPath()), '/');
        if ($themePath === '' || !\is_dir($themePath)) {
            return [];
        }
        $files = [];
        foreach ($this->watchRoots($themePath) as $root) {
            $base = $root['absolute'];
            if (!\is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->isLink()) {
                    continue;
                }
                $absolute = \str_replace('\\', '/', $item->getPathname());
                $relative = \substr($absolute, \strlen($themePath) + 1);
                if ($relative === false || $relative === '') {
                    continue;
                }
                $class = $this->classifyRelativePath($relative);
                if ($class === null) {
                    continue;
                }
                if ($class['kind'] === 'assets') {
                    // Watch common static extensions under assets/; ignore junk.
                    $ext = \strtolower((string)\pathinfo($relative, \PATHINFO_EXTENSION));
                    if ($ext === '' || !\in_array($ext, [
                        'css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif',
                        'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'txt',
                        'xml', 'webmanifest', 'wasm',
                    ], true)) {
                        continue;
                    }
                } elseif ($class['kind'] === 'skip') {
                    if (!\str_ends_with($relative, '.phtml')) {
                        continue;
                    }
                } elseif ($class['kind'] === 'layout' || $class['kind'] === 'chrome') {
                    if (!\str_ends_with($relative, '.phtml')) {
                        continue;
                    }
                } else {
                    continue;
                }
                $mtime = (int)@$item->getMTime();
                $size = (int)@$item->getSize();
                $prevMeta = $previous[$relative] ?? null;
                if (
                    \is_array($prevMeta)
                    && (int)($prevMeta['mtime'] ?? -1) === $mtime
                    && (int)($prevMeta['size'] ?? -1) === $size
                    && (string)($prevMeta['sha256'] ?? '') !== ''
                ) {
                    $files[$relative] = [
                        'sha256' => (string)$prevMeta['sha256'],
                        'mtime' => $mtime,
                        'size' => $size,
                    ];
                    continue;
                }
                $files[$relative] = [
                    'sha256' => \hash_file('sha256', $absolute) ?: '',
                    'mtime' => $mtime,
                    'size' => $size,
                ];
            }
        }
        \ksort($files);

        return $files;
    }

    /**
     * @return list<array{absolute:string}>
     */
    private function watchRoots(string $themePath): array
    {
        $roots = [];
        foreach (['frontend', 'backend'] as $area) {
            foreach ([
                $themePath . '/theme/' . $area,
                $themePath . '/view/theme/' . $area,
                $themePath . '/' . $area,
            ] as $base) {
                if (\is_dir($base)) {
                    $roots[] = ['absolute' => \str_replace('\\', '/', $base)];
                }
            }
        }

        return $roots;
    }

    /**
     * @param array<string, array{sha256:string,mtime:int,size:int}> $previous
     * @param array<string, array{sha256:string,mtime:int,size:int}> $current
     * @return array{changed_paths:list<string>,manifest_dirty:bool}
     */
    private function diffSnapshot(array $previous, array $current): array
    {
        $changed = [];
        $allKeys = \array_unique(\array_merge(\array_keys($previous), \array_keys($current)));
        $manifestDirty = false;
        foreach ($allKeys as $path) {
            $prev = $previous[$path] ?? null;
            $curr = $current[$path] ?? null;
            if ($prev === null || $curr === null) {
                $changed[] = $path;
                continue;
            }
            if ((string)($prev['sha256'] ?? '') !== (string)($curr['sha256'] ?? '')) {
                $changed[] = $path;
                continue;
            }
            if ((int)($prev['mtime'] ?? 0) !== (int)($curr['mtime'] ?? 0)
                || (int)($prev['size'] ?? 0) !== (int)($curr['size'] ?? 0)
            ) {
                $manifestDirty = true;
            }
        }
        \sort($changed);

        return ['changed_paths' => $changed, 'manifest_dirty' => $manifestDirty];
    }

    /**
     * @return array<string, array{sha256:string,mtime:int,size:int}>|null
     */
    private function readManifest(int $themeId): ?array
    {
        $path = $this->manifestPath($themeId);
        if (!\is_file($path)) {
            return null;
        }
        try {
            $raw = \file_get_contents($path);
            if ($raw === false || $raw === '') {
                return null;
            }
            $data = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($data) || !\is_array($data['files'] ?? null)) {
            return null;
        }
        $files = [];
        foreach ($data['files'] as $rel => $meta) {
            if (!\is_string($rel) || !\is_array($meta)) {
                continue;
            }
            $files[$rel] = [
                'sha256' => (string)($meta['sha256'] ?? ''),
                'mtime' => (int)($meta['mtime'] ?? 0),
                'size' => (int)($meta['size'] ?? 0),
            ];
        }

        return $files;
    }

    /**
     * @param array<string, array{sha256:string,mtime:int,size:int}> $files
     */
    private function writeManifest(int $themeId, array $files): void
    {
        $path = $this->manifestPath($themeId);
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('theme_origin_watch_manifest_mkdir_failed:' . $dir);
        }
        $payload = [
            'version' => self::MANIFEST_VERSION,
            'updated_at' => \gmdate('c'),
            'deploy' => (string)(Env::system('deploy') ?? ''),
            'files' => $files,
        ];
        $json = \json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
        $tmp = $path . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException('theme_origin_watch_manifest_write_failed:' . $tmp);
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException('theme_origin_watch_manifest_rename_failed:' . $path);
        }
    }

    private function manifestPath(int $themeId): string
    {
        return \rtrim($this->paths->root(), '/\\')
            . \DIRECTORY_SEPARATOR
            . $themeId
            . \DIRECTORY_SEPARATOR
            . self::MANIFEST_NAME;
    }
}
