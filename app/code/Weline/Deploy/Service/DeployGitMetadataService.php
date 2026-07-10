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
        $this->runGitOrFail(['checkout', $tag], 'Git checkout tag failed: %{1}', $root);
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

    public function checkoutBranch(string $branch, ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $this->runGitOrFail(['checkout', $branch], 'Git checkout failed: %{1}', $root);
    }

    public function resetHard(string $branch, string $remote = 'origin', ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $this->runGitOrFail(['reset', '--hard', $remote . '/' . $branch], 'Git reset failed: %{1}', $root);
    }

    public function pullFastForward(string $branch, string $remote = 'origin', ?string $root = null): void
    {
        $root = $this->normalizeRoot($root);
        $remote = trim($remote) !== '' ? trim($remote) : 'origin';
        $this->runGitOrFail(['pull', '--ff-only', $remote, $branch], 'Git pull failed: %{1}', $root);
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
