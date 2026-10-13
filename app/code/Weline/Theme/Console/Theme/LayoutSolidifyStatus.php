<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPageInputDigest;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyJobStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;

/**
 * Pending solidify jobs + chrome digest health; optional --purge-stale GC with dry-run default.
 */
class LayoutSolidifyStatus implements CommandInterface
{
    public const ALIASES = ['theme:layout-solidify-status'];

    public function __construct(
        private readonly Printing $printing,
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeLayoutEntitySolidifyJobStore $jobs,
    ) {
    }

    public function execute(array $args = [], array $data = [])
    {
        $themeId = 0;
        $purgeStale = false;
        $apply = false;
        $ttl = ThemeLayoutEntitySolidifyJobStore::DEFAULT_TTL_SECONDS;
        foreach ($args as $i => $arg) {
            if (($arg === '-t' || $arg === '--theme-id') && isset($args[(int)$i + 1])) {
                $themeId = (int)$args[(int)$i + 1];
            }
            if ($arg === '--purge-stale') {
                $purgeStale = true;
            }
            if ($arg === '--apply') {
                $apply = true;
            }
            if ($arg === '--dry-run') {
                $apply = false;
            }
            if (($arg === '--ttl') && isset($args[(int)$i + 1])) {
                $ttl = max(60, (int)$args[(int)$i + 1]);
            }
        }
        if ($themeId < 1 && isset($args['t'])) {
            $themeId = (int)$args['t'];
        }

        if ($purgeStale) {
            $this->runPurgeStale($apply, $ttl);
        }

        $pending = $this->jobs->listPending(200);
        $this->printing->note(__('Pending solidify jobs: %{1}', [(string)count($pending)]));
        foreach ($pending as $job) {
            $key = $job['key'];
            $this->printing->note(sprintf(
                '  - theme=%d layout=%s/%s scope=%s V=%d R=%d queued=%s',
                $key->themeId,
                $key->layoutType,
                $key->layoutOption,
                $key->canonicalScope,
                $key->themeVersionId,
                $key->contentRevision,
                (string)($job['queued_at'] ?? ''),
            ));
        }

        if ($themeId > 0) {
            $this->reportChromeHealth($themeId);
        } else {
            $this->printing->note(__('Tip: php bin/w theme:layout-solidify-status -t {theme_id} for chrome digest sample'));
        }
    }

    private function runPurgeStale(bool $apply, int $ttl): void
    {
        $predicate = ThemeLayoutEntitySolidifyJobStore::defaultStalePredicate(
            static function (int $themeId): bool {
                $theme = (clone ObjectManager::getInstance(WelineTheme::class))->clearData()->clearQuery()->load($themeId);

                return $theme !== null && (int)$theme->getId() === $themeId && $themeId > 0;
            },
            static function (int $themeId, int $versionId, string $scope, string $area, string $storeMode): bool {
                $version = (clone ObjectManager::getInstance(ThemeScopeVersion::class))
                    ->clearData()->clearQuery()->load($versionId);
                if ($version === null || (int)$version->getVersionId() !== $versionId) {
                    return false;
                }

                return (int)$version->getThemeId() === $themeId
                    && (string)$version->getScope() === $scope
                    && (string)$version->getArea() === $area
                    && (string)$version->getStoreMode() === $storeMode;
            },
            $ttl,
        );
        $report = $this->jobs->purgeStale($predicate, $apply);
        $mode = $apply ? 'apply' : 'dry-run';
        $this->printing->note(sprintf(
            'purge-stale %s: candidates=%d purged=%d ttl=%ds',
            $mode,
            count($report['candidates']),
            (int)$report['purged'],
            $ttl,
        ));
        foreach ($report['candidates'] as $item) {
            $key = $item['key'];
            $this->printing->note(sprintf(
                '  [%s] theme=%d V=%d scope=%s layout=%s/%s reason=%s',
                $apply ? 'purged' : 'would-purge',
                $key->themeId,
                $key->themeVersionId,
                $key->canonicalScope,
                $key->layoutType,
                $key->layoutOption,
                (string)$item['reason'],
            ));
        }
    }

    private function reportChromeHealth(int $themeId): void
    {
        $root = rtrim($this->paths->root(), '/\\') . DIRECTORY_SEPARATOR . $themeId;
        if (!is_dir($root)) {
            $this->printing->warning(__('No layout entities for theme #%{1}', [(string)$themeId]));

            return;
        }
        $buckets = [
            'draft' => ['checked' => 0, 'ok' => 0, 'mismatch' => 0, 'missing_digest' => 0, 'raw_no_meta' => 0],
            'formal' => ['checked' => 0, 'ok' => 0, 'mismatch' => 0, 'missing_digest' => 0, 'raw_no_meta' => 0],
        ];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'phtml') {
                continue;
            }
            $path = $file->getPathname();
            $normalized = str_replace('\\', '/', $path);
            if (!str_contains($normalized, '/theme/partials/')) {
                continue;
            }
            if (!preg_match('#/partials/(header|footer)/#', $normalized)) {
                continue;
            }
            $lane = str_contains($normalized, '/draft/') ? 'draft' : 'formal';
            $buckets[$lane]['checked']++;
            $meta = ThemeLayoutSourceSnapshot::metadata((string)file_get_contents($path));
            if (!is_array($meta)) {
                $buckets[$lane]['raw_no_meta']++;
                continue;
            }
            $digest = trim((string)($meta['input_digest'] ?? ''));
            $origin = (string)($meta['origin'] ?? '');
            if ($digest === '') {
                $buckets[$lane]['missing_digest']++;
                continue;
            }
            if (!ThemeLayoutEntityPageInputDigest::matchesStored($digest, $origin)) {
                $buckets[$lane]['mismatch']++;
                continue;
            }
            $buckets[$lane]['ok']++;
        }
        $total = [
            'checked' => $buckets['draft']['checked'] + $buckets['formal']['checked'],
            'ok' => $buckets['draft']['ok'] + $buckets['formal']['ok'],
            'mismatch' => $buckets['draft']['mismatch'] + $buckets['formal']['mismatch'],
            'missing_digest' => $buckets['draft']['missing_digest'] + $buckets['formal']['missing_digest'],
            'raw_no_meta' => $buckets['draft']['raw_no_meta'] + $buckets['formal']['raw_no_meta'],
        ];
        foreach (['draft', 'formal'] as $lane) {
            $b = $buckets[$lane];
            $this->printing->note(sprintf(
                'Chrome %s theme#%d: checked=%d ok=%d mismatch=%d missing_digest=%d raw_no_meta=%d',
                $lane,
                $themeId,
                $b['checked'],
                $b['ok'],
                $b['mismatch'],
                $b['missing_digest'],
                $b['raw_no_meta'],
            ));
        }
        $this->printing->note(sprintf(
            'Chrome header/footer sample theme#%d: checked=%d ok=%d mismatch=%d missing_digest=%d raw_no_meta=%d',
            $themeId,
            $total['checked'],
            $total['ok'],
            $total['mismatch'],
            $total['missing_digest'],
            $total['raw_no_meta'],
        ));
    }

    public function tip(): string
    {
        return __('布局固化物 pending jobs / chrome digest 健康；可选 --purge-stale GC');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:layout-solidify-status',
            '列出 .solidify-jobs 积压，并可抽检主题 header/footer 的 weline-source/input_digest；可选 purge-stale',
            [
                '-t, --theme-id' => '主题 catalog theme_id（可选，用于 chrome 抽检）',
                '--purge-stale' => '按存活谓词（主题/版本不存在或超 TTL）列/清 stale jobs',
                '--dry-run' => 'purge-stale 默认：只列候选不删（可显式）',
                '--apply' => 'purge-stale 真正移入 failed/ 并移出 pending',
                '--ttl' => 'TTL 秒（默认 86400）',
                '-h, --help' => '显示帮助',
            ],
            [],
            [
                '查看积压' => 'php bin/w theme:layout-solidify-status',
                '抽检 PIC' => 'php bin/w theme:layout-solidify-status -t 11',
                'GC dry-run' => 'php bin/w theme:layout-solidify-status --purge-stale --dry-run',
                'GC apply' => 'php bin/w theme:layout-solidify-status --purge-stale --apply',
            ],
        );
    }
}
