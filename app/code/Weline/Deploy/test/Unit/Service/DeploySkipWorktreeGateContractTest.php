<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * skip-worktree fail-closed gate before git fetch/reset/checkout.
 */
final class DeploySkipWorktreeGateContractTest extends TestCase
{
    public function testGitMetadataAndOrchestratorWireSkipWorktreeGate(): void
    {
        $base = dirname(__DIR__, 3);
        $git = (string)file_get_contents($base . '/Service/DeployGitMetadataService.php');
        $orch = (string)file_get_contents($base . '/Service/DeployOrchestratorService.php');
        $notifier = (string)file_get_contents($base . '/Service/DeployReleaseNotifier.php');

        self::assertStringContainsString('function listSkipWorktreePaths', $git);
        self::assertStringContainsString("ls-files', '-v'", $git);

        self::assertStringContainsString('assertNoSkipWorktree', $orch);
        self::assertStringContainsString('listSkipWorktreePaths', $orch);
        self::assertStringContainsString('PHASE_WORKDIR_BLOCKED', $orch);
        // Must appear in both release and rollback git paths.
        self::assertSame(2, substr_count($orch, 'assertNoSkipWorktree($deployRoot'));
        // Must NOT auto-clear skip-worktree (no update-index write path).
        self::assertStringNotContainsString("update-index', '--no-skip-worktree'", $orch);
        self::assertStringNotContainsString("update-index', '--no-skip-worktree'", $git);
        self::assertStringNotContainsString('runGit([\'update-index\'', $orch);

        self::assertStringContainsString("PHASE_WORKDIR_BLOCKED = 'workdir_blocked'", $notifier);
        self::assertStringContainsString("self::PHASE_WORKDIR_BLOCKED => 'error'", $notifier);
        // Non-terminal: failed still fires after throw.
        self::assertStringNotContainsString('self::PHASE_WORKDIR_BLOCKED,', $notifier);
    }
}
