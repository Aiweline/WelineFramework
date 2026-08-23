<?php

declare(strict_types=1);

use LearningMcp\ProjectResolver;

require dirname(__DIR__) . '/src/bootstrap.php';

$defaults = [
    '/Users/weline/Project/Official/框架',
    '/Users/weline/Project/Official/Weline-Codex-Mcp',
    '/Users/weline/Project/Official/Official-Site',
];
$arguments = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $argument): bool => $argument !== '' && $argument[0] !== '-',
));
$directories = $arguments !== [] ? $arguments : $defaults;
$reports = [];
$ids = [];
$failed = false;

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        fwrite(STDERR, "[FAIL] missing real project directory: $directory
");
        $failed = true;
        continue;
    }
    try {
        $resolved = ProjectResolver::resolve($directory, false);
        $projectId = (string) ($resolved['project']['id'] ?? '');
        $canonical = realpath($directory) ?: $directory;
        $passed = $projectId !== ''
            && (string) ($resolved['repository'] ?? '') === $canonical
            && (string) ($resolved['identity_strategy'] ?? '') === 'canonical_directory'
            && !isset($ids[$projectId]);
        if (!$passed) {
            $failed = true;
        }
        $ids[$projectId] = $canonical;
        $reports[] = [
            'project' => basename($canonical),
            'directory' => $canonical,
            'project_id' => $projectId,
            'identity_strategy' => $resolved['identity_strategy'] ?? '',
            'git_head_available' => (string) ($resolved['head_commit'] ?? '') !== '',
            'passed' => $passed,
        ];
        fwrite($passed ? STDOUT : STDERR, sprintf(
            "[%s] %s -> %s
",
            $passed ? 'PASS' : 'FAIL',
            $canonical,
            $projectId,
        ));
    } catch (Throwable $exception) {
        $failed = true;
        fwrite(STDERR, '[FAIL] ' . $directory . ': ' . $exception->getMessage() . "
");
    }
}

if (count($reports) < 3) {
    $failed = true;
    fwrite(STDERR, "[FAIL] at least three real project directories are required
");
}
if (count($ids) !== count($reports)) {
    $failed = true;
    fwrite(STDERR, "[FAIL] canonical directories did not produce isolated project IDs
");
}

fwrite(STDOUT, json_encode([
    'schema_version' => 'weline-real-project-acceptance.v1',
    'scenario_coverage' => [
        'normal_multi_file' => 'covered by tests/run.php execution-run fixture',
        'large_symbol_edit' => 'covered by exact-region and run-bound edit-plan fixture',
        'post_apply_impact_expansion' => 'covered by IMPACT_EXPANSION execution-run fixture',
        'directory_identity' => 'verified against the real directories below',
    ],
    'project_count' => count($reports),
    'isolated_project_id_count' => count($ids),
    'projects' => $reports,
    'passed' => !$failed,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "
");

exit($failed ? 1 : 0);