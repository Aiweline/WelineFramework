<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;

final class ProjectResolver
{
    /** @return array<string, mixed> */
    public static function resolve(string $cwd, bool $includeDirty = false): array
    {
        if (trim($cwd) === '') {
            throw new RuntimeException('cwd is required');
        }
        $absolute = self::absolute($cwd);
        if (!is_dir($absolute)) {
            throw new RuntimeException('cwd is not a directory: ' . $absolute);
        }
        $repository = self::git($absolute, ['rev-parse', '--show-toplevel']) ?? $absolute;
        $repository = realpath($repository) ?: $repository;
        $remote = self::git($repository, ['config', '--get', 'remote.origin.url']) ?? '';
        $branch = self::git($repository, ['symbolic-ref', '--short', '-q', 'HEAD']) ?? '';
        $head = self::git($repository, ['rev-parse', '--verify', 'HEAD']) ?? '';
        $defaultBranch = self::git($repository, ['symbolic-ref', '--short', '-q', 'refs/remotes/origin/HEAD']) ?? '';
        $defaultBranch = preg_replace('~^origin/~', '', $defaultBranch) ?? $defaultBranch;
        $dirty = false;
        if ($includeDirty) {
            $status = self::git($repository, ['status', '--porcelain=v1', '--untracked-files=normal']);
            $dirty = $status !== null && trim($status) !== '';
        }
        $normalizedRemote = self::normalizeRemote($remote);
        $rootFingerprint = Ids::hash($repository);
        $remoteFingerprint = $normalizedRemote === '' ? '' : Ids::hash($normalizedRemote);
        $projectId = 'repo:' . Ids::hash($normalizedRemote . "\n" . $rootFingerprint);
        $now = Clock::now();

        return [
            'project' => [
                'id' => $projectId,
                'name' => basename($repository),
                'root_fingerprint' => $rootFingerprint,
                'remote_fingerprint' => $remoteFingerprint,
                'default_branch' => $defaultBranch,
                'config' => [],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'repository' => $repository,
            'branch' => $branch,
            'default_branch' => $defaultBranch,
            'head_commit' => $head,
            'dirty' => $dirty,
        ];
    }

    private static function absolute(string $path): string
    {
        $path = Config::expandPath($path);
        return realpath($path) ?: $path;
    }

    /** @param list<string> $arguments */
    private static function git(string $cwd, array $arguments): ?string
    {
        $allowed = [
            ['rev-parse', '--show-toplevel'],
            ['config', '--get', 'remote.origin.url'],
            ['symbolic-ref', '--short', '-q', 'HEAD'],
            ['rev-parse', '--verify', 'HEAD'],
            ['symbolic-ref', '--short', '-q', 'refs/remotes/origin/HEAD'],
            ['status', '--porcelain=v1', '--untracked-files=normal'],
        ];
        if (!in_array($arguments, $allowed, true)) {
            throw new RuntimeException('Unsupported Git inspection command');
        }
        $command = array_merge(['git', '-C', $cwd], $arguments);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // The executable/subcommands are allowlisted above and proc_open receives an argv array, never a shell string.
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]); // nosemgrep: php.lang.security.exec-use.exec-use
        if (!is_resource($process)) {
            return null;
        }
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || $output === false) {
            return null;
        }

        return trim($output);
    }

    private static function normalizeRemote(string $remote): string
    {
        $remote = trim($remote);
        if ($remote === '') {
            return '';
        }
        if (str_contains($remote, '://')) {
            $parts = parse_url($remote);
            if (is_array($parts) && isset($parts['host'])) {
                $path = trim((string) ($parts['path'] ?? ''), '/');
                $path = preg_replace('/\.git$/i', '', $path) ?? $path;
                return strtolower($parts['host'] . '/' . $path);
            }
        }
        $at = strrpos($remote, '@');
        if ($at !== false) {
            $remote = substr($remote, $at + 1);
        }
        $remote = preg_replace('/:/', '/', $remote, 1) ?? $remote;
        $remote = trim($remote, '/');
        $remote = preg_replace('/\.git$/i', '', $remote) ?? $remote;

        return strtolower($remote);
    }
}
