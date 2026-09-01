<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;

/**
 * Bounds MCP index/refresh/GC work to one attached project repository.
 *
 * Cursor registration sets LEARNING_MCP_BOUND_REPOSITORY to the framework
 * checkout that owns this MCP package. Empty bound keeps tests/unbound CLI.
 */
final class RepositoryScope
{
    /**
     * Per-project MCP data directory outside the repository.
     * Shape: ~/.learning-mcp/projects/<sha256(canonical_repository)>.
     */
    public static function projectDataDir(string $repository, ?string $home = null): string
    {
        self::assertFilesystemRepository($repository);
        $home = $home ?? HomeDirectory::resolve();
        if (trim($home) === '') {
            throw new RuntimeException('HOME is required to resolve a project MCP data directory');
        }
        $root = realpath(Config::expandPath($repository));
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Repository does not exist: ' . $repository);
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower(str_replace('\\', '/', $root));
        }

        return rtrim($home, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '.learning-mcp'
            . DIRECTORY_SEPARATOR . 'projects'
            . DIRECTORY_SEPARATOR . hash('sha256', $root);
    }

    public static function boundRepository(Config $config): ?string
    {
        $configured = trim((string) $config->get('index.bound_repository', ''));
        $environment = getenv('LEARNING_MCP_BOUND_REPOSITORY');
        if (is_string($environment) && trim($environment) !== '') {
            $configured = trim($environment);
        }
        if ($configured === '') {
            return null;
        }
        $path = realpath(Config::expandPath($configured));
        if ($path === false || !is_dir($path)) {
            throw new RuntimeException('index.bound_repository does not exist: ' . $configured);
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    public static function isAllowed(Config $config, string $repository): bool
    {
        try {
            self::assertAllowed($config, $repository);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function assertAllowed(Config $config, string $repository): void
    {
        self::assertFilesystemRepository($repository);
        $bound = self::boundRepository($config);
        if ($bound === null) {
            return;
        }
        $root = realpath(Config::expandPath($repository));
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Repository does not exist: ' . $repository);
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if (!hash_equals($bound, $root)) {
            throw new RuntimeException(
                'Repository is outside the bound MCP project scope: ' . $root
                . ' (bound=' . $bound . ')'
            );
        }
    }

    public static function boundGeneration(Config $config): ?string
    {
        $bound = self::boundRepository($config);
        if ($bound === null) {
            return null;
        }
        $resolved = ProjectResolver::resolve($bound, false);
        $projectId = trim((string) ($resolved['project']['id'] ?? ''));
        $root = rtrim((string) $resolved['repository'], DIRECTORY_SEPARATOR);
        if ($projectId === '') {
            $projectId = 'dir:sha256:' . hash('sha256', $root);
        }

        return hash('sha256', $projectId . "\0" . $root);
    }

    /**
     * Reject http(s) URLs or other non-path strings mistaken for repository roots.
     */
    public static function assertFilesystemRepository(string $repository): void
    {
        $trimmed = trim($repository);
        if ($trimmed === '') {
            throw new RuntimeException('Repository path is empty');
        }
        if (preg_match('#^https?://#i', $trimmed) === 1) {
            throw new RuntimeException(
                'Repository must be a filesystem path, not a URL: ' . $trimmed,
            );
        }
        if (str_contains($trimmed, '://')) {
            throw new RuntimeException(
                'Repository must be a filesystem path, not a URI scheme: ' . $trimmed,
            );
        }
    }
}
