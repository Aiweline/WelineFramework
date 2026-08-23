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
        $directory = self::absolute($cwd);
        if (!is_dir($directory)) {
            throw new RuntimeException('cwd is not a directory: ' . $directory);
        }

        $identityPath = str_replace('\\', '/', $directory);
        if (PHP_OS_FAMILY === 'Windows') {
            $identityPath = strtolower($identityPath);
        }
        $rootFingerprint = Ids::hash($identityPath);
        $projectId = 'dir:' . $rootFingerprint;

        // VCS metadata remains available for diagnostics and guarded edits, but
        // it never changes the project boundary or identity.
        $branch = self::git($directory, ['symbolic-ref', '--short', '-q', 'HEAD']) ?? '';
        $head = self::git($directory, ['rev-parse', '--verify', 'HEAD']) ?? '';
        $defaultBranch = self::git($directory, ['symbolic-ref', '--short', '-q', 'refs/remotes/origin/HEAD']) ?? '';
        $defaultBranch = preg_replace('~^origin/~', '', $defaultBranch) ?? $defaultBranch;
        $dirty = false;
        if ($includeDirty) {
            $status = self::git($directory, ['status', '--porcelain=v1', '--untracked-files=normal']);
            $dirty = $status !== null && trim($status) !== '';
        }
        $now = Clock::now();

        return [
            'project' => [
                'id' => $projectId,
                'name' => basename($directory) ?: $directory,
                'root_fingerprint' => $rootFingerprint,
                'remote_fingerprint' => '',
                'default_branch' => $defaultBranch,
                'config' => ['identity_strategy' => 'canonical_directory'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'repository' => $directory,
            'identity_strategy' => 'canonical_directory',
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

}
