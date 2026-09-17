<?php

declare(strict_types=1);

use LearningMcp\HostEditorRulesGenerator;

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/scripts/project-guidance-host-editor-rules.php';

$checks = [];

$mdc = HostEditorRulesGenerator::coldStartMdc();
$checks['coldstart mdc has alwaysApply'] = str_contains($mdc, 'alwaysApply: true');
$checks['coldstart mdc mandates prepare_project'] = str_contains($mdc, 'prepare_project')
    && str_contains($mdc, 'hard_constraints');
$checks['coldstart mdc has generator marker'] = str_contains($mdc, HostEditorRulesGenerator::GENERATOR_MARKER);
$checks['coldstart mdc forbids hand edit'] = str_contains($mdc, '禁止 Agent 手改')
    || str_contains($mdc, 'do not hand-edit');
$checks['coldstart mdc requires re-prepare on context loss'] = str_contains($mdc, '上下文丢失自愈')
    && str_contains($mdc, '重新')
    && str_contains($mdc, 'prepare_project');
$checks['coldstart mdc forbids hand-writing rules to remember guidance'] = str_contains($mdc, '禁止为「记住引导」而手写')
    && str_contains($mdc, '.cursor/rules');

$tmpRoot = sys_get_temp_dir() . '/weline-host-editor-rules-' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0775, true);
$sync1 = HostEditorRulesGenerator::syncCursorRules($tmpRoot);
$coldPath = $sync1['paths']['coldstart'] ?? '';
$checks['sync ready'] = ($sync1['ready'] ?? false) === true;
$checks['sync writes coldstart'] = ($sync1['changed'] ?? false) === true
    && in_array(HostEditorRulesGenerator::COLDSTART_RULE_BASENAME, $sync1['written'] ?? [], true)
    && is_file($coldPath);
$checks['written content matches catalog'] = is_file($coldPath)
    && (string) file_get_contents($coldPath) === $mdc;

$sync2 = welineGuidanceSyncHostEditorRules($tmpRoot);
$checks['helper is idempotent'] = ($sync2['ready'] ?? false) === true
    && ($sync2['changed'] ?? true) === false
    && ($sync2['reason'] ?? '') === 'unchanged';

$ensure = (string) file_get_contents(dirname(__DIR__) . '/scripts/ensure-project-guidance.php');
$checks['ensure requires host-editor-rules script'] = str_contains($ensure, 'project-guidance-host-editor-rules.php')
    && str_contains($ensure, 'welineGuidanceSyncHostEditorRules')
    && str_contains($ensure, 'host_editor_rules');

// cleanup
@unlink($coldPath);
@rmdir(dirname($coldPath));
@rmdir(dirname(dirname($coldPath)));
@rmdir($tmpRoot);

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "host-editor-rules-generator FAILED\n");
    foreach ($failed as $label) {
        fwrite(STDERR, "  - {$label}\n");
    }
    exit(1);
}

echo 'host-editor-rules-generator OK (' . count($checks) . " checks)\n";
exit(0);
