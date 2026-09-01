<?php

declare(strict_types=1);

/**
 * Lightweight contract checks for DeployUserCommandRunner (no root/admin required).
 * Run: php setup/server_installer/tests/deploy-user-command-runner.php
 */

$root = dirname(__DIR__, 3);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'DeployUserCommandRunner.php';

$failures = 0;
$assert = static function (bool $ok, string $msg) use (&$failures): void {
    if ($ok) {
        echo "OK  {$msg}\n";
        return;
    }
    ++$failures;
    echo "FAIL {$msg}\n";
};

putenv('WELINE_USER=install-test-user');
$assert(
    DeployUserCommandRunner::resolveDeployUser($root) === 'install-test-user',
    'resolveDeployUser prefers WELINE_USER'
);
$assert(
    DeployUserCommandRunner::configuredDeployUserOrNull($root) === 'install-test-user',
    'configuredDeployUserOrNull reads WELINE_USER'
);
putenv('WELINE_USER=');
putenv('WELINE_USER');

$wrapped = DeployUserCommandRunner::wrapShellCommand($root, 'echo ok');
if (DeployUserCommandRunner::shouldDropRootPrivileges($root)) {
    $assert(
        str_contains($wrapped, 'bash -lc') && ($wrapped !== 'echo ok'),
        'root wraps shell command'
    );
} else {
    $assert($wrapped === 'echo ok', 'non-root leaves shell command unchanged');
}

$user = DeployUserCommandRunner::currentProcessUserName();
$assert($user !== '', 'currentProcessUserName returns non-empty');

$prefix = DeployUserCommandRunner::privilegeDropPrefix('weline');
$assert(
    str_starts_with($prefix, 'runuser -u ') || str_starts_with($prefix, 'sudo -u '),
    'privilegeDropPrefix uses runuser or sudo'
);

if (DIRECTORY_SEPARATOR !== '\\') {
    $assert(
        DeployUserCommandRunner::shouldDropWindowsPrivileges($root) === false,
        'non-Windows does not use Windows drop gate'
    );
} else {
    putenv('WELINE_USER=OtherDeployUser');
    $assert(
        DeployUserCommandRunner::resolveWindowsCommandUser($root) === 'OtherDeployUser',
        'Windows command user prefers WELINE_USER'
    );
    $assert(
        DeployUserCommandRunner::shouldDropWindowsPrivileges($root) === true
            || strcasecmp(DeployUserCommandRunner::currentProcessUserName(), 'OtherDeployUser') === 0,
        'Windows drops when WELINE_USER mismatches (unless already that user and non-elevated)'
    );
    putenv('WELINE_USER=');
    putenv('WELINE_USER');
}

$assert(
    method_exists(DeployUserCommandRunner::class, 'runAsWindowsDeployUser'),
    'Windows deploy runner method exists for command-level drop'
);
$assert(
    !method_exists(DeployUserCommandRunner::class, 'shouldRefuseElevatedWindowsInstaller'),
    'hard refuse elevated Windows installer API removed'
);

if ($failures > 0) {
    fwrite(STDERR, "deploy-user-command-runner: {$failures} failure(s)\n");
    exit(1);
}
echo "deploy-user-command-runner: all checks passed\n";
exit(0);
