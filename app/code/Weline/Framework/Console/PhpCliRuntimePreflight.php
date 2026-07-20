<?php
declare(strict_types=1);

namespace Weline\Framework\Console;

/**
 * Dependency-free PHP CLI safety preflight.
 *
 * It is loaded directly by bin/w and bin/m before app/bootstrap.php, so it must
 * not depend on autoloading, framework services, translations, or generated code.
 */
final class PhpCliRuntimePreflight
{
    public const PROFILE = 'windows-arm64-x64-cli-safe-v2';
    public const ENV_PROFILE = 'WELINE_PHP_CLI_RUNTIME_PROFILE';
    public const ENV_WLS_PROFILE = 'WLS_PHP_RUNTIME_SAFETY_PROFILE';
    public const ENV_DEPTH = 'WELINE_PHP_CLI_RUNTIME_REEXEC_DEPTH';

    private const MANAGED_INI_FILE = '99-weline-windows-arm64-x64-cli-safe.ini';

    /** @var array<string, string> */
    private static array $persistentDriveRoots = [];

    public static function enforce(array $argv, string $entryScript, string $projectRoot): ?int
    {
        $projectRoot = self::resolveWindowsPersistentPath($projectRoot);
        $entryScript = self::resolveWindowsPersistentPath($entryScript);
        $profile = self::applyForDescendants($projectRoot);
        if (empty($profile['requires_jit_isolation'])) {
            return null;
        }

        $managedIni = (string)($profile['managed_ini'] ?? '');
        if (self::isEffectiveSafe($managedIni)) {
            return null;
        }

        $depth = \max(0, (int)(\getenv(self::ENV_DEPTH) ?: 0));
        if ($depth >= 1) {
            throw new \RuntimeException(
                'Windows ARM64/x64 PHP CLI safety relaunch did not activate the managed OPcache profile.'
            );
        }
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException(
                'proc_open is required to relaunch the Windows ARM64/x64 PHP CLI with a safe runtime profile.'
            );
        }

        self::publishEnvironment(self::ENV_DEPTH, (string)($depth + 1));

        $entry = self::resolveWindowsPersistentPath(\realpath($entryScript) ?: $entryScript);
        if ($entry === '' || !\is_file($entry)) {
            throw new \RuntimeException('Weline CLI entry script is unavailable for runtime safety relaunch.');
        }

        $command = [
            \PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            '-d',
            'opcache.jit=off',
            '-d',
            'opcache.jit_buffer_size=0',
            $entry,
        ];
        foreach (\array_slice($argv, 1) as $argument) {
            $command[] = (string)$argument;
        }

        $childCwd = \str_starts_with($projectRoot, '\\\\')
            ? (\sys_get_temp_dir() ?: 'C:\\Windows\\Temp')
            : (\getcwd() ?: $projectRoot);
        self::publishEnvironment('WELINE_START_PROCESS_CWD', $projectRoot);
        $process = @\proc_open(
            $command,
            [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR],
            $pipes,
            $childCwd,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to relaunch Weline CLI with the managed PHP runtime safety profile.');
        }

        $exitCode = @\proc_close($process);

        return \is_int($exitCode) && $exitCode >= 0 && $exitCode <= 255 ? $exitCode : 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function applyForDescendants(string $projectRoot): array
    {
        $projectRoot = self::resolveWindowsPersistentPath($projectRoot);
        $detected = self::inspect();
        $jitBefore = (string)\ini_get('opcache.jit');
        $opcacheCliBefore = (string)\ini_get('opcache.enable_cli');

        if (empty($detected['requires_jit_isolation'])) {
            return \array_merge($detected, [
                'applied' => false,
                'jit_before' => $jitBefore,
                'jit_after' => $jitBefore,
                'opcache_cli_before' => $opcacheCliBefore,
                'opcache_cli_after' => $opcacheCliBefore,
                'managed_ini' => '',
                'php_ini_scan_dir' => (string)(\getenv('PHP_INI_SCAN_DIR') ?: ''),
            ]);
        }

        if (self::isJitEnabled()) {
            @\ini_set('opcache.jit', 'off');
            if (self::isJitEnabled()) {
                throw new \RuntimeException(
                    'Unable to disable OPcache JIT in the current Windows ARM64/x64 PHP process.'
                );
            }
        }

        $managedIni = self::ensureManagedIni($projectRoot);
        $scanDir = self::appendManagedScanDirectory(\dirname($managedIni));
        self::publishEnvironment(self::ENV_PROFILE, self::PROFILE);
        self::publishEnvironment(self::ENV_WLS_PROFILE, self::PROFILE);

        return \array_merge($detected, [
            'applied' => true,
            'jit_before' => $jitBefore,
            'jit_after' => (string)\ini_get('opcache.jit'),
            'opcache_cli_before' => $opcacheCliBefore,
            'opcache_cli_after' => (string)\ini_get('opcache.enable_cli'),
            'managed_ini' => $managedIni,
            'php_ini_scan_dir' => $scanDir,
        ]);
    }

    /**
     * @return array{
     *     profile:string,
     *     requires_jit_isolation:bool,
     *     os_architecture:string,
     *     php_architecture:string,
     *     opcache_cli_enabled:bool,
     *     jit_enabled:bool,
     *     jit_buffer_size:string
     * }
     */
    public static function inspect(): array
    {
        $osArchitecture = self::resolveWindowsOsArchitecture();
        $phpArchitecture = self::normalizeArchitecture((string)\php_uname('m'));
        $requiresIsolation = \PHP_OS_FAMILY === 'Windows'
            && self::isArm64Architecture($osArchitecture)
            && self::isX64Architecture($phpArchitecture);

        return [
            'profile' => $requiresIsolation ? self::PROFILE : 'native',
            'requires_jit_isolation' => $requiresIsolation,
            'os_architecture' => $osArchitecture,
            'php_architecture' => $phpArchitecture,
            'opcache_cli_enabled' => self::isIniEnabled((string)\ini_get('opcache.enable_cli')),
            'jit_enabled' => self::isJitEnabled(),
            'jit_buffer_size' => (string)\ini_get('opcache.jit_buffer_size'),
        ];
    }

    public static function isEffectiveSafe(string $managedIni): bool
    {
        if ($managedIni === '' || !self::isManagedIniScanned($managedIni)) {
            return false;
        }

        $opcacheLoaded = \extension_loaded('Zend OPcache') || \function_exists('opcache_get_status');
        if (!$opcacheLoaded) {
            return true;
        }

        return !self::isIniEnabled((string)\ini_get('opcache.enable_cli'))
            && !self::isJitEnabled()
            && self::isZeroSize((string)\ini_get('opcache.jit_buffer_size'));
    }

    public static function isJitEnabled(): bool
    {
        return self::isIniEnabled((string)\ini_get('opcache.jit'));
    }

    private static function resolveWindowsOsArchitecture(): string
    {
        $architectures = [
            (string)(\getenv('PROCESSOR_ARCHITECTURE') ?: ''),
            (string)(\getenv('PROCESSOR_ARCHITEW6432') ?: ''),
        ];

        foreach ($architectures as $architecture) {
            $normalized = self::normalizeArchitecture($architecture);
            if (self::isArm64Architecture($normalized)) {
                return $normalized;
            }
        }

        $processorIdentifier = (string)(\getenv('PROCESSOR_IDENTIFIER') ?: '');
        if ($processorIdentifier !== ''
            && \preg_match('/(?:ARM64|AARCH64|ARMV8)/i', $processorIdentifier) === 1
        ) {
            return 'ARM64';
        }

        foreach ($architectures as $architecture) {
            $normalized = self::normalizeArchitecture($architecture);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return self::normalizeArchitecture((string)\php_uname('m'));
    }

    private static function ensureManagedIni(string $projectRoot): string
    {
        $root = \rtrim($projectRoot, '/\\');
        if ($root === '') {
            throw new \RuntimeException('Weline project root is empty while preparing the PHP CLI safety profile.');
        }

        $directory = $root . \DIRECTORY_SEPARATOR . 'var'
            . \DIRECTORY_SEPARATOR . 'runtime'
            . \DIRECTORY_SEPARATOR . 'php-ini'
            . \DIRECTORY_SEPARATOR . self::PROFILE;

        if (!\is_dir($directory) && !@\mkdir($directory, 0775, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create the managed PHP CLI safety directory: ' . $directory);
        }

        $iniFile = $directory . \DIRECTORY_SEPARATOR . self::MANAGED_INI_FILE;
        foreach ((array)(\glob($directory . \DIRECTORY_SEPARATOR . '*.ini') ?: []) as $candidate) {
            if (self::normalizePathForComparison((string)$candidate) !== self::normalizePathForComparison($iniFile)) {
                throw new \RuntimeException('Unexpected ini file in the managed PHP CLI safety directory: ' . $candidate);
            }
        }

        $content = "; Managed by Weline for Windows ARM64 running emulated x64 PHP.\r\n"
            . "opcache.enable_cli=0\r\n"
            . "opcache.jit=off\r\n"
            . "opcache.jit_buffer_size=0\r\n";

        if (\is_file($iniFile)) {
            if ((string)@\file_get_contents($iniFile) !== $content) {
                throw new \RuntimeException('Managed PHP CLI safety file has unexpected content: ' . $iniFile);
            }
            return $iniFile;
        }

        $temporary = $iniFile . '.' . (string)(\getmypid() ?: 0) . '.'
            . \str_replace('.', '', \uniqid('', true)) . '.tmp';
        if (@\file_put_contents($temporary, $content, \LOCK_EX) !== \strlen($content)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to write the managed PHP CLI safety file: ' . $iniFile);
        }

        if (!@\rename($temporary, $iniFile)) {
            if (\is_file($iniFile) && (string)@\file_get_contents($iniFile) === $content) {
                @\unlink($temporary);
                return $iniFile;
            }
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish the managed PHP CLI safety file: ' . $iniFile);
        }

        return $iniFile;
    }

    private static function appendManagedScanDirectory(string $managedDirectory): string
    {
        $current = \getenv('PHP_INI_SCAN_DIR');
        $segments = $current === false || \trim((string)$current) === ''
            ? ['']
            : \explode(\PATH_SEPARATOR, (string)$current);
        $managedKey = self::normalizePathForComparison($managedDirectory);

        foreach ($segments as $segment) {
            if (self::normalizePathForComparison($segment) === $managedKey) {
                $scanDir = \implode(\PATH_SEPARATOR, $segments);
                self::publishEnvironment('PHP_INI_SCAN_DIR', $scanDir);
                return $scanDir;
            }
        }

        $segments[] = $managedDirectory;
        $scanDir = \implode(\PATH_SEPARATOR, $segments);
        self::publishEnvironment('PHP_INI_SCAN_DIR', $scanDir);
        return $scanDir;
    }

    private static function isManagedIniScanned(string $managedIni): bool
    {
        $expected = self::normalizePathForComparison(\realpath($managedIni) ?: $managedIni);
        $scanned = (string)(\php_ini_scanned_files() ?: '');
        foreach ((array)\preg_split('/,\s*/', $scanned, -1, \PREG_SPLIT_NO_EMPTY) as $file) {
            $candidate = \trim((string)$file, " \t\n\r\0\x0B\"");
            if (self::normalizePathForComparison(\realpath($candidate) ?: $candidate) === $expected) {
                return true;
            }
        }
        return false;
    }

    private static function isIniEnabled(string $value): bool
    {
        $normalized = \strtolower(\trim($value));
        return !\in_array($normalized, ['', '0', 'off', 'disable', 'disabled', 'false', 'no'], true);
    }

    private static function isZeroSize(string $value): bool
    {
        $normalized = \strtolower(\trim($value));
        return $normalized === '' || \preg_match('/^0+(?:\.0+)?[kmg]?$/', $normalized) === 1;
    }

    private static function resolveWindowsPersistentPath(string $path): string
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return $path;
        }

        $trimmed = \trim($path);
        if ($trimmed === '' || \str_starts_with($trimmed, '\\\\')) {
            return $path;
        }
        if (\preg_match('/^([a-z]):(?:(?:[\\\\\/])(.*))?$/i', $trimmed, $matches) !== 1) {
            return $path;
        }

        $drive = \strtoupper((string)$matches[1]);
        $root = self::resolveWindowsPersistentDriveRoot($drive);
        if ($root === '') {
            return $path;
        }

        $tail = \substr($trimmed, 2);
        if ($tail === '') {
            return $root;
        }

        return \rtrim($root, '\\\\/') . '\\' . \ltrim(\str_replace('/', '\\', $tail), '\\');
    }

    private static function resolveWindowsPersistentDriveRoot(string $drive): string
    {
        if (\array_key_exists($drive, self::$persistentDriveRoots)) {
            return self::$persistentDriveRoots[$drive];
        }

        $inheritedDrive = \strtoupper(\trim((string)\getenv('WELINE_PERSISTENT_PROJECT_DRIVE')));
        $inheritedRoot = \trim((string)\getenv('WELINE_PERSISTENT_PROJECT_ROOT'));
        if ($inheritedDrive === $drive . ':' && \str_starts_with($inheritedRoot, '\\\\')) {
            return self::$persistentDriveRoots[$drive] = \rtrim($inheritedRoot, '\\\\/');
        }

        $systemRoot = \rtrim((string)(\getenv('SystemRoot') ?: 'C:\\Windows'), '\\\\/');
        $powerShell = $systemRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!\is_file($powerShell)) {
            $powerShell = 'powershell.exe';
        }
        $command = '$welineDrive = Get-PSDrive -PSProvider FileSystem -Name '
            . "'" . $drive . "'"
            . ' -ErrorAction SilentlyContinue; '
            . 'if ($null -ne $welineDrive -and $null -ne $welineDrive.DisplayRoot) '
            . '{ [Console]::Out.Write([string]$welineDrive.DisplayRoot) }';
        $process = @\proc_open(
            [$powerShell, '-NoProfile', '-NonInteractive', '-Command', $command],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \sys_get_temp_dir() ?: null,
            null,
            ['bypass_shell' => true]
        );
        if (!\is_resource($process)) {
            return self::$persistentDriveRoots[$drive] = '';
        }

        if (isset($pipes[0])) {
            @\fclose($pipes[0]);
        }
        if (isset($pipes[1])) {
            @\stream_set_blocking($pipes[1], false);
        }
        if (isset($pipes[2])) {
            @\stream_set_blocking($pipes[2], false);
        }

        $output = '';
        $status = null;
        $deadline = \microtime(true) + 2.0;
        do {
            if (isset($pipes[1])) {
                $chunk = @\fread($pipes[1], 4096);
                if (\is_string($chunk) && $chunk !== '') {
                    $output .= $chunk;
                }
            }
            $status = @\proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                break;
            }
            \usleep(10_000);
        } while (\microtime(true) < $deadline);

        if (isset($pipes[1])) {
            $chunk = @\stream_get_contents($pipes[1]);
            if (\is_string($chunk) && $chunk !== '') {
                $output .= $chunk;
            }
            @\fclose($pipes[1]);
        }
        if (isset($pipes[2])) {
            @\fclose($pipes[2]);
        }
        if (($status['running'] ?? false) === true) {
            @\proc_terminate($process);
        } else {
            @\proc_close($process);
        }

        $root = \trim(\preg_replace('/^\\xEF\\xBB\\xBF/', '', $output) ?? $output);
        if (!\str_starts_with($root, '\\\\')) {
            return self::$persistentDriveRoots[$drive] = '';
        }

        $root = \rtrim($root, '\\\\/');
        self::$persistentDriveRoots[$drive] = $root;
        self::publishEnvironment('WELINE_PERSISTENT_PROJECT_DRIVE', $drive . ':');
        self::publishEnvironment('WELINE_PERSISTENT_PROJECT_ROOT', $root);

        return $root;
    }

    private static function normalizeArchitecture(string $architecture): string
    {
        return (string)\preg_replace('/[^A-Z0-9]/', '', \strtoupper(\trim($architecture)));
    }

    private static function isArm64Architecture(string $architecture): bool
    {
        return \in_array($architecture, ['ARM64', 'AARCH64'], true);
    }

    private static function isX64Architecture(string $architecture): bool
    {
        return \in_array($architecture, ['AMD64', 'X8664', 'X64'], true);
    }

    private static function normalizePathForComparison(string $path): string
    {
        $normalized = \rtrim(\str_replace('\\', '/', \trim($path)), '/');
        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($normalized) : $normalized;
    }

    private static function publishEnvironment(string $name, string $value): void
    {
        if (!@\putenv($name . '=' . $value)) {
            throw new \RuntimeException('Unable to publish PHP CLI runtime safety environment: ' . $name);
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
