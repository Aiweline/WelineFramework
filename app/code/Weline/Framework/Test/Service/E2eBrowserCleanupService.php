<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Service;

/**
 * Reap orphaned Playwright / MCP automation browsers after e2e or Agent browser work.
 *
 * Delegates to tests/e2e/framework/cleanup-orphaned-browsers.js so Node and PHP share one policy.
 */
final class E2eBrowserCleanupService
{
    /**
     * @param array{dry_run?:bool,close_acceptance_tabs?:bool} $options
     * @return array{
     *   ok: bool,
     *   dryRun: bool,
     *   closeAcceptanceTabs: bool,
     *   killedPids: list<int>,
     *   closedTabs: int,
     *   removedPaths: list<string>,
     *   skipped: list<string>,
     *   notes: list<string>,
     *   raw?: string,
     *   error?: string
     * }
     */
    public function cleanup(array $options = []): array
    {
        $script = BP . 'tests' . DIRECTORY_SEPARATOR . 'e2e' . DIRECTORY_SEPARATOR
            . 'framework' . DIRECTORY_SEPARATOR . 'cleanup-orphaned-browsers.js';
        if (!is_file($script)) {
            return [
                'ok' => false,
                'dryRun' => !empty($options['dry_run']),
                'closeAcceptanceTabs' => !empty($options['close_acceptance_tabs']),
                'killedPids' => [],
                'closedTabs' => 0,
                'removedPaths' => [],
                'skipped' => [],
                'notes' => [],
                'error' => 'cleanup script missing: ' . $script,
            ];
        }

        $node = $this->resolveNodeBinary();
        $args = [$script, '--json'];
        if (!empty($options['dry_run'])) {
            $args[] = '--dry-run';
        }
        if (!empty($options['close_acceptance_tabs'])) {
            $args[] = '--close-acceptance-tabs';
        }

        $command = escapeshellarg($node);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $output = [];
        $exitCode = 1;
        exec($command . ' 2>&1', $output, $exitCode);
        $raw = trim(implode("\n", $output));
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'dryRun' => !empty($options['dry_run']),
                'closeAcceptanceTabs' => !empty($options['close_acceptance_tabs']),
                'killedPids' => [],
                'closedTabs' => 0,
                'removedPaths' => [],
                'skipped' => [],
                'notes' => [],
                'raw' => $raw,
                'error' => 'cleanup script returned non-JSON (exit=' . $exitCode . ')',
            ];
        }

        return [
            'ok' => $exitCode === 0,
            'dryRun' => (bool)($decoded['dryRun'] ?? !empty($options['dry_run'])),
            'closeAcceptanceTabs' => (bool)($decoded['closeAcceptanceTabs'] ?? !empty($options['close_acceptance_tabs'])),
            'killedPids' => array_values(array_map('intval', is_array($decoded['killedPids'] ?? null) ? $decoded['killedPids'] : [])),
            'closedTabs' => (int)($decoded['closedTabs'] ?? 0),
            'removedPaths' => array_values(array_map('strval', is_array($decoded['removedPaths'] ?? null) ? $decoded['removedPaths'] : [])),
            'skipped' => array_values(array_map('strval', is_array($decoded['skipped'] ?? null) ? $decoded['skipped'] : [])),
            'notes' => array_values(array_map('strval', is_array($decoded['notes'] ?? null) ? $decoded['notes'] : [])),
            'raw' => $raw,
        ];
    }

    private function resolveNodeBinary(): string
    {
        $candidates = ['node'];
        if (PHP_OS_FAMILY !== 'Windows') {
            $candidates = ['/opt/homebrew/bin/node', '/usr/local/bin/node', 'node'];
        }
        foreach ($candidates as $candidate) {
            if ($candidate === 'node') {
                return 'node';
            }
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'node';
    }
}
