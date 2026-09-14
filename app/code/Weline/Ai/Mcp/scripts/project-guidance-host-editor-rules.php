<?php

declare(strict_types=1);

/**
 * Ensure helper: sync MCP-generated Cursor alwaysApply rules into the repo worktree.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'HostEditorRulesGenerator.php';

/**
 * @return array{
 *   schema_version: string,
 *   ready: bool,
 *   changed: bool,
 *   written: list<string>,
 *   paths: array<string, string>,
 *   reason: string
 * }
 */
function welineGuidanceSyncHostEditorRules(string $repoRoot): array
{
    return \LearningMcp\HostEditorRulesGenerator::syncCursorRules($repoRoot);
}
