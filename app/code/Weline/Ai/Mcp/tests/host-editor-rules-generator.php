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
$checks['coldstart mdc points host_codex_delegation'] = str_contains($mdc, 'host_codex_delegation')
    && str_contains($mdc, 'host_delegate_explore_plan_review_to_codex_cli');
$checks['coldstart mdc delegates explore plan review to Codex'] = str_contains($mdc, '委派探索')
    && str_contains($mdc, 'Plan Mode 只承载 Codex 计划')
    && str_contains($mdc, '只按计划编码');
$checks['coldstart mdc requires Codex-working user-visible announce'] = str_contains($mdc, 'Codex 正在工作')
    && str_contains($mdc, '禁止静默委派');
$checks['coldstart mdc forbids nested Codex recursion'] = str_contains($mdc, '禁止嵌套再调')
    || str_contains($mdc, 'Codex 原生宿主禁止嵌套');
$checks['coldstart mdc notes content-ops exemption and CLI fallback'] = str_contains($mdc, '内容运营')
    && str_contains($mdc, 'CLI 不可用才回退');

$tmpRoot = sys_get_temp_dir() . '/weline-host-editor-rules-' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0775, true);
$mcpRoot = dirname(__DIR__);
$sync1 = HostEditorRulesGenerator::syncCursorRulesAndHooks($tmpRoot, $mcpRoot, \LearningMcp\Config::defaultPath());
$coldPath = $sync1['paths']['coldstart'] ?? '';
$hooksPath = $sync1['paths']['hooks'] ?? '';
$checks['sync ready'] = ($sync1['ready'] ?? false) === true;
$checks['sync writes coldstart'] = ($sync1['changed'] ?? false) === true
    && in_array(HostEditorRulesGenerator::COLDSTART_RULE_BASENAME, $sync1['written'] ?? [], true)
    && is_file($coldPath);
$checks['sync writes hooks'] = in_array('hooks.json', $sync1['written'] ?? [], true)
    && is_file($hooksPath);
$checks['written content matches catalog'] = is_file($coldPath)
    && (string) file_get_contents($coldPath) === $mdc;

$sync2 = welineGuidanceSyncHostEditorRules($tmpRoot);
$checks['helper is idempotent'] = ($sync2['ready'] ?? false) === true
    && ($sync2['changed'] ?? true) === false;

$ensure = (string) file_get_contents(dirname(__DIR__) . '/scripts/ensure-project-guidance.php');
$checks['ensure requires host-editor-rules script'] = str_contains($ensure, 'project-guidance-host-editor-rules.php')
    && str_contains($ensure, 'welineGuidanceSyncHostEditorRules')
    && str_contains($ensure, 'host_editor_rules');

// cleanup
@unlink($coldPath);
@unlink($hooksPath);
@rmdir(dirname($coldPath));
@rmdir(dirname($hooksPath));
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
