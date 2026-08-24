<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Enforces the framework repository dev/master delivery contract.
 *
 * Code changes are allowed only on {@see DEVELOPMENT_BRANCH}. {@see RELEASE_BRANCH}
 * is a merge-and-push target, not a development branch.
 */
final class FrameworkBranchGuard
{
    public const DEVELOPMENT_BRANCH = 'dev';
    public const RELEASE_BRANCH = 'master';

    /** @return array{code:string,message:string,details:array<string,mixed>}|null */
    public static function developmentBlocker(string $repositoryRoot): ?array
    {
        $root = realpath(Config::expandPath($repositoryRoot));
        if ($root === false || !is_dir($root)) {
            return null;
        }
        if (!is_dir($root . DIRECTORY_SEPARATOR . '.git')) {
            return null;
        }
        $codeRoot = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code';
        if (!is_dir($codeRoot) || is_link($codeRoot)) {
            return null;
        }
        if (self::git($root, ['rev-parse', '--verify', 'refs/heads/' . self::DEVELOPMENT_BRANCH]) === null) {
            return null;
        }

        $branch = self::git($root, ['symbolic-ref', '--short', '-q', 'HEAD']) ?? '';
        if ($branch === self::DEVELOPMENT_BRANCH) {
            return null;
        }

        $details = [
            'current_branch' => $branch,
            'required_branch' => self::DEVELOPMENT_BRANCH,
            'release_branch' => self::RELEASE_BRANCH,
            'workflow' => 'develop_on_dev_merge_to_master_before_push',
        ];

        if ($branch === self::RELEASE_BRANCH) {
            return [
                'code' => 'GIT_BRANCH_FORBIDDEN',
                'message' => '框架仓禁止在 master 上开发。请先切换到 dev（git switch dev），在 dev 完成修改并提交；'
                    . '需要发布时再合并到 master 并推送。',
                'details' => $details + ['next_action' => 'git switch dev'],
            ];
        }

        if ($branch === '') {
            return [
                'code' => 'GIT_BRANCH_AMBIGUOUS',
                'message' => '当前 Git 未指向 dev 分支（可能处于 detached HEAD）。'
                    . '框架仓只允许在 dev 上开发。',
                'details' => $details + ['next_action' => 'git switch dev'],
            ];
        }

        return [
            'code' => 'GIT_BRANCH_FORBIDDEN',
            'message' => '框架仓只允许在 dev 分支开发，不创建或使用其他分支。请切换到 dev（git switch dev）。',
            'details' => $details + ['next_action' => 'git switch dev'],
        ];
    }

    /** @param list<string> $arguments */
    private static function git(string $cwd, array $arguments): ?string
    {
        $allowed = [
            ['symbolic-ref', '--short', '-q', 'HEAD'],
            ['rev-parse', '--verify', 'refs/heads/dev'],
        ];
        if (!in_array($arguments, $allowed, true)) {
            throw new \RuntimeException('Unsupported Git inspection command');
        }
        $command = array_merge(['git', '-C', $cwd], $arguments);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
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
