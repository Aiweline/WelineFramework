<?php

declare(strict_types=1);

/**
 * Installer / CLI command runner for deploy-user isolation:
 * - Unix root: re-exec / wrap as WELINE_USER|env.user|weline (runuser/sudo -u).
 * - Windows elevated Administrator or wrong account: run framework commands as the
 *   deploy user at Limited integrity (schtasks / PowerShell credential). Admin may
 *   still drive install.bat for system-level steps; only command ownership is dropped.
 */
final class DeployUserCommandRunner
{
    public static function resolveDeployUser(string $projectRoot): string
    {
        $configured = self::configuredDeployUserOrNull($projectRoot);
        if ($configured !== null) {
            return $configured;
        }

        return 'weline';
    }

    /**
     * Explicit deploy identity from WELINE_USER or env.php user; null when unset.
     */
    public static function configuredDeployUserOrNull(string $projectRoot): ?string
    {
        $fromEnv = \getenv('WELINE_USER');
        if (\is_string($fromEnv)) {
            $trimmed = \trim($fromEnv);
            if ($trimmed !== '' && !self::isForbiddenDeployUserName($trimmed)) {
                return $trimmed;
            }
        }

        $envPhp = $projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        if (\is_file($envPhp)) {
            /** @var mixed $cfg */
            $cfg = include $envPhp;
            if (\is_array($cfg) && isset($cfg['user']) && \is_string($cfg['user'])) {
                $configured = \trim($cfg['user']);
                if ($configured !== '' && !self::isForbiddenDeployUserName($configured)) {
                    return $configured;
                }
            }
        }

        return null;
    }

    public static function currentProcessUserName(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $info = @\posix_getpwuid((int) \posix_geteuid());
            if (\is_array($info) && isset($info['name']) && \is_string($info['name']) && $info['name'] !== '') {
                return $info['name'];
            }
        }
        foreach (['USERNAME', 'USER', 'LOGNAME'] as $key) {
            $value = \getenv($key);
            if (\is_string($value) && \trim($value) !== '') {
                return \trim($value);
            }
        }
        $fallback = \get_current_user();
        return \is_string($fallback) ? $fallback : '';
    }

    /**
     * Privileged identity: Unix euid 0, or Windows elevated High/System IL.
     */
    public static function isElevatedPrivilegedProcess(): bool
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            return true;
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }

        return self::isWindowsElevatedIntegrity();
    }

    /**
     * Unix root should wrap/re-exec as deploy user.
     */
    public static function shouldDropRootPrivileges(string $projectRoot): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return false;
        }
        if (!\function_exists('posix_geteuid') || \posix_geteuid() !== 0) {
            return false;
        }
        $deploy = self::resolveDeployUser($projectRoot);

        return $deploy !== '' && $deploy !== 'root';
    }

    /**
     * Windows: drop when elevated (ACL) or explicit deploy user differs from current.
     */
    public static function shouldDropWindowsPrivileges(string $projectRoot): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }
        if (self::isElevatedPrivilegedProcess()) {
            return true;
        }
        $configured = self::configuredDeployUserOrNull($projectRoot);
        if ($configured === null) {
            return false;
        }
        $current = self::currentProcessUserName();

        return $current === '' || \strcasecmp($current, $configured) !== 0;
    }

    /**
     * Account used for Windows framework-command drop.
     * Explicit WELINE_USER/env.user wins; else current user (de-elevate only).
     */
    public static function resolveWindowsCommandUser(string $projectRoot): string
    {
        $configured = self::configuredDeployUserOrNull($projectRoot);
        if ($configured !== null) {
            return $configured;
        }
        $current = self::currentProcessUserName();

        return $current !== '' ? $current : 'weline';
    }

    public static function wrapShellCommand(string $projectRoot, string $command): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows cannot express credential/Limited drop as a simple prefix string;
            // callers must use runShell/runPhp which invoke runAsDeployUser().
            return $command;
        }
        if (!self::shouldDropRootPrivileges($projectRoot)) {
            return $command;
        }

        $user = self::resolveDeployUser($projectRoot);
        $path = (string) (\getenv('PATH') ?: '');
        $inner = 'cd ' . \escapeshellarg($projectRoot)
            . ' && export PATH=' . \escapeshellarg($path)
            . ' && ' . $command;

        if (self::commandExists('runuser')) {
            return 'runuser -u ' . \escapeshellarg($user)
                . ' -- /bin/bash -lc ' . \escapeshellarg($inner);
        }

        return 'sudo -u ' . \escapeshellarg($user)
            . ' -- /bin/bash -lc ' . \escapeshellarg($inner);
    }

    public static function runPhp(string $projectRoot, string $phpBin, string $args): int
    {
        return self::runShell($projectRoot, \trim($phpBin . ' ' . $args));
    }

    public static function runShell(string $projectRoot, string $command): int
    {
        if (DIRECTORY_SEPARATOR === '\\' && self::shouldDropWindowsPrivileges($projectRoot)) {
            $user = self::resolveWindowsCommandUser($projectRoot);
            echo "Executing as deploy user {$user} (Windows privilege drop): {$command}\n";

            return self::runAsWindowsDeployUser($projectRoot, $user, $command);
        }

        $wrapped = self::wrapShellCommand($projectRoot, $command);
        if ($wrapped !== $command) {
            echo 'Executing as deploy user ' . self::resolveDeployUser($projectRoot) . ": {$command}\n";
        } else {
            echo "Executing command: {$command}\n";
        }
        \passthru($wrapped, $code);

        return (int) $code;
    }

    /**
     * Re-exec current PHP CLI argv as deploy/de-elevated Windows user.
     * @param list<string> $argv
     */
    public static function reexecWindowsCliAsDeployUser(string $projectRoot, array $argv): int
    {
        $php = (\defined('PHP_BINARY') && \PHP_BINARY !== '') ? (string) \PHP_BINARY : 'php';
        $quoted = \array_map(static function (string $part): string {
            return self::windowsQuote($part);
        }, $argv);
        $command = self::windowsQuote($php) . ($quoted !== [] ? ' ' . \implode(' ', $quoted) : '');
        $user = self::resolveWindowsCommandUser($projectRoot);
        echo "CLI elevated/wrong user; re-executing as deploy user {$user}...\n";

        return self::runAsWindowsDeployUser($projectRoot, $user, $command, true);
    }

    /**
     * Build runuser/sudo -u wrapper for a full bash -lc body (Unix only).
     */
    public static function privilegeDropPrefix(string $deployUser): string
    {
        if (self::commandExists('runuser')) {
            return 'runuser -u ' . \escapeshellarg($deployUser) . ' -- /bin/bash -lc ';
        }

        return 'sudo -u ' . \escapeshellarg($deployUser) . ' -- /bin/bash -lc ';
    }

    /**
     * Run a cmd.exe command line as $user at Limited integrity when possible.
     */
    public static function runAsWindowsDeployUser(
        string $projectRoot,
        string $user,
        string $command,
        bool $setCliDeployFlag = false
    ): int {
        $password = \getenv('WELINE_USER_PASSWORD');
        $password = \is_string($password) ? $password : '';
        $current = self::currentProcessUserName();
        $sameUser = $current !== '' && \strcasecmp($current, $user) === 0;

        if ($password !== '') {
            return self::runWindowsViaPowerShellCredential($projectRoot, $user, $password, $command, $setCliDeployFlag);
        }
        if ($sameUser || self::isElevatedPrivilegedProcess()) {
            // Same account elevated → Limited scheduled task (no password).
            // Cross-user without password often fails; still try Limited task as $user.
            return self::runWindowsViaLimitedScheduledTask($projectRoot, $user, $command, $setCliDeployFlag);
        }

        \fwrite(
            \STDERR,
            "ERROR: cannot switch to deploy user '{$user}' from '{$current}' without WELINE_USER_PASSWORD. "
            . "Set WELINE_USER_PASSWORD in the environment/weline.env for non-interactive drop, "
            . "or open a session as '{$user}' before running framework commands.\n"
        );

        return 1;
    }

    private static function runWindowsViaPowerShellCredential(
        string $projectRoot,
        string $user,
        string $password,
        string $command,
        bool $setCliDeployFlag
    ): int {
        $tmp = self::windowsTempDir($projectRoot);
        $stamp = \bin2hex(\random_bytes(8));
        $exitFile = $tmp . DIRECTORY_SEPARATOR . "weline-deploy-exit-{$stamp}.txt";
        $outFile = $tmp . DIRECTORY_SEPARATOR . "weline-deploy-out-{$stamp}.txt";
        $errFile = $tmp . DIRECTORY_SEPARATOR . "weline-deploy-err-{$stamp}.txt";
        $ps1 = $tmp . DIRECTORY_SEPARATOR . "weline-deploy-{$stamp}.ps1";

        $userFq = self::windowsUserForPowerShell($user);
        $innerCmd = ($setCliDeployFlag
                ? 'set WELINE_CLI_AS_DEPLOY_USER=1&& set WELINE_INSTALLER_AS_DEPLOY_USER=1&& '
                : '')
            . 'cd /d ' . $projectRoot . ' && ' . $command;
        $script = '$ErrorActionPreference = \'Stop\'' . "\r\n"
            . '$user = ' . self::psQuote($userFq) . "\r\n"
            . '$pass = ConvertTo-SecureString ' . self::psQuote($password) . ' -AsPlainText -Force' . "\r\n"
            . '$cred = New-Object System.Management.Automation.PSCredential($user, $pass)' . "\r\n"
            . '$p = Start-Process -FilePath \'cmd.exe\' -ArgumentList @(\'/c\', ' . self::psQuote($innerCmd) . ') '
            . '-WorkingDirectory ' . self::psQuote($projectRoot) . ' -Credential $cred -Wait -PassThru -NoNewWindow '
            . '-RedirectStandardOutput ' . self::psQuote($outFile) . ' -RedirectStandardError ' . self::psQuote($errFile) . "\r\n"
            . '$code = if ($null -ne $p -and $null -ne $p.ExitCode) { [int]$p.ExitCode } else { 1 }' . "\r\n"
            . 'Set-Content -Path ' . self::psQuote($exitFile) . ' -Value $code -Encoding ASCII' . "\r\n";

        \file_put_contents($ps1, $script);
        $psCmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . self::windowsQuote($ps1);
        \passthru($psCmd, $psCode);
        $exit = self::readExitFile($exitFile, (int) $psCode);
        self::relayFileToStdout($outFile);
        self::relayFileToStderr($errFile);
        @\unlink($ps1);
        @\unlink($exitFile);
        @\unlink($outFile);
        @\unlink($errFile);

        return $exit;
    }

    private static function runWindowsViaLimitedScheduledTask(
        string $projectRoot,
        string $user,
        string $command,
        bool $setCliDeployFlag
    ): int {
        $tmp = self::windowsTempDir($projectRoot);
        $stamp = \bin2hex(\random_bytes(8));
        $exitFile = $tmp . DIRECTORY_SEPARATOR . "weline-deploy-exit-{$stamp}.txt";
        $outFile = $tmp . DIRECTORY_SEPARATOR . "weline-deploy-out-{$stamp}.txt";
        $errFile = $tmp . DIRECTORY_SEPARATOR . "weline-deploy-err-{$stamp}.txt";
        $wrapper = $tmp . DIRECTORY_SEPARATOR . "weline-deploy-{$stamp}.cmd";
        $taskName = 'WelineDeploy_' . $stamp;

        $lines = [
            '@echo off',
            'cd /d ' . self::windowsQuote($projectRoot),
        ];
        if ($setCliDeployFlag) {
            $lines[] = 'set WELINE_CLI_AS_DEPLOY_USER=1';
            $lines[] = 'set WELINE_INSTALLER_AS_DEPLOY_USER=1';
        }
        $path = (string) (\getenv('PATH') ?: '');
        if ($path !== '') {
            $lines[] = 'set PATH=' . $path;
        }
        $lines[] = $command . ' >' . self::windowsQuote($outFile) . ' 2>' . self::windowsQuote($errFile);
        $lines[] = 'echo %ERRORLEVEL% >' . self::windowsQuote($exitFile);
        \file_put_contents($wrapper, \implode("\r\n", $lines) . "\r\n");

        $create = 'schtasks /Create /TN ' . self::windowsQuote($taskName)
            . ' /TR ' . self::windowsQuote(self::windowsQuote($wrapper))
            . ' /SC ONCE /ST 00:00 /RL LIMITED /F /RU ' . self::windowsQuote($user);
        // When RU is current elevated user, omit /RP; for other users Windows requires password.
        $password = \getenv('WELINE_USER_PASSWORD');
        if (\is_string($password) && $password !== '') {
            $create .= ' /RP ' . self::windowsQuote($password);
        }
        \exec($create . ' 2>&1', $createOut, $createCode);
        if ($createCode !== 0) {
            \fwrite(\STDERR, "ERROR: Windows Limited scheduled task create failed for deploy user '{$user}'.\n");
            \fwrite(\STDERR, \implode("\n", $createOut) . "\n");
            if (!\is_string($password) || $password === '') {
                \fwrite(
                    \STDERR,
                    "Hint: set WELINE_USER_PASSWORD for cross-user drop, or run framework commands while logged on as '{$user}'.\n"
                );
            }
            @\unlink($wrapper);

            return 1;
        }

        \exec('schtasks /Run /TN ' . self::windowsQuote($taskName) . ' 2>&1', $runOut, $runCode);
        $deadline = \time() + 3600;
        while (\time() < $deadline && !\is_file($exitFile)) {
            \usleep(200000);
        }
        $exit = self::readExitFile($exitFile, $runCode !== 0 ? 1 : 0);
        \exec('schtasks /Delete /TN ' . self::windowsQuote($taskName) . ' /F 2>&1');
        self::relayFileToStdout($outFile);
        self::relayFileToStderr($errFile);
        @\unlink($wrapper);
        @\unlink($exitFile);
        @\unlink($outFile);
        @\unlink($errFile);

        return $exit;
    }

    private static function readExitFile(string $exitFile, int $fallback): int
    {
        if (!\is_file($exitFile)) {
            return $fallback;
        }
        $raw = \trim((string) \file_get_contents($exitFile));

        return \is_numeric($raw) ? (int) $raw : $fallback;
    }

    private static function relayFileToStdout(string $file): void
    {
        if (\is_file($file)) {
            $data = (string) \file_get_contents($file);
            if ($data !== '') {
                echo $data;
                if (!\str_ends_with($data, "\n")) {
                    echo "\n";
                }
            }
        }
    }

    private static function relayFileToStderr(string $file): void
    {
        if (\is_file($file)) {
            $data = (string) \file_get_contents($file);
            if ($data !== '') {
                \fwrite(\STDERR, $data);
                if (!\str_ends_with($data, "\n")) {
                    \fwrite(\STDERR, "\n");
                }
            }
        }
    }

    private static function windowsTempDir(string $projectRoot): string
    {
        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        return \is_dir($dir) ? $dir : (\sys_get_temp_dir());
    }

    private static function windowsQuote(string $value): string
    {
        if ($value === '') {
            return '""';
        }
        if (!\preg_match('/[\s"]/', $value)) {
            return $value;
        }

        return '"' . \str_replace('"', '""', $value) . '"';
    }

    private static function psQuote(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }

    private static function windowsUserForPowerShell(string $user): string
    {
        if (\str_contains($user, '\\') || \str_contains($user, '@')) {
            return $user;
        }
        $computer = \getenv('COMPUTERNAME');
        if (\is_string($computer) && $computer !== '') {
            return $computer . '\\' . $user;
        }

        return $user;
    }

    private static function isForbiddenDeployUserName(string $name): bool
    {
        $lower = \strtolower($name);

        return $lower === 'root'
            || $lower === 'administrator'
            || $lower === 'system'
            || $lower === 'localsystem';
    }

    private static function isWindowsElevatedIntegrity(): bool
    {
        $out = [];
        $code = 1;
        @\exec('whoami /groups /fo csv 2>NUL', $out, $code);
        if ($code === 0 && $out !== []) {
            $blob = \strtolower(\implode("\n", $out));

            return \str_contains($blob, 's-1-16-12288')
                || \str_contains($blob, 's-1-16-16384');
        }
        $netCode = 1;
        @\exec('net session >NUL 2>&1', $out, $netCode);

        return $netCode === 0;
    }

    private static function commandExists(string $name): bool
    {
        $out = [];
        $code = 1;
        @\exec('command -v ' . \escapeshellarg($name) . ' 2>/dev/null', $out, $code);

        return $code === 0 && $out !== [];
    }
}
