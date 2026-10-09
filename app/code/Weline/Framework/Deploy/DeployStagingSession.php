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

    /** @var list<array{live:string,prev:string,tree:string}> */
    private array $committedPrev = [];

    private function __construct(string $stamp, string $root)
    {
        $this->stamp = $stamp;
        $this->root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function isActive(): bool
    {
        return self::$active !== null || self::envRoot() !== '';
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
                if (file_exists($prev) || is_dir($prev) || is_link($prev)) {
                    throw new \RuntimeException('deploy_staging_prev_exists:' . $prev);
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
        foreach ($this->committedPrev as $item) {
            $prev = $item['prev'];
            if (is_dir($prev) || is_link($prev) || file_exists($prev)) {
                $this->removePath($prev);
            }
        }
        $this->committedPrev = [];
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
