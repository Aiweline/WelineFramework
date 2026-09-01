<?php

declare(strict_types=1);

use LearningMcp\GitSafetyPolicy;

$policyFile = dirname(__DIR__) . '/src/GitSafetyPolicy.php';
if (!is_file($policyFile)) {
    fwrite(STDERR, "[FAIL] Git worktree safety policy is missing\n");
    exit(1);
}

require $policyFile;

$cases = [
    'read-only inspection remains allowed' => [
        'argv' => ['git', '-C', '/tmp/repository', 'status', '--porcelain=v1'],
        'allowed' => true,
    ],
    'plain dev switch is forbidden inside MCP' => [
        'argv' => ['git', '-C', '/tmp/repository', 'switch', 'dev'],
        'allowed' => false,
    ],
    'hard reset is forbidden' => [
        'argv' => ['git', '-C', '/tmp/repository', 'reset', '--hard'],
        'allowed' => false,
    ],
    'restore is forbidden' => [
        'argv' => ['git', '-C', '/tmp/repository', 'restore', '.'],
        'allowed' => false,
    ],
    'clean is forbidden' => [
        'argv' => ['git', '-C', '/tmp/repository', 'clean', '-fd'],
        'allowed' => false,
    ],
    'checkout is forbidden' => [
        'argv' => ['git', '-C', '/tmp/repository', 'checkout', '--', 'file.php'],
        'allowed' => false,
    ],
    'stash mutation is forbidden' => [
        'argv' => ['git', '-C', '/tmp/repository', 'stash', 'clear'],
        'allowed' => false,
    ],
    'symbolic-ref mutation is forbidden' => [
        'argv' => ['git', '-C', '/tmp/repository', 'symbolic-ref', 'HEAD', 'refs/heads/master'],
        'allowed' => false,
    ],
    'forced switch is forbidden' => [
        'argv' => ['git', '-C', '/tmp/repository', 'switch', '--discard-changes', 'dev'],
        'allowed' => false,
    ],
    'Git config injection is forbidden' => [
        'argv' => ['git', '-c', 'diff.external=touch /tmp/unsafe', 'diff'],
        'allowed' => false,
    ],
    'pager helper execution is forbidden' => [
        'argv' => ['git', 'grep', '--open-files-in-pager=touch /tmp/unsafe', 'needle'],
        'allowed' => false,
    ],
];

$failed = false;
foreach ($cases as $label => $case) {
    $allowed = true;
    try {
        GitSafetyPolicy::assertNonDestructive($case['argv']);
    } catch (RuntimeException) {
        $allowed = false;
    }
    $passed = $allowed === $case['allowed'];
    fwrite($passed ? STDOUT : STDERR, sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label));
    $failed = $failed || !$passed;
}

$bootstrap = file_get_contents(dirname(__DIR__) . '/scripts/ensure-project-guidance.php');
$bootstrapDoesNotSwitch = is_string($bootstrap)
    && !str_contains($bootstrap, "welineGuidanceExec(['git', '-C', \$repoRoot, 'switch'");
fwrite(
    $bootstrapDoesNotSwitch ? STDOUT : STDERR,
    sprintf("[%s] bootstrap never performs an automatic branch switch\n", $bootstrapDoesNotSwitch ? 'PASS' : 'FAIL'),
);
$failed = $failed || !$bootstrapDoesNotSwitch;

exit($failed ? 1 : 0);
