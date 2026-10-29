<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Output\Cli\Printing;

/**
 * Reclaims disk space under the compiled-template root (generated/complicate).
 *
 * Measured 2026-10-29 on the default instance: 23886 artifacts / 811.6 MB, of
 * which 20249 are com_default.phtml. Compilation is continuous rather than
 * request-driven (three consecutive pierced requests wrote 0 artifacts), so the
 * tree is kept bounded by age instead of trying to deduplicate it.
 *
 * Default mode is a dry run. Guardrails: refuses a root that is not a real
 * directory, refuses symlinked entries, and never escapes the resolved compile
 * root via "..".
 *
 * `--dedupe` reclaims content-level duplicates instead of (or in addition to)
 * ageing out old artifacts. Measured 2026-10-29: 9610 artifacts / 331.6 MB hold
 * only 2185 distinct contents / 127.2 MB, so 204.4 MB (61.6%) is byte-identical
 * duplication caused by the locale x host x theme_id key explosion. Dedup uses
 * hard links, which is safe because compiled artifacts are only ever written by
 * tempnam()+rename() (Template.php:1489-1494) — a recompile always lands on a
 * fresh inode, so it can never mutate a shared one.
 */
class ComplicateGc implements \Weline\Framework\Console\CommandInterface
{
    /** Default retention window; measured to keep the 857 keys that recompiled within 6h. */
    private const DEFAULT_KEEP_HOURS = 6;

    /**
     * Shared disk bytecode cache, injected by the WLS runtime. Kept in sync with
     * LongRunningPhpRuntime::RELATIVE_FILE_CACHE_DIR; duplicated here so this
     * Framework command does not depend on the Server module.
     */
    private const RELATIVE_FILE_CACHE_DIR = 'generated/framework/opcache_file_cache';

    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $options = $this->parseOptions($args);
        $keepHours = $options['keep-hours'];
        $dryRun = !$options['purge'];
        $dedupe = $options['dedupe'];
        $binGc = $options['bin-gc'];
        $root = DeployStagingSession::complicateRoot();

        $this->printing->title(__('模板编译产物回收'), '-', $this->printing::SUCCESS);

        $guard = $this->validateRoot($root);
        if ($guard !== '') {
            $this->printing->errorIcon($guard);

            return 1;
        }

        $cutoff = \time() - ($keepHours * 3600);
        $scan = $this->scanTree($root, $cutoff);

        $this->printing->keyValue([
            __('编译根目录') => $root,
            __('保留窗口') => $keepHours . ' 小时',
            __('模式') => $dryRun ? __('演练（不删除）') : __('执行删除'),
            __('产物总数') => $scan['total_files'],
            __('占用空间') => $this->formatBytes($scan['total_bytes']),
            __('陈旧产物') => $scan['stale_files'],
            __('可释放空间') => $this->formatBytes($scan['stale_bytes']),
        ], ':', 14);

        // Dedup runs after the age sweep so it never spends hashing effort on
        // artifacts that are about to be deleted anyway.
        $deleted = ['files' => 0, 'bytes' => 0, 'errors' => 0];
        if ($scan['stale_files'] > 0 && !$dryRun) {
            $deleted = $this->deleteStale($root, $cutoff);

            $this->printing->successIcon(__('已删除 %{1} 个产物，释放 %{2}', [
                $deleted['files'],
                $this->formatBytes($deleted['bytes']),
            ]));

            if ($deleted['errors'] > 0) {
                $this->printing->warningIcon(__('%{1} 个条目删除失败，详见上方日志。', [$deleted['errors']]));
            }
        }

        if ($dedupe) {
            $dedupeResult = $this->dedupeByContent($root, $dryRun);

            $this->printing->keyValue([
                __('唯一内容数') => $dedupeResult['unique'],
                __('重复副本数') => $dedupeResult['duplicates'],
                __('已建立硬链接') => $dedupeResult['linked'],
                __('已是硬链接（跳过）') => $dedupeResult['already'],
                __('可回收空间') => $this->formatBytes($dedupeResult['bytes']),
                __('跳过（失败/跨设备）') => $dedupeResult['skipped'],
            ], ':', 14);

            if ($dryRun) {
                $this->printing->warningIcon(__('演练模式未建立任何硬链接；加 --purge 才真正去重。'));
            }
        }

        if ($binGc) {
            $binResult = $this->collectOrphanBytecode($dryRun);

            $this->printing->keyValue([
                __('字节码缓存目录') => $binResult['root'] === '' ? __('（不可用）') : $binResult['root'],
                __('缓存文件总数') => $binResult['total_files'],
                __('孤儿缓存（源已不存在）') => $binResult['orphan_files'],
                __('孤儿缓存占用') => $this->formatBytes($binResult['orphan_bytes']),
                __('已删除缓存数') => $binResult['deleted_files'],
                __('删除失败数') => $binResult['errors'],
            ], ':', 14);

            if ($dryRun && $binResult['orphan_files'] > 0) {
                $this->printing->warningIcon(__('演练模式未删除任何字节码缓存；加 --purge 才真正删除。'));
            }
        }

        if ($scan['stale_files'] === 0 && (!$dedupe || ($dedupeResult['linked'] ?? 0) === 0)) {
            $this->printing->successIcon(__('没有超出保留窗口的产物，无需回收。'));
        }

        return 0;
    }

    /**
     * @param array<int|string, mixed> $args
     * @return array{keep-hours:int,purge:bool}
     */
    private function parseOptions(array $args): array
    {
        $keepHours = self::DEFAULT_KEEP_HOURS;
        $purge = false;
        $dedupe = false;
        $binGc = false;

        foreach ($args as $key => $value) {
            if (!\is_string($value)) {
                continue;
            }
            if ($value === '--purge') {
                $purge = true;
                continue;
            }
            if ($value === '--dedupe') {
                $dedupe = true;
                continue;
            }
            if ($value === '--bin-gc') {
                $binGc = true;
                continue;
            }
            if (\preg_match('/^--keep-hours=(\d+)$/', $value, $match) === 1) {
                $keepHours = (int)$match[1];
                continue;
            }
            // Supports: --keep-hours 6
            if ($value === '--keep-hours' && isset($args[$key + 1]) && \is_numeric($args[$key + 1])) {
                $keepHours = (int)$args[$key + 1];
            }
        }

        if ($keepHours < 1) {
            $keepHours = self::DEFAULT_KEEP_HOURS;
        }

        return ['keep-hours' => $keepHours, 'purge' => $purge, 'dedupe' => $dedupe, 'bin-gc' => $binGc];
    }

    private function validateRoot(string $root): string
    {
        $root = \rtrim($root, '/\\');
        if ($root === '' || !\is_dir($root)) {
            return __('编译根目录不存在或不是目录：%{1}', [$root]);
        }

        $real = \realpath($root);
        if (!\is_string($real) || $real === '' || !\is_dir($real)) {
            return __('编译根目录无法解析为真实路径：%{1}', [$root]);
        }

        // Never operate on a bare filesystem root or a path that lost its
        // generated/ ancestry (e.g. a mis-resolved staging override).
        if (!\str_contains(\str_replace('\\', '/', $real), '/generated/')) {
            return __('编译根目录不在 generated/ 下，拒绝操作：%{1}', [$real]);
        }

        return '';
    }

    /**
     * @return array{total_files:int,total_bytes:int,stale_files:int,stale_bytes:int}
     */
    private function scanTree(string $root, int $cutoff): array
    {
        $scan = ['total_files' => 0, 'total_bytes' => 0, 'stale_files' => 0, 'stale_bytes' => 0];
        foreach ($this->iterateEntries($root) as $entry) {
            $size = $entry['size'];
            $scan['total_files']++;
            $scan['total_bytes'] += $size;
            if ($entry['mtime'] < $cutoff) {
                $scan['stale_files']++;
                $scan['stale_bytes'] += $size;
            }
        }

        return $scan;
    }

    /**
     * @return array{files:int,bytes:int,errors:int}
     */
    private function deleteStale(string $root, int $cutoff): array
    {
        $result = ['files' => 0, 'bytes' => 0, 'errors' => 0];
        $directories = [];

        foreach ($this->iterateEntries($root) as $entry) {
            if ($entry['mtime'] >= $cutoff) {
                continue;
            }
            $path = $entry['path'];
            if (!\is_file($path) || \is_link($path)) {
                continue;
            }
            $size = $entry['size'];
            if (@\unlink($path)) {
                $result['files']++;
                $result['bytes'] += $size;
                $directories[\dirname($path)] = true;
            } else {
                $result['errors']++;
            }
        }

        // Remove directories that became empty, deepest first, without ever
        // touching the compile root itself.
        $rootNorm = \rtrim(\str_replace('\\', '/', $root), '/');
        \krsort($directories);
        $remaining = \array_keys($directories);
        while ($remaining !== []) {
            $next = [];
            foreach ($remaining as $directory) {
                $normalized = \rtrim(\str_replace('\\', '/', $directory), '/');
                if ($normalized === $rootNorm || !\str_starts_with($normalized, $rootNorm . '/')) {
                    continue;
                }
                if (!\is_dir($directory) || \is_link($directory)) {
                    continue;
                }
                $entries = @\scandir($directory);
                if (!\is_array($entries) || \count(\array_diff($entries, ['.', '..'])) > 0) {
                    continue;
                }
                if (!@\rmdir($directory)) {
                    $result['errors']++;
                    continue;
                }
                $parent = \dirname($directory);
                if ($parent !== $rootNorm) {
                    $next[$parent] = true;
                }
            }
            $remaining = \array_keys($next);
        }

        return $result;
    }

    /**
     * Yields regular files only, skipping symlinks and anything that resolves
     * outside the compile root.
     *
     * @return \Generator<int, array{path:string,size:int,mtime:int}>
     */
    private function iterateEntries(string $root): \Generator
    {
        $rootReal = \realpath($root);
        if (!\is_string($rootReal) || $rootReal === '') {
            return;
        }
        $rootLen = \strlen(\rtrim($rootReal, '/\\')) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $path = $file->getPathname();
            $real = \realpath($path);
            // Reject a symlinked ancestor masquerading as a compile artifact.
            if (!\is_string($real) || \strlen($real) <= $rootLen
                || !\str_starts_with(\str_replace('\\', '/', $real), \rtrim(\str_replace('\\', '/', $rootReal), '/') . '/')) {
                continue;
            }
            $mtime = @\filemtime($real);
            yield [
                'path' => $real,
                'size' => (int)$file->getSize(),
                'mtime' => $mtime === false ? 0 : (int)$mtime,
            ];
        }
    }

    /**
     * Replaces byte-identical artifacts with hard links to a single canonical
     * file. Lookup paths, filenames and include() semantics are unchanged.
     *
     * Safety: compiled artifacts are only ever written via tempnam()+rename()
     * (Template.php:1489-1494), so a recompile always creates a fresh inode and
     * can never mutate the inode shared by other paths.
     *
     * @return array{unique:int,duplicates:int,linked:int,bytes:int,skipped:int,already:int}
     */
    private function dedupeByContent(string $root, bool $dryRun): array
    {
        $result = ['unique' => 0, 'duplicates' => 0, 'linked' => 0, 'bytes' => 0, 'skipped' => 0, 'already' => 0];
        $canonical = [];
        $errors = 0;

        foreach ($this->iterateEntries($root) as $entry) {
            $path = $entry['path'];
            $identity = $this->identityOf($path);
            if ($identity === null) {
                $result['skipped']++;
                continue;
            }

            // Cheap first pass: an artifact that already shares an inode with
            // another path is deduped, so hashing it again is wasted work and
            // must not be reported as reclaimable space.
            $inoKey = $identity['dev'] . ':' . $identity['ino'];
            if (isset($canonical['ino'][$inoKey])) {
                $result['already']++;
                continue;
            }

            $hash = @\hash_file('sha256', $path);
            if (!\is_string($hash) || $hash === '') {
                $result['skipped']++;
                continue;
            }

            if (!isset($canonical['hash'][$hash])) {
                $canonical['hash'][$hash] = $path;
                $canonical['ino'][$inoKey] = $path;
                $result['unique']++;
                continue;
            }

            $result['duplicates']++;
            $target = $canonical['hash'][$hash];

            // Hard links cannot span filesystems.
            $targetDev = $this->deviceOf($target);
            if ($targetDev === null || $targetDev !== $identity['dev']) {
                $result['skipped']++;
                continue;
            }

            if ($dryRun) {
                $result['bytes'] += $entry['size'];
                continue;
            }

            if ($this->replaceWithHardLink($target, $path)) {
                $result['linked']++;
                $result['bytes'] += $entry['size'];
                // The path now points at the canonical inode, so re-read the
                // identity: the pre-link inode is gone.
                $linkedIdentity = $this->identityOf($path);
                if ($linkedIdentity !== null) {
                    $canonical['ino'][$linkedIdentity['dev'] . ':' . $linkedIdentity['ino']] = $path;
                }
            } else {
                $errors++;
                $result['skipped']++;
            }
        }

        if ($errors > 0) {
            $this->printing->warningIcon(__('%{1} 个副本建立硬链接失败，详见上方日志。', [$errors]));
        }

        return $result;
    }

    /**
     * Atomically replaces $path with a hard link to $canonicalPath.
     *
     * A sibling temp name is linked first and then renamed over the destination,
     * so a failure never leaves a half-linked artifact behind.
     */
    private function replaceWithHardLink(string $canonicalPath, string $path): bool
    {
        $temp = $path . '.ghl-' . \bin2hex(\random_bytes(4));
        if (!@\link($canonicalPath, $temp)) {
            return false;
        }

        // rename() over the existing file drops the old inode while keeping the
        // temp link, so both names end up on the canonical inode.
        if (!@\rename($temp, $path)) {
            @\unlink($temp);

            return false;
        }

        return true;
    }

    private function deviceOf(string $path): ?int
    {
        $stat = @\stat($path);

        return \is_array($stat) && isset($stat['dev']) ? (int)$stat['dev'] : null;
    }

    /**
     * Filesystem identity of a path (device + inode). Two paths sharing this
     * identity are already the same file instance, i.e. deduped.
     *
     * @return array{dev:int,ino:int}|null
     */
    private function identityOf(string $path): ?array
    {
        $stat = @\stat($path);
        if (!\is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            return null;
        }

        return ['dev' => (int)$stat['dev'], 'ino' => (int)$stat['ino']];
    }

    /**
     * Removes stale entries from the shared opcache disk cache.
     *
     * PHP names a cache file `{system_id}/{absolute source path}.bin`, so an
     * entry is orphaned exactly when its source file no longer exists. That
     * happens because the compile_id is part of the compiled-template path: once
     * a template recompiles, the previous generation's `.bin` can never be hit
     * again and would otherwise accumulate forever.
     *
     * Only entries whose source is provably absent are removed; a live source
     * always keeps its cache.
     *
     * @return array{root:string,total_files:int,orphan_files:int,orphan_bytes:int,deleted_files:int,errors:int}
     */
    private function collectOrphanBytecode(bool $dryRun): array
    {
        $result = [
            'root' => '',
            'total_files' => 0,
            'orphan_files' => 0,
            'orphan_bytes' => 0,
            'deleted_files' => 0,
            'errors' => 0,
        ];

        $base = \defined('BP') ? (string)\BP : '';
        if ($base === '') {
            return $result;
        }

        $root = \rtrim($base, '/\\') . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, self::RELATIVE_FILE_CACHE_DIR);
        $result['root'] = $root;

        $real = \realpath($root);
        if (!\is_string($real) || $real === '' || !\is_dir($real)) {
            return $result;
        }

        foreach ($this->iterateBytecodeEntries($real) as $entry) {
            $result['total_files']++;
            if ($entry['source_exists']) {
                continue;
            }

            $result['orphan_files']++;
            $result['orphan_bytes'] += $entry['size'];

            if ($dryRun) {
                continue;
            }

            if (@\unlink($entry['path'])) {
                $result['deleted_files']++;
            } else {
                $result['errors']++;
            }
        }

        return $result;
    }

    /**
     * Yields `{path,size,source_exists}` for every `.bin` under the disk cache.
     *
     * @return \Generator<int, array{path:string,size:int,source_exists:bool}>
     */
    private function iterateBytecodeEntries(string $root): \Generator
    {
        $root = \rtrim($root, '/\\') . \DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            if (\strtolower($file->getExtension()) !== 'bin') {
                continue;
            }

            $path = $file->getPathname();
            $relative = \substr($path, \strlen($root));

            // Strip the leading {system_id}/ segment, then the trailing ".bin".
            $slash = \strpos($relative, \DIRECTORY_SEPARATOR);
            if ($slash === false) {
                continue;
            }
            $source = '/' . \str_replace(\DIRECTORY_SEPARATOR, '/', \substr($relative, $slash + 1, -4));

            yield [
                'path' => $path,
                'size' => (int)$file->getSize(),
                'source_exists' => \is_file($source),
            ];
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = \max($bytes, 0);
        $pow = (int)\floor(($bytes ? \log($bytes) : 0) / \log(1024));
        $pow = (int)\min($pow, \count($units) - 1);

        return \round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('回收生成的模板编译产物。[--keep-hours=N]:保留最近 N 小时（默认 6） [--dedupe]:按内容去重（硬链接） [--bin-gc]:清理孤儿字节码缓存 [--purge]:真正执行，默认仅演练');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '--keep-hours=N' => __('保留最近 N 小时内的产物，默认 6'),
                '--dedupe' => __('内容级去重：相同字节的产物合并为硬链接'),
                '--bin-gc' => __('清理源文件已不存在的 opcache 盘缓存（.bin）'),
                '--purge' => __('执行删除；不加时只打印将要释放的空间'),
            ],
            [],
            []
        );
    }
}
