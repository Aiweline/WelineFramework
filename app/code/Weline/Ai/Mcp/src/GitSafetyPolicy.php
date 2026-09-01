<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;

/**
 * Fail-closed Git boundary for MCP-owned child processes.
 *
 * MCP may inspect Git state, but it never mutates Git metadata, the index, or
 * the worktree. Branch switching remains an explicit workspace-owner action:
 * even an ordinary switch cannot absolutely protect ignored files or a
 * concurrent filesystem writer.
 */
final class GitSafetyPolicy
{
    /** @var array<string, true> */
    private const READ_ONLY_COMMANDS = [
        'diff' => true,
        'grep' => true,
        'log' => true,
        'ls-files' => true,
        'rev-parse' => true,
        'show' => true,
        'show-ref' => true,
        'status' => true,
        'version' => true,
    ];

    /** @param list<string> $argv */
    public static function assertNonDestructive(array $argv): void
    {
        if ($argv === [] || !self::isGitExecutable((string) $argv[0])) {
            return;
        }

        [$subcommand, $arguments] = self::splitCommand($argv);
        if ($subcommand === 'symbolic-ref'
            && in_array($arguments, [
                ['--short', '-q', 'HEAD'],
                ['--short', '-q', 'refs/remotes/origin/HEAD'],
            ], true)) {
            return;
        }
        if (isset(self::READ_ONLY_COMMANDS[$subcommand])) {
            self::assertReadOnlyArguments($arguments);
            return;
        }

        throw new RuntimeException(
            'MCP_WORKTREE_MUTATION_FORBIDDEN: MCP must preserve all dirty tracked, staged, and untracked changes',
        );
    }

    private static function isGitExecutable(string $program): bool
    {
        $name = strtolower(basename(str_replace('\\', '/', $program)));

        return $name === 'git' || $name === 'git.exe';
    }

    /**
     * @param list<string> $argv
     * @return array{0:string,1:list<string>}
     */
    private static function splitCommand(array $argv): array
    {
        $count = count($argv);
        for ($index = 1; $index < $count; $index++) {
            $argument = (string) $argv[$index];
            if ($argument === '-C') {
                if (!isset($argv[$index + 1])) {
                    throw new RuntimeException('MCP_WORKTREE_MUTATION_FORBIDDEN: incomplete Git global option');
                }
                $index++;
                continue;
            }
            if (in_array($argument, [
                '--no-pager', '--literal-pathspecs', '--no-literal-pathspecs',
                '--glob-pathspecs', '--noglob-pathspecs', '--icase-pathspecs', '--no-replace-objects',
            ], true)) {
                continue;
            }
            if ($argument === '--version') {
                return ['version', []];
            }
            if (str_starts_with($argument, '-')) {
                throw new RuntimeException('MCP_WORKTREE_MUTATION_FORBIDDEN: unsupported Git global option');
            }

            return [strtolower($argument), array_values(array_slice($argv, $index + 1))];
        }

        throw new RuntimeException('MCP_WORKTREE_MUTATION_FORBIDDEN: Git subcommand is missing');
    }

    /** @param list<string> $arguments */
    private static function assertReadOnlyArguments(array $arguments): void
    {
        foreach ($arguments as $argument) {
            $normalized = strtolower((string) $argument);
            if ($normalized === '--output'
                || str_starts_with($normalized, '--output=')
                || $normalized === '--ext-diff'
                || $normalized === '--textconv'
                || str_starts_with($normalized, '--exec=')
                || $normalized === '--open-files-in-pager'
                || str_starts_with($normalized, '--open-files-in-pager=')
                || preg_match('/^-o(?:.+)?/D', $normalized) === 1) {
                throw new RuntimeException(
                    'MCP_WORKTREE_MUTATION_FORBIDDEN: Git inspection cannot write output or invoke helpers',
                );
            }
        }
    }
}
