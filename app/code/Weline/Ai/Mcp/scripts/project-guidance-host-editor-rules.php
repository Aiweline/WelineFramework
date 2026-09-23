<?php

declare(strict_types=1);

/**
 * Ensure helper: sync MCP-generated Cursor alwaysApply rules into the repo worktree.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Support.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ProjectResolver.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'HostEditorRulesGenerator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'HostCursorHooksGenerator.php';

/**
 * @return array<string, mixed>
 */
function welineGuidanceSyncHostEditorRules(string $repoRoot): array
{
    $mcpRoot = dirname(__DIR__);
    $configPath = \LearningMcp\Config::defaultPath();

    return \LearningMcp\HostEditorRulesGenerator::syncCursorRulesAndHooks($repoRoot, $mcpRoot, $configPath);
}
