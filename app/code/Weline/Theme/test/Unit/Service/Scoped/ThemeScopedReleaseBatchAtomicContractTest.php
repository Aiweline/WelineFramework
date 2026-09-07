<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

final class ThemeScopedReleaseBatchAtomicContractTest extends TestCase
{
    public function testWorkspaceExposesOneCoordinatorOwnedAtomicBatchCommit(): void
    {
        $root = \dirname(__DIR__, 4);
        $interface = (string)\file_get_contents($root . '/Api/Scoped/ThemeScopedWorkspaceInterface.php');
        $workspace = (string)\file_get_contents($root . '/Service/Scoped/ThemeScopedWorkspace.php');

        self::assertStringContainsString('public function publishBatch(', $interface);
        self::assertStringContainsString('public function publishBatch(', $workspace);
        self::assertStringContainsString('prepareReleaseBatchItem', $workspace);
        self::assertStringContainsString('applyPreparedReleaseBatchItem', $workspace);
        self::assertStringContainsString('freezeDescendantWorkspaces', $workspace);
        self::assertStringContainsString('propagateFrozenDescendantsInTransaction', $workspace);
        self::assertStringContainsString('ThemeScopeReleaseBatch::STATE_PREPARING', $workspace);
        self::assertStringContainsString('ThemeScopeReleaseBatch::STATE_PUBLISHED', $workspace);
    }

    public function testBatchReceiptContainsEveryAuditField(): void
    {
        $workspace = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/Scoped/ThemeScopedWorkspace.php'
        );

        foreach ([
            "'batch_id'",
            "'resource_type'",
            "'release_id'",
            "'revision_id'",
            "'parent_release_id'",
            "'fingerprint'",
            "'scope'",
            "'actor_id'",
            "'committed_at'",
        ] as $field) {
            self::assertStringContainsString($field, $workspace, $field);
        }
    }
}
