<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\App\Env;

/** Project-owned capability token for the loopback Nginx backend ingress. */
final class GatewayBackendIngressTokenStore
{
    private const TOKEN_MAX_BYTES = 128;
    private const STATE_LOCK_FILE = '.state.lock';
    private const STATE_OPERATION_BUDGET_SECONDS = 5.0;

    public static function runtimeDirectory(string $instanceName): string
    {
        $instanceName = self::normalizeInstanceName($instanceName);
        return Env::VAR_DIR . 'server' . DS . 'gateway-backend' . DS . $instanceName;
    }

    public static function tokenFile(string $instanceName): string
    {
        return self::runtimeDirectory($instanceName) . DS . 'ingress.token';
    }

    public static function ensureTokenFile(
        string $instanceName,
        ?float $deadlineMonotonic = null,
    ): string {
        $deadlineMonotonic = self::stateOperationDeadline($deadlineMonotonic);
        $instanceName = self::normalizeInstanceName($instanceName);
        $directory = self::runtimeDirectory($instanceName);
        self::ensurePrivateDirectory($directory);
        $path = self::tokenFile($instanceName);
        return self::withStateLocks(
            $instanceName,
            static function () use (
                $instanceName,
                $path,
                $deadlineMonotonic,
            ): string {
                self::stateDeadlineRemaining($deadlineMonotonic);
                self::cleanupTokenRecoveryBackups($instanceName, $path);
                $contents = GatewayProjectStateFilesystem::readOptional(
                    $path,
                    self::TOKEN_MAX_BYTES,
                    'WLS gateway backend ingress token',
                );
                if ($contents !== null) {
                    self::validateTokenContents($contents);
                }
                if ($contents === null) {
                    $contents = \bin2hex(\random_bytes(32)) . "\n";
                    self::writeAtomicallyLocked($path, $contents, 0600);
                }
                self::assertProtectedTokenFile($path);
                return $path;
            },
            $deadlineMonotonic,
        );
    }

    public static function readToken(
        string $instanceName,
        ?float $deadlineMonotonic = null,
    ): string {
        $deadlineMonotonic = self::stateOperationDeadline($deadlineMonotonic);
        $path = self::ensureTokenFile($instanceName, $deadlineMonotonic);
        self::stateDeadlineRemaining($deadlineMonotonic);
        $token = \strtolower(\trim(GatewayProjectStateFilesystem::read(
            $path,
            self::TOKEN_MAX_BYTES,
            'WLS gateway backend ingress token',
        )));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new \RuntimeException(
                'WLS gateway backend ingress token file is invalid.',
            );
        }
        return $token;
    }

    /** @return array{path:string,token:string} */
    public static function resolveConfiguredTokenFile(
        string $gatewayBackendTokenFile,
    ): array {
        $gatewayBackendTokenFile = self::normalizeConfiguredPath(
            $gatewayBackendTokenFile,
            'WLS gateway backend token file',
        );
        if ($gatewayBackendTokenFile === '') {
            return ['path' => '', 'token' => ''];
        }
        return [
            'path' => $gatewayBackendTokenFile,
            'token' => self::readConfiguredTokenFile($gatewayBackendTokenFile),
        ];
    }

    /** @return array{path:string,token:string} */
    public static function resolveConfiguredTokenEnvironment(): array
    {
        return self::resolveConfiguredTokenFile(
            self::configuredEnvironmentPath('WLS_GATEWAY_BACKEND_TOKEN_FILE'),
        );
    }

    public static function digest(
        string $instanceName,
        ?float $deadlineMonotonic = null,
    ): string {
        return \hash('sha256', self::readToken($instanceName, $deadlineMonotonic));
    }

    private static function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException(
                'WLS gateway backend ingress instance identity is invalid.',
            );
        }
        return $instanceName;
    }

    private static function ensurePrivateDirectory(string $directory): void
    {
        self::assertSafeRuntimeTarget($directory, true);
        if (!\is_dir($directory)
            && !@\mkdir($directory, 0700, true)
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Unable to create WLS gateway backend ingress runtime directory.',
            );
        }
        self::preserveProjectRuntimeOwnership($directory, true);
        @\chmod($directory, 0700);
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || (PHP_OS_FAMILY !== 'Windows'
                && (((int)($status['mode'] ?? 0)) & 0777) !== 0700)
            || !\is_writable($directory)
        ) {
            throw new \RuntimeException(
                'WLS gateway backend ingress runtime directory is not protected and writable.',
            );
        }
    }

    private static function writeAtomicallyLocked(
        string $path,
        string $contents,
        int $mode,
    ): void {
        self::assertSafeRuntimeTarget($path, false);
        if ($contents === '' || \strlen($contents) > self::TOKEN_MAX_BYTES) {
            throw new \RuntimeException(
                'WLS gateway backend ingress token exceeds its fixed size contract.',
            );
        }
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            $contents,
            $mode,
            self::projectRuntimeOwnershipSeal(),
        );
    }

    /** @template TResult @param \Closure():TResult $operation @return TResult */
    private static function withStateLocks(
        string $instanceName,
        \Closure $operation,
        ?float $deadlineMonotonic = null,
    ): mixed {
        $deadlineMonotonic = self::stateOperationDeadline($deadlineMonotonic);
        $instanceName = self::normalizeInstanceName($instanceName);
        $directory = self::runtimeDirectory($instanceName);
        self::ensurePrivateDirectory($directory);
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . self::STATE_LOCK_FILE,
            static function () use ($operation, $deadlineMonotonic): mixed {
                self::stateDeadlineRemaining($deadlineMonotonic);
                return $operation();
            },
            self::projectRuntimeOwnershipSeal(),
            waitTimeoutSeconds: self::stateLockWaitTimeout($deadlineMonotonic),
            deadlineMonotonic: $deadlineMonotonic,
        );
    }

    private static function stateLockWaitTimeout(float $deadlineMonotonic): float
    {
        return \min(0.25, self::stateDeadlineRemaining($deadlineMonotonic));
    }

    private static function stateDeadlineRemaining(float $deadlineMonotonic): float
    {
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException(
                'WLS gateway backend ingress state deadline is invalid.',
            );
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException(
                'WLS gateway backend ingress state deadline was exhausted.',
            );
        }
        return $remaining;
    }

    private static function stateOperationDeadline(?float $deadlineMonotonic): float
    {
        $now = \hrtime(true) / 1_000_000_000;
        if ($deadlineMonotonic === null) {
            return $now + self::STATE_OPERATION_BUDGET_SECONDS;
        }
        if (!\is_finite($deadlineMonotonic) || $deadlineMonotonic <= $now) {
            throw new \RuntimeException(
                'WLS gateway backend ingress state deadline was exhausted.',
            );
        }
        return $deadlineMonotonic;
    }

    private static function cleanupTokenRecoveryBackups(
        string $instanceName,
        string $path,
    ): void {
        self::assertRuntimeStateTarget($instanceName, $path);
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $path,
            self::TOKEN_MAX_BYTES,
            'WLS gateway backend ingress token',
            static function (string $contents): void {
                self::validateTokenContents($contents);
            },
        );
    }

    private static function validateTokenContents(string $contents): void
    {
        if (\preg_match('/\A[a-f0-9]{64}(?:\r?\n)?\z/D', $contents) !== 1) {
            throw new \RuntimeException(
                'WLS gateway backend ingress token recovery target is corrupt.',
            );
        }
    }

    private static function assertRuntimeStateTarget(
        string $instanceName,
        string $path,
    ): void {
        $instanceName = self::normalizeInstanceName($instanceName);
        self::assertSafeRuntimeTarget($path, false);
        $candidate = \str_replace('\\', '/', \rtrim($path, '/\\'));
        $expected = \str_replace(
            '\\',
            '/',
            \rtrim(self::tokenFile($instanceName), '/\\'),
        );
        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = \strtolower($candidate);
            $expected = \strtolower($expected);
        }
        if (!\hash_equals($expected, $candidate)) {
            throw new \RuntimeException(
                'WLS gateway backend ingress token escapes its exact instance namespace.',
            );
        }
    }

    private static function assertProtectedTokenFile(string $path): void
    {
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (PHP_OS_FAMILY !== 'Windows'
                && (((int)($status['mode'] ?? 0)) & 0777) !== 0600)
        ) {
            throw new \RuntimeException(
                'WLS gateway backend ingress token must be one protected regular file.',
            );
        }
    }

    private static function readConfiguredTokenFile(string $path): string
    {
        self::assertSafeRuntimeTarget($path, false);
        self::assertProtectedTokenFile($path);
        $contents = GatewayProjectStateFilesystem::read(
            $path,
            self::TOKEN_MAX_BYTES,
            'Configured WLS gateway backend ingress token',
        );
        self::validateTokenContents($contents);
        return \strtolower(\trim($contents));
    }

    private static function normalizeConfiguredPath(string $path, string $label): string
    {
        $path = \trim($path);
        if ($path === '') {
            return '';
        }
        if (\str_contains($path, "\0")) {
            throw new \RuntimeException($label . ' contains a null byte.');
        }
        return $path;
    }

    private static function configuredEnvironmentPath(string $name): string
    {
        $values = [];
        foreach ([
            $_SERVER[$name] ?? null,
            $_ENV[$name] ?? null,
            \getenv($name),
        ] as $value) {
            if (!\is_string($value) || \trim($value) === '') {
                continue;
            }
            $value = self::normalizeConfiguredPath($value, $name);
            $identity = PHP_OS_FAMILY === 'Windows'
                ? \strtolower(\str_replace('\\', '/', $value))
                : \str_replace('\\', '/', $value);
            $values[$identity] = $value;
        }
        if (\count($values) > 1) {
            throw new \RuntimeException($name . ' has conflicting environment values.');
        }
        return $values === [] ? '' : (string)\reset($values);
    }

    /** @return (\Closure(resource,string):void)|null */
    private static function projectRuntimeOwnershipSeal(): ?\Closure
    {
        if (PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || (int)\posix_geteuid() !== 0
        ) {
            return null;
        }
        $projectOwner = @\lstat((string)BP);
        if (!\is_array($projectOwner)
            || !\is_int($projectOwner['uid'] ?? null)
            || !\is_int($projectOwner['gid'] ?? null)
        ) {
            throw new \RuntimeException(
                'Unable to resolve the project owner for gateway backend ingress state.',
            );
        }
        $uid = (int)$projectOwner['uid'];
        $gid = (int)$projectOwner['gid'];
        return static function ($handle, string $stagingPath) use ($uid, $gid): void {
            if (!\function_exists('fchown')
                || !\function_exists('fchgrp')
                || !@\fchown($handle, $uid)
                || !@\fchgrp($handle, $gid)
            ) {
                throw new \RuntimeException(
                    'Unable to preserve the project owner on gateway backend ingress state: '
                        . $stagingPath,
                );
            }
        };
    }

    private static function assertSafeRuntimeTarget(string $path, bool $directory): void
    {
        $path = \rtrim($path, '/\\');
        $projectRoot = \realpath((string)BP);
        if (!\is_string($projectRoot)
            || $projectRoot === ''
            || \is_link((string)BP)
        ) {
            throw new \RuntimeException(
                'WLS gateway backend ingress project root is unsafe.',
            );
        }
        $projectRoot = \rtrim($projectRoot, '/\\');
        $candidate = \str_replace('\\', '/', $path);
        $project = \str_replace('\\', '/', $projectRoot);
        $compareCandidate = PHP_OS_FAMILY === 'Windows'
            ? \strtolower($candidate)
            : $candidate;
        $compareProject = PHP_OS_FAMILY === 'Windows'
            ? \strtolower($project)
            : $project;
        $runtimeRoot = \str_replace(
            '\\',
            '/',
            \rtrim(Env::VAR_DIR . 'server' . DS . 'gateway-backend', '/\\'),
        );
        $runtimeRoot = PHP_OS_FAMILY === 'Windows'
            ? \strtolower($runtimeRoot)
            : $runtimeRoot;
        if (!\str_starts_with($compareCandidate, $compareProject . '/')
            || !\str_starts_with($compareCandidate, $runtimeRoot . '/')
        ) {
            throw new \RuntimeException(
                'WLS gateway backend ingress target escapes its project runtime root.',
            );
        }
        $relative = \substr($candidate, \strlen($project) + 1);
        $segments = \explode('/', $relative);
        $cursor = $projectRoot;
        foreach ($segments as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || \str_contains($segment, "\0")
            ) {
                throw new \RuntimeException(
                    'WLS gateway backend ingress target contains traversal.',
                );
            }
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (\is_link($cursor)) {
                throw new \RuntimeException(
                    'WLS gateway backend ingress target must not cross a symbolic link.',
                );
            }
        }
        if (\file_exists($path)
            && ($directory ? !\is_dir($path) : !\is_file($path))
        ) {
            throw new \RuntimeException(
                'WLS gateway backend ingress target has an unexpected filesystem type.',
            );
        }
    }

    private static function preserveProjectRuntimeOwnership(
        string $path,
        bool $directory,
    ): void {
        self::assertSafeRuntimeTarget($path, $directory);
        if (PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $projectOwner = @\lstat((string)BP);
        $target = @\lstat($path);
        if (!\is_array($projectOwner)
            || !\is_int($projectOwner['uid'] ?? null)
            || !\is_int($projectOwner['gid'] ?? null)
            || !\is_array($target)
            || \is_link($path)
            || ($directory ? !\is_dir($path) : !\is_file($path))
        ) {
            throw new \RuntimeException(
                'Unable to establish safe gateway backend ingress ownership.',
            );
        }
        $uid = (int)$projectOwner['uid'];
        $gid = (int)$projectOwner['gid'];
        $ownerApplied = \function_exists('lchown')
            ? @\lchown($path, $uid)
            : @\chown($path, $uid);
        $groupApplied = \function_exists('lchgrp')
            ? @\lchgrp($path, $gid)
            : @\chgrp($path, $gid);
        $actual = @\lstat($path);
        if (!$ownerApplied
            || !$groupApplied
            || !\is_array($actual)
            || (int)($actual['uid'] ?? -1) !== $uid
            || (int)($actual['gid'] ?? -1) !== $gid
        ) {
            throw new \RuntimeException(
                'Unable to preserve the project owner on gateway backend ingress state.',
            );
        }
    }
}
