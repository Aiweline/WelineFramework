<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

class DeployGitMetadataService
{
    private string $root;
    private string $gitBinary;

    public function __construct()
    {
        $this->root = BP;
        $this->ensureWindowsCommandPath();
        $this->gitBinary = $this->resolveGitBinary();
    }

    public function getShortCommit(?string $root = null): string
    {
        return $this->readGitOutput(['rev-parse', '--short', 'HEAD'], $root);
    }

    public function getFullCommit(?string $root = null): string
    {
        return $this->readGitOutput(['rev-parse', 'HEAD'], $root);
    }

    public function getCurrentBranch(?string $root = null): string
    {
        $result = $this->readGitOutput(['rev-parse', '--abbrev-ref', 'HEAD'], $root);
        return $result === 'HEAD' ? '' : $result;
    }

    /**
     * 工作区未提交改动文件数（含未跟踪）。
     *
     * 部署版本戳只记录发布时的 commit；用 core:update 同步过的树可能领先 HEAD，
     * 下一次 force-reset 发布会覆盖这些差异 —— 发布前必须能看见。
     */
    public function getDirtyFileCount(?string $root = null): int
    {
        $output = $this->readGitOutput(['status', '--porcelain'], $root);
        if ($output === '') {
            return 0;
        }

        $lines = preg_split('/\R/', $output) ?: [];
        $count = 0;
        foreach ($lines as $line) {
            if (trim((string)$line) !== '') {
                ++$count;
            }
        }

        return $count;
    }

    public function isDetachedHead(?string $root = null): bool
    {
        return $this->getCurrentBranch($root) === '';
    }

    public function fetch(bool $tags = false, string $remote = 'origin', ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $arguments = ['fetch', $remote];
        if ($tags) {
            $arguments[] = '--tags';
        }

        $this->runGitOrFail($arguments, 'Git fetch failed: %{1}', $root);
    }

    public function checkoutTag(string $tag, string $remote = 'origin', ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $this->fetch(true, $remote, $root);
        // Deploy always force-aligns: dirty/untracked worktrees must not block tag releases.
        $this->snapshotDirtyWorktree('checkout_tag:' . $tag, $root);
        $this->runGitOrFail(['checkout', '-f', $tag], 'Git checkout tag failed: %{1}', $root);
    }

    public function checkoutRemoteBranch(string $branch, string $remote = 'origin', ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $this->fetch(false, $remote, $root);
        $this->runGitOrFail(['checkout', '-B', $branch, $remote . '/' . $branch], 'Git checkout branch failed: %{1}', $root);
    }

    public function checkoutCommit(string $commit, string $remote = 'origin', ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $this->fetch(true, $remote, $root);
        $this->runGitOrFail(['checkout', '--detach', $commit], 'Git checkout commit failed: %{1}', $root);
    }

    /**
     * Restore a known local commit without network fetch (failure auto-rollback).
     * When $branch is set, recreates that branch at the commit to avoid detached HEAD.
     */
    public function restoreLocalCommit(string $commit, string $branch = '', ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $commit = trim($commit);
        if ($commit === '') {
            throw new \InvalidArgumentException((string)__('Git restore commit cannot be empty.'));
        }

        $this->snapshotDirtyWorktree('restore_commit:' . $commit, $root);

        $branch = trim($branch);
        if ($branch !== '') {
            $this->runGitOrFail(
                ['checkout', '-B', $branch, $commit],
                'Git restore branch failed: %{1}',
                $root
            );
            return;
        }

        $this->runGitOrFail(
            ['checkout', '--detach', $commit],
            'Git restore commit failed: %{1}',
            $root
        );
    }

    public function checkoutBranch(string $branch, ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $this->runGitOrFail(['checkout', $branch], 'Git checkout failed: %{1}', $root);
    }

    public function resetHard(string $branch, string $remote = 'origin', ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $this->snapshotDirtyWorktree('reset_hard:' . $remote . '/' . $branch, $root);
        $this->runGitOrFail(['reset', '--hard', $remote . '/' . $branch], 'Git reset failed: %{1}', $root);
    }

    public function pullFastForward(string $branch, string $remote = 'origin', ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $this->runGitOrFail(['pull', '--ff-only', $remote, $branch], 'Git pull failed: %{1}', $root);
    }

    /**
     * 破坏性 git 对齐（checkout -f / reset --hard / 自动回滚）前，把当前脏改快照到
     * var/deploy/dirty-snapshots/<时间戳>/，避免覆盖其它会话正在推进的修改。
     * 返回快照目录；工作区干净时返回空串。快照失败必须让发布中止（fail-closed）。
     */
    public function snapshotDirtyWorktree(string $reason = '', ?string $root = null): string
    {
        $root = $this->normalizeRoot($root);
        $statusResult = $this->runGit(['status', '--porcelain=v1', '-z', '--untracked-files=all'], $root);
        if (($statusResult['exit_code'] ?? 1) !== 0) {
            throw new \RuntimeException((string)__(
                'Deploy dirty-worktree snapshot aborted: git status failed: %{1}',
                [trim((string)($statusResult['stderr'] ?? ''))]
            ));
        }
        $status = (string)($statusResult['stdout'] ?? '');
        if ($status === '') {
            return '';
        }

        $stamp = date('Ymd-His') . '-' . substr(sha1($status . microtime(true)), 0, 8);
        $snapshotRoot = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'deploy'
            . DIRECTORY_SEPARATOR . 'dirty-snapshots' . DIRECTORY_SEPARATOR . $stamp;
        if (!is_dir($snapshotRoot) && !@mkdir($snapshotRoot, 0775, true) && !is_dir($snapshotRoot)) {
            throw new \RuntimeException((string)__(
                'Deploy dirty-worktree snapshot aborted: unable to create %{1}',
                [$snapshotRoot]
            ));
        }

        $statusText = str_replace("\0", "\n", $status);
        $entries = array_values(array_filter(explode("\0", $status), static fn(string $value): bool => $value !== ''));
        if (@file_put_contents($snapshotRoot . DIRECTORY_SEPARATOR . 'status.txt', $statusText) === false) {
            throw new \RuntimeException('Deploy dirty-worktree snapshot aborted: unable to write status.txt');
        }

        $diffResult = $this->runGit(['diff', '--binary', 'HEAD'], $root);
        if (($diffResult['exit_code'] ?? 1) !== 0) {
            throw new \RuntimeException((string)__(
                'Deploy dirty-worktree snapshot aborted: git diff failed: %{1}',
                [trim((string)($diffResult['stderr'] ?? ''))]
            ));
        }
        $patch = (string)($diffResult['stdout'] ?? '');
        if (@file_put_contents($snapshotRoot . DIRECTORY_SEPARATOR . 'tracked.patch', $patch) === false) {
            throw new \RuntimeException('Deploy dirty-worktree snapshot aborted: unable to write tracked.patch');
        }

        $untrackedResult = $this->runGit(['ls-files', '--others', '--exclude-standard', '-z'], $root);
        if (($untrackedResult['exit_code'] ?? 1) !== 0) {
            throw new \RuntimeException((string)__(
                'Deploy dirty-worktree snapshot aborted: git ls-files failed: %{1}',
                [trim((string)($untrackedResult['stderr'] ?? ''))]
            ));
        }
        $untracked = (string)($untrackedResult['stdout'] ?? '');
        $untrackedFiles = $untracked === ''
            ? []
            : array_values(array_filter(explode("\0", $untracked), static fn(string $value): bool => $value !== ''));
        $copied = 0;
        foreach ($untrackedFiles as $relativePath) {
            $relativePath = str_replace('\\', '/', $relativePath);
            $segments = array_values(array_filter(explode('/', $relativePath), static fn(string $value): bool => $value !== ''));
            if ($segments === [] || in_array('..', $segments, true)) {
                continue;
            }
            $relativePath = implode('/', $segments);
            $source = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($source)) {
                continue;
            }
            $destination = $snapshotRoot . DIRECTORY_SEPARATOR . 'untracked' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $destinationDir = dirname($destination);
            if (!is_dir($destinationDir) && !@mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
                throw new \RuntimeException((string)__(
                    'Deploy dirty-worktree snapshot aborted: unable to create %{1}',
                    [$destinationDir]
                ));
            }
            if (!@copy($source, $destination)) {
                throw new \RuntimeException((string)__(
                    'Deploy dirty-worktree snapshot aborted: unable to copy %{1}',
                    [$relativePath]
                ));
            }
            ++$copied;
        }

        $manifest = [
            'created_at' => date('c'),
            'reason' => $reason,
            'deploy_root' => $root,
            'dirty_entries' => count($entries),
            'untracked_copied' => $copied,
            'tracked_patch_bytes' => strlen($patch),
        ];
        @file_put_contents(
            $snapshotRoot . DIRECTORY_SEPARATOR . 'manifest.json',
            (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $logLine = '[' . date('c') . '] ' . ($reason !== '' ? $reason : 'deploy') . ' -> ' . $snapshotRoot
            . ' dirty=' . count($entries) . ' untracked=' . $copied . "\n";
        $logDir = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'deploy';
        if (is_dir($logDir) || @mkdir($logDir, 0775, true) || is_dir($logDir)) {
            @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'dirty-snapshot.log', $logLine, FILE_APPEND);
        }

        return $snapshotRoot;
    }

    public function getTagCommit(string $tag, ?string $root = null): string
    {
        return $this->readGitOutput(['rev-parse', $tag], $root);
    }

    /**
     * @return list<string>
     */
    public function listRemoteBranches(string $remote = 'origin', ?string $root = null): array
    {
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $prefix = $remote . '/';
        $raw = $this->readGitOutput(['branch', '-r', '--format=%(refname:short)'], $root);
        if ($raw === '') {
            return [];
        }

        $branches = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, $prefix)) {
                continue;
            }
            $branch = substr($line, strlen($prefix));
            if ($branch === 'HEAD' || str_contains($branch, '->')) {
                continue;
            }
            $branches[] = $branch;
        }

        $branches = array_values(array_unique($branches));
        sort($branches, SORT_NATURAL | SORT_FLAG_CASE);

        return $branches;
    }

    public function getRemoteBranchCommit(string $branch, string $remote = 'origin', ?string $root = null): string
    {
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $branch = trim($branch);
        return $this->readGitOutput(['rev-parse', $remote . '/' . $branch], $root);
    }

    /**
     * Remote branch tip SHA via `git ls-remote` (not local origin/branch tracking).
     * Returns null when the git process fails (caller should fail-open).
     */
    public function listRemoteBranchTip(string $branch, string $remote = 'origin', ?string $root = null): ?string
    {
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $branch = trim($branch);
        if ($branch === '') {
            return null;
        }
        $ref = 'refs/heads/' . $branch;
        try {
            $result = $this->runGit(['ls-remote', $remote, $ref], $root);
        } catch (\Throwable) {
            return null;
        }
        if (($result['exit_code'] ?? 1) !== 0) {
            return null;
        }
        $raw = trim((string)($result['stdout'] ?? ''));
        if ($raw === '') {
            return '';
        }
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            if (trim((string)$parts[1]) !== $ref) {
                continue;
            }
            $sha = strtolower(trim((string)$parts[0]));
            if (preg_match('/^[0-9a-f]{40}$/', $sha) === 1) {
                return $sha;
            }
        }

        return '';
    }

    /**
     * Paths with skip-worktree bit set (`git ls-files -v`, leading S/s).
     *
     * @return list<string>
     */
    public function listSkipWorktreePaths(?string $root = null): array
    {
        $raw = $this->readGitOutput(['ls-files', '-v'], $root);
        if ($raw === '') {
            return [];
        }
        $paths = [];
        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            // Format: "<status><space><path>" — skip-worktree uses S or s.
            $status = $line[0] ?? '';
            if ($status !== 'S' && $status !== 's') {
                continue;
            }
            $path = trim(substr($line, 1));
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return list<array{sha:string,short_sha:string,committed_at:int,author:string,subject:string}>
     */
    public function listCommits(
        string $branch,
        string $remote = 'origin',
        int $limit = 50,
        int $offset = 0,
        ?string $root = null
    ): array {
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $branch = trim($branch);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $ref = $remote . '/' . $branch;
        $format = '%H%x1f%h%x1f%at%x1f%an%x1f%s';
        $raw = $this->readGitOutput([
            'log',
            $ref,
            '--format=' . $format,
            '-n',
            (string)$limit,
            '--skip=' . (string)$offset,
        ], $root);
        if ($raw === '') {
            return [];
        }

        $commits = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode("\x1f", $line);
            if (count($parts) < 5) {
                continue;
            }
            $commits[] = [
                'sha' => strtolower((string)$parts[0]),
                'short_sha' => (string)$parts[1],
                'committed_at' => (int)$parts[2],
                'author' => (string)$parts[3],
                'subject' => (string)$parts[4],
            ];
        }

        return $commits;
    }

    /**
     * Remote tag names via `git ls-remote --tags` (peeled `^{}` rows ignored).
     * Returns null when the git process fails (caller should fail-open).
     *
     * @return list<string>|null
     */
    public function listRemoteTagNames(string $remote = 'origin', ?string $root = null): ?array
    {
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        try {
            $result = $this->runGit(['ls-remote', '--tags', $remote], $root);
        } catch (\Throwable) {
            return null;
        }
        if (($result['exit_code'] ?? 1) !== 0) {
            return null;
        }

        $raw = trim((string)($result['stdout'] ?? ''));
        $names = [];
        if ($raw !== '') {
            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);
                if ($line === '' || str_ends_with($line, '^{}')) {
                    continue;
                }
                // "<sha>\trefs/tags/<name>"
                $parts = preg_split('/\s+/', $line, 2);
                if (!is_array($parts) || count($parts) < 2) {
                    continue;
                }
                $ref = trim((string)$parts[1]);
                if (!str_starts_with($ref, 'refs/tags/')) {
                    continue;
                }
                $name = substr($ref, 10);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }
        $out = array_keys($names);
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * @return list<array{name:string,sha:string,short_sha:string,committed_at:int,subject:string}>
     */
    public function listTags(int $limit = 100, ?string $root = null): array
    {
        $limit = max(1, min(200, $limit));
        $format = '%(refname:short)%x1f%(objectname)%x1f%(objectname:short)%x1f%(creatordate:unix)%x1f%(subject)';
        $raw = $this->readGitOutput([
            'for-each-ref',
            '--sort=-creatordate',
            'refs/tags',
            '--format=' . $format,
            '--count=' . (string)$limit,
        ], $root);
        if ($raw === '') {
            return [];
        }

        $tags = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode("\x1f", $line);
            if (count($parts) < 5) {
                continue;
            }
            $tags[] = [
                'name' => (string)$parts[0],
                'sha' => strtolower((string)$parts[1]),
                'short_sha' => (string)$parts[2],
                'committed_at' => (int)$parts[3],
                'subject' => (string)$parts[4],
            ];
        }

        return $tags;
    }

    public function isAncestor(string $ancestor, string $descendant, ?string $root = null): bool
    {
        $ancestor = strtolower(trim($ancestor));
        $descendant = strtolower(trim($descendant));
        if ($ancestor === '' || $descendant === '' || $ancestor === $descendant) {
            return false;
        }

        try {
            $result = $this->runGit(['merge-base', '--is-ancestor', $ancestor, $descendant], $root);
        } catch (\Throwable) {
            return false;
        }

        return (int)($result['exit_code'] ?? 1) === 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function readGitOutput(array $arguments, ?string $root = null): string
    {
        try {
            $result = $this->runGit($arguments, $root);
        } catch (\Throwable) {
            return '';
        }

        if (($result['exit_code'] ?? 1) !== 0) {
            return '';
        }

        return trim((string)($result['stdout'] ?? ''));
    }

    /**
     * @param list<string> $arguments
     */
    private function runGitOrFail(array $arguments, string $message, ?string $root = null): void
    {
        $result = $this->runGit($arguments, $root);
        if (($result['exit_code'] ?? 1) === 0) {
            return;
        }

        $details = trim((string)($result['stderr'] ?? '') . "\n" . (string)($result['stdout'] ?? ''));
        throw new \RuntimeException((string)__($message, [$details]));
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runGit(array $arguments, ?string $root = null): array
    {
        $root = $this->normalizeRoot($root);
        if (!is_dir($root)) {
            throw new \RuntimeException((string)__('Git working directory does not exist: %{1}', [$root]));
        }

        $command = array_merge([$this->gitBinary], array_map(static fn($argument) => (string)$argument, array_values($arguments)));
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $root,
            $this->processEnvironment(),
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException((string)__('Git process could not be opened.'));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => (int)$exitCode,
            'stdout' => (string)$stdout,
            'stderr' => (string)$stderr,
        ];
    }

    private function resolveGitBinary(): string
    {
        $configured = trim((string)(getenv('WELINE_GIT_BIN') ?: getenv('GIT_BIN') ?: ''), " \t\n\r\0\x0B\"'");
        $candidates = array_filter([
            $configured,
            'C:\\Program Files\\Git\\bin\\git.exe',
            'C:\\Program Files\\Git\\cmd\\git.exe',
            'git',
        ]);

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate, " \t\n\r\0\x0B\"'");
            if ($candidate === '') {
                continue;
            }

            if (str_contains($candidate, '\\') || str_contains($candidate, '/')) {
                if (is_file($candidate)) {
                    return $candidate;
                }
                continue;
            }

            return $candidate;
        }

        return 'git';
    }

    /**
     * @return array<string,string>
     */
    private function processEnvironment(): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $path = (string)(getenv('PATH') ?: getenv('Path') ?: '');
        if ($path !== '') {
            $environment['PATH'] = $path;
            $environment['Path'] = $path;
        }

        return array_map(static fn($value) => (string)$value, $environment);
    }

    private function ensureWindowsCommandPath(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return;
        }

        $current = (string)getenv('PATH');
        $prefixes = [
            'C:\\Windows\\System32',
            'C:\\Program Files\\Git\\bin',
            'C:\\Program Files\\Git\\cmd',
        ];
        $pathParts = explode(PATH_SEPARATOR, $current);
        foreach (array_reverse($prefixes) as $prefix) {
            if (!in_array($prefix, $pathParts, true) && is_dir($prefix)) {
                array_unshift($pathParts, $prefix);
            }
        }
        $path = implode(PATH_SEPARATOR, $pathParts);
        putenv('PATH=' . $path);
        putenv('Path=' . $path);
    }

    private function normalizeRoot(?string $root): string
    {
        $root = trim((string)$root);
        return $root !== '' ? $root : $this->root;
    }
}
