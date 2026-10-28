<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

use Weline\Framework\App\Env;

/**
 * Mode\Set prod unified staging pipeline: write artifacts under var/deploy/staging/{stamp}/,
 * then rename-swap onto live trees. Live trees stay readable until commitSwap.
 */
final class DeployStagingSession
{
    public const ENV_ROOT = 'WELINE_DEPLOY_STAGING_ROOT';

    public const TREE_STATIC = 'static';
    public const TREE_COMPLICATE = 'complicate';
    public const TREE_THEME_LAYOUT = 'theme-layout-entities';

    private static ?self $active = null;

    private string $stamp;
    private string $root;
    private StaticCarryOver $staticCarryOver;

    /** @var list<array{live:string,prev:string,tree:string}> */
    private array $committedPrev = [];

    private function __construct(string $stamp, string $root)
    {
        $this->stamp = $stamp;
        $this->root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $this->staticCarryOver = new StaticCarryOver();
    }

    public static function isActive(): bool
    {
        return self::$active !== null || self::envRoot() !== '';
    }

    /**
     * Mode\Set prod staging runs in a process that still has DEV=true / PROD=false
     * (App constants are define-once). Public static URLs and version stamps must
     * nevertheless use production semantics so baked HTML / complicate do not leak
     * `/Weline/...` module paths or `?v=dev_*` fingerprints into the live trees.
     */
    public static function forcesProductionPublicUrls(): bool
    {
        return self::isActive();
    }

    public static function current(): ?self
    {
        return self::$active;
    }

    public static function envRoot(): string
    {
        $fromEnv = getenv(self::ENV_ROOT);
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return rtrim($fromEnv, '/\\') . DIRECTORY_SEPARATOR;
        }
        $fromServer = $_ENV[self::ENV_ROOT] ?? $_SERVER[self::ENV_ROOT] ?? '';
        if (is_string($fromServer) && trim($fromServer) !== '') {
            return rtrim($fromServer, '/\\') . DIRECTORY_SEPARATOR;
        }

        return self::$active?->root ?? '';
    }

    public static function staticRoot(): string
    {
        $staging = self::envRoot();
        if ($staging !== '') {
            return $staging . self::TREE_STATIC;
        }

        return rtrim((string)\PUB, '/\\') . DIRECTORY_SEPARATOR . 'static';
    }

    public static function complicateRoot(): string
    {
        $staging = self::envRoot();
        if ($staging !== '') {
            return $staging . self::TREE_COMPLICATE . DIRECTORY_SEPARATOR;
        }

        return rtrim((string)Env::path_COMPLICATE_GENERATED_DIR, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function themeLayoutEntitiesRoot(): string
    {
        $staging = self::envRoot();
        if ($staging !== '') {
            return $staging . self::TREE_THEME_LAYOUT . DIRECTORY_SEPARATOR;
        }
        $generated = \defined(Env::class . '::GENERATED_DIR')
            ? (string)Env::GENERATED_DIR
            : (rtrim((string)\BP, '/\\') . DIRECTORY_SEPARATOR . 'generated');

        return rtrim($generated, '/\\') . DIRECTORY_SEPARATOR . self::TREE_THEME_LAYOUT . DIRECTORY_SEPARATOR;
    }

    public static function open(?string $stamp = null): self
    {
        if (self::$active !== null) {
            throw new \RuntimeException('deploy_staging_session_already_open');
        }
        $stamp = trim((string)$stamp);
        if ($stamp === '') {
            $stamp = 'prod-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        }
        $root = rtrim((string)\BP, '/\\') . DIRECTORY_SEPARATOR
            . 'var' . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR
            . 'staging' . DIRECTORY_SEPARATOR . $stamp . DIRECTORY_SEPARATOR;
        foreach ([self::TREE_STATIC, self::TREE_COMPLICATE, self::TREE_THEME_LAYOUT] as $tree) {
            $dir = $root . $tree;
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('deploy_staging_mkdir_failed:' . $tree);
            }
        }
        $session = new self($stamp, $root);
        self::$active = $session;
        putenv(self::ENV_ROOT . '=' . rtrim($root, '/\\'));
        $_ENV[self::ENV_ROOT] = rtrim($root, '/\\');
        $_SERVER[self::ENV_ROOT] = rtrim($root, '/\\');

        return $session;
    }

    public function stamp(): string
    {
        return $this->stamp;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Rename-swap staging trees onto live paths. On any failure, restore from *.prev.{stamp}.
     *
     * flat 契约模式（{@see StaticBuildId::isFlatMode()}）下静态树不整树 rename：
     * 逐文件同目录原子替换到 live 平铺路径，任一时刻被引用路径都有可读字节；
     * 仅本次 staging 产物被覆盖，live 独有的历史字节保留（单权威树原地演进）。
     */
    public function commitSwap(): void
    {
        $pairs = $this->swapPairs();
        $done = [];
        try {
            foreach ($pairs as $pair) {
                $live = $pair['live'];
                $staging = $pair['staging'];
                $prev = $pair['prev'];
                if (!is_dir($staging)) {
                    throw new \RuntimeException('deploy_staging_missing_tree:' . $pair['tree']);
                }
                $hasContent = $this->dirHasEntries($staging);
                if (!$hasContent) {
                    // static must be populated; other trees may stay live if pipeline produced nothing.
                    if ($pair['tree'] === self::TREE_STATIC) {
                        throw new \RuntimeException('deploy_staging_static_empty');
                    }
                    continue;
                }
                if ($pair['tree'] === self::TREE_STATIC && StaticBuildId::isFlatMode()) {
                    $this->overlayStaticFlat($staging, $live);
                    $done[] = array_merge($pair, ['flat' => true]);
                    continue;
                }
                if (file_exists($prev) || is_dir($prev) || is_link($prev)) {
                    throw new \RuntimeException('deploy_staging_prev_exists:' . $prev);
                }
                if ($pair['tree'] === self::TREE_STATIC) {
                    // Content-hashed theme packs are immutable by URL, but the
                    // rename-swap would otherwise make their lifetime exactly
                    // one release. Carry still-servable old bytes into staging
                    // before the swap; new staging files always win.
                    try {
                        $this->staticCarryOver->carryForward($live, $staging);
                    } catch (\Throwable $error) {
                        throw new \RuntimeException(
                            'deploy_staging_static_carry_failed:' . $error->getMessage(),
                            0,
                            $error,
                        );
                    }
                }
                if (is_dir($live) || is_link($live) || (file_exists($live) && !is_dir($live))) {
                    if (!@rename($live, $prev)) {
                        throw new \RuntimeException('deploy_staging_rename_live_to_prev_failed:' . $pair['tree']);
                    }
                }
                $liveParent = dirname($live);
                if (!is_dir($liveParent) && !mkdir($liveParent, 0775, true) && !is_dir($liveParent)) {
                    if (is_dir($prev)) {
                        @rename($prev, $live);
                    }
                    throw new \RuntimeException('deploy_staging_live_parent_failed:' . $pair['tree']);
                }
                if (!@rename($staging, $live)) {
                    if (is_dir($prev)) {
                        @rename($prev, $live);
                    }
                    throw new \RuntimeException('deploy_staging_rename_staging_to_live_failed:' . $pair['tree']);
                }
                $done[] = $pair;
                $this->committedPrev[] = [
                    'live' => $live,
                    'prev' => $prev,
                    'tree' => $pair['tree'],
                ];
            }
        } catch (\Throwable $error) {
            $this->rollbackSwap($done);
            throw $error;
        }
    }

    /**
     * Remove previous live trees after successful commit. Also clears leftover module view/tpl.
     */
    public function purgePrevAndResidue(): void
    {
        $staticLive = '';
        foreach ($this->committedPrev as $item) {
            if ($item['tree'] === self::TREE_STATIC) {
                $staticLive = $item['live'];
            }
            $prev = $item['prev'];
            if (is_dir($prev) || is_link($prev) || file_exists($prev)) {
                $this->removePath($prev);
            }
        }
        $this->committedPrev = [];
        if ($staticLive !== '' && is_dir($staticLive)) {
            // Retention GC runs only after the swap is committed. A failure
            // here must not turn a successful deploy into a rollback; it only
            // delays tombstone cleanup to the next deploy.
            try {
                $this->staticCarryOver->pruneExpired($staticLive);
            } catch (\Throwable $error) {
                try {
                    Env::log_error('deploy', 'static_carry_prune_failed: ' . $error->getMessage());
                } catch (\Throwable) {
                    // Deploy already committed; never fail it from logging.
                }
            }
        }
        $this->purgeModuleTplResidue();
        $this->removePath(rtrim($this->root, '/\\'));
        $this->removeEmptyStagingParent();
    }

    /** Drop staging only; live untouched. */
    public function abort(): void
    {
        $this->removePath(rtrim($this->root, '/\\'));
        $this->removeEmptyStagingParent();
        $this->deactivate();
    }

    /**
     * flat 模式静态树提交：staging 产物逐文件同目录原子替换进 live 平铺树。
     * legacy 世代目录（12hex / tu-* / {code}/v{n}）与 .build.json 不参与覆盖；
     * live 独有文件一律保留 —— 单权威树只增改不删，旧引用永不 404。
     */
    private function overlayStaticFlat(string $staging, string $live): void
    {
        $staging = rtrim($staging, '/\\');
        $live = rtrim($live, '/\\');
        if (!is_dir($live) && !mkdir($live, 0775, true) && !is_dir($live)) {
            throw new \RuntimeException('deploy_staging_live_parent_failed:' . self::TREE_STATIC);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($staging) + 1);
            $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($this->isLegacyGenerationPath($normalized)) {
                continue;
            }
            $target = $live . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('deploy_staging_overlay_mkdir_failed:' . $normalized);
            }
            $tmp = $target . '.swap-' . $this->stamp;
            $this->removePath($tmp);
            if (@link($item->getPathname(), $tmp) || @copy($item->getPathname(), $tmp)) {
                if (!@rename($tmp, $target)) {
                    $this->removePath($tmp);
                    if (!@copy($item->getPathname(), $target)) {
                        throw new \RuntimeException('deploy_staging_overlay_write_failed:' . $normalized);
                    }
                }
            } elseif (!@copy($item->getPathname(), $target)) {
                throw new \RuntimeException('deploy_staging_overlay_copy_failed:' . $normalized);
            }
        }
    }

    /** 判断相对路径首段是否为 legacy 世代目录（12hex 构建代 / tu- 批次 / v{n} 主题版本段）。 */
    private function isLegacyGenerationPath(string $normalizedRelative): bool
    {
        $segments = explode('/', $normalizedRelative);
        // 世代前缀最多三段：{code}/v{n}/{deploy12hex}；Weline/<theme>/<area> 等真实命名空间不误判。
        $depth = \min(\count($segments) - 1, 2);
        for ($index = 0; $index <= $depth; $index++) {
            $segment = $segments[$index];
            if ($index === 0 && (\preg_match('/^[0-9a-f]{12}$/i', $segment) === 1 || \str_starts_with($segment, 'tu-'))) {
                return true;
            }
            if ($index >= 1 && \preg_match('/^v[0-9]+$/', $segment) === 1) {
                return true;
            }
            if ($index === 2 && \preg_match('/^[0-9a-f]{12}$/i', $segment) === 1
                && \preg_match('/^v[0-9]+$/', $segments[1]) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Remove var/deploy/staging when no stamp dirs remain (.DS_Store ignored). */
    private function removeEmptyStagingParent(): void
    {
        $parent = dirname(rtrim($this->root, '/\\'));
        if (!is_dir($parent)) {
            return;
        }
        $children = @scandir($parent) ?: [];
        foreach ($children as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($name === '.DS_Store') {
                @unlink($parent . DIRECTORY_SEPARATOR . $name);
                continue;
            }

            return;
        }
        @rmdir($parent);
    }

    public function deactivate(): void
    {
        if (self::$active === $this) {
            self::$active = null;
        }
        putenv(self::ENV_ROOT);
        unset($_ENV[self::ENV_ROOT], $_SERVER[self::ENV_ROOT]);
    }

    /**
     * @return list<array{tree:string,live:string,staging:string,prev:string}>
     */
    private function swapPairs(): array
    {
        $staticLive = rtrim((string)\PUB, '/\\') . DIRECTORY_SEPARATOR . 'static';
        $complicateLive = rtrim((string)Env::path_COMPLICATE_GENERATED_DIR, '/\\');
        $themeLive = rtrim((string)Env::GENERATED_DIR, '/\\') . DIRECTORY_SEPARATOR . self::TREE_THEME_LAYOUT;

        return [
            [
                'tree' => self::TREE_STATIC,
                'live' => $staticLive,
                'staging' => rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . self::TREE_STATIC,
                'prev' => $staticLive . '.prev.' . $this->stamp,
            ],
            [
                'tree' => self::TREE_COMPLICATE,
                'live' => $complicateLive,
                'staging' => rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . self::TREE_COMPLICATE,
                'prev' => $complicateLive . '.prev.' . $this->stamp,
            ],
            [
                'tree' => self::TREE_THEME_LAYOUT,
                'live' => $themeLive,
                'staging' => rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . self::TREE_THEME_LAYOUT,
                'prev' => $themeLive . '.prev.' . $this->stamp,
            ],
        ];
    }

    /**
     * @param list<array{tree:string,live:string,staging:string,prev:string}> $done
     */
    private function rollbackSwap(array $done): void
    {
        for ($i = count($done) - 1; $i >= 0; $i--) {
            $pair = $done[$i];
            if (!empty($pair['flat'])) {
                // flat 覆盖是逐文件原子替换，已提交字节均为完整可读产物；
                // 半途失败不回滚整树（无 prev 可恢复），下一次发布收敛。
                continue;
            }
            $live = $pair['live'];
            $staging = $pair['staging'];
            $prev = $pair['prev'];
            if (is_dir($live) || is_link($live)) {
                @rename($live, $staging);
            }
            if (is_dir($prev) || is_link($prev)) {
                @rename($prev, $live);
            }
        }
        $this->committedPrev = [];
    }

    private function dirHasEntries(string $dir): bool
    {
        $children = @scandir($dir) ?: [];
        foreach ($children as $name) {
            if ($name !== '.' && $name !== '..') {
                return true;
            }
        }

        return false;
    }

    private function purgeModuleTplResidue(): void
    {
        $modules = Env::getInstance()->getModuleList();
        foreach ($modules as $module) {
            $base = (string)($module['base_path'] ?? '');
            if ($base === '') {
                continue;
            }
            $tpl = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'tpl';
            if (is_dir($tpl)) {
                $this->removePath($tpl);
            }
        }
    }

    private function removePath(string $path): void
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || $path === '/' || (!file_exists($path) && !is_link($path) && !is_dir($path))) {
            return;
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (!file_exists($path) && !is_link($path) && !is_dir($path)) {
                return;
            }
            if (is_link($path) || is_file($path)) {
                @unlink($path);

                return;
            }
            if (!is_dir($path)) {
                return;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $p = $item->getPathname();
                if ($item->isLink() || $item->isFile()) {
                    @unlink($p);
                } elseif ($item->isDir()) {
                    @rmdir($p);
                }
            }
            if (@rmdir($path) || !is_dir($path)) {
                return;
            }
            usleep(50_000);
        }
    }
}
