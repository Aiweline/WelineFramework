<?php

declare(strict_types=1);

use LearningMcp\HardConstraintsCatalog;
use LearningMcp\SystemConfigTermRouting;

require dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;

function configTermCheck(bool $ok, string $label): void
{
    global $failed;
    fwrite(($ok ? STDOUT : STDERR), ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) {
        $failed = true;
    }
}

$needles = SystemConfigTermRouting::pathIntentNeedles();
foreach (['配置', '统一配置', '统一配置中心', '系统配置', '嵌入配置', '配置嵌入', 'config:embed', 'systemconfig'] as $needle) {
    configTermCheck(in_array($needle, $needles, true), 'path-intent needles include ' . $needle);
}

$paths = SystemConfigTermRouting::pathIntentPaths();
foreach (['/weline/systemconfig/', 'systemconfig', 'config-embed', 'config:embed'] as $path) {
    configTermCheck(in_array($path, $paths, true), 'path-intent paths include ' . $path);
}

foreach (['加个配置项', '统一配置中心筛选', '业务页嵌入配置', '用 config:embed 挂字段'] as $task) {
    configTermCheck(SystemConfigTermRouting::matchesConfigIntent($task), 'matchesConfigIntent: ' . $task);
    $expanded = SystemConfigTermRouting::expandTask($task);
    configTermCheck(
        str_contains($expanded, 'Weline_SystemConfig')
        && str_contains($expanded, 'config:embed'),
        'expandTask hits SystemConfig+embed: ' . $task,
    );
}

$roles = SystemConfigTermRouting::contextRoleNeedles();
configTermCheck(in_array('嵌入配置', $roles, true), 'context role needles include 嵌入配置');
configTermCheck(in_array('configuration', SystemConfigTermRouting::contextRoles(), true), 'context roles include configuration');

$hasUnifiedRule = false;
foreach (HardConstraintsCatalog::rules() as $rule) {
    if (($rule['id'] ?? '') === SystemConfigTermRouting::HARD_RULE_ID) {
        $hasUnifiedRule = true;
        $summary = (string) ($rule['summary'] ?? '');
        configTermCheck(
            str_contains($summary, '统一配置')
            && (str_contains($summary, 'config:embed') || str_contains($summary, '嵌入配置')),
            'hard rule summary mentions unified+embed config',
        );
        break;
    }
}
configTermCheck($hasUnifiedRule, 'hard-constraints includes systemconfig_unified_config_terms');

$indexDoc = dirname(__DIR__, 2) . '/doc/AI硬规则索引.md';
$indexBody = is_file($indexDoc) ? (string) file_get_contents($indexDoc) : '';
configTermCheck(
    str_contains($indexBody, '统一配置')
    && str_contains($indexBody, '嵌入配置')
    && str_contains($indexBody, 'systemconfig_unified_config_terms'),
    'AI硬规则索引 routes 配置 vocabulary to SystemConfig',
);

fwrite(STDOUT, $failed ? "FAILED\n" : "OK\n");
exit($failed ? 1 : 0);
