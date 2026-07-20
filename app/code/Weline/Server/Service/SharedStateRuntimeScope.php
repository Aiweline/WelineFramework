<?php

declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Isolates localhost sidecars when the project tree is shared across runtime
 * environments (for example, Parallels UNC or a Colima bind mount).
 *
 * macOS keeps the historical names. Windows combines the project and local
 * user profile identities, while Linux adds a network-namespace identity.
 * Explicit file names remain authoritative and are not passed through this
 * helper.
 */
final class SharedStateRuntimeScope
{
    public static function tokenDirectory(): string
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return \defined('BP')
                ? BP . 'var' . \DIRECTORY_SEPARATOR . 'session' . \DIRECTORY_SEPARATOR
                : \rtrim(\sys_get_temp_dir(), '\\/') . \DIRECTORY_SEPARATOR . 'wls_session' . \DIRECTORY_SEPARATOR;
        }

        // A Parallels/shared-folder BP is an UNC path. Authentication token
        // reads are latency-sensitive and must not put Worker request loops at
        // the mercy of SMB, so all Windows processes use the same user-local,
        // project-scoped directory instead.
        $basePath = self::windowsLocalAppDataDirectory();

        return \rtrim($basePath, '\\/')
            . \DIRECTORY_SEPARATOR . 'Weline'
            . \DIRECTORY_SEPARATOR . 'wls'
            . \DIRECTORY_SEPARATOR . MasterProcess::getProjectScopeToken()
            . \DIRECTORY_SEPARATOR . 'session'
            . \DIRECTORY_SEPARATOR;
    }

    public static function tokenFilePath(string $fileName): string
    {
        $fileName = \basename(\str_replace('\\', '/', \trim($fileName, " \t\n\r\0\x0B\"'")));

        return self::tokenDirectory() . $fileName;
    }

    public static function defaultTokenFileNameForRole(string $role, int $port = 0): string
    {
        $memory = \str_starts_with(\strtolower(\trim($role)), 'memory');
        $fileName = self::scopeDefaultFileName($memory ? 'memory_server.token' : 'session_server.token');
        $defaultPort = ($memory ? 19971 : 19970) + MasterProcess::getProjectPortOffset();
        if ($port <= 0 || $port === $defaultPort) {
            return $fileName;
        }

        $extension = (string)\pathinfo($fileName, \PATHINFO_EXTENSION);
        $stem = (string)\pathinfo($fileName, \PATHINFO_FILENAME);
        if ($stem === '') {
            $stem = $memory ? 'memory_server' : 'session_server';
        }

        return $stem . '.' . $port . '.' . ($extension !== '' ? $extension : 'token');
    }

    public static function scopeDefaultFileName(string $fileName): string
    {
        $fileName = \basename(\str_replace('\\', '/', \trim($fileName)));
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return $fileName;
        }

        $scope = self::storageScopeToken();
        if ($scope === '') {
            return $fileName;
        }

        $extension = (string)\pathinfo($fileName, \PATHINFO_EXTENSION);
        $stem = (string)\pathinfo($fileName, \PATHINFO_FILENAME);
        if ($stem === '') {
            return $fileName;
        }
        if (\preg_match('/(?:^|\.)' . \preg_quote($scope, '/') . '(?:\.|$)/', $stem) === 1) {
            return $fileName;
        }

        return $stem . '.' . $scope . ($extension !== '' ? '.' . $extension : '');
    }

    public static function storageScopeToken(): string
    {
        $explicit = \trim((string)\getenv('WLS_SHARED_STATE_STORAGE_SCOPE'));
        if ($explicit !== '') {
            if (\strlen($explicit) > 32 || \preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $explicit) !== 1) {
                throw new \RuntimeException(
                    'WLS_SHARED_STATE_STORAGE_SCOPE must be 1-32 characters using A-Z, a-z, 0-9, dot, underscore, or dash.'
                );
            }

            return \strtolower($explicit);
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $profileIdentity = \strtolower(\str_replace(
                '\\',
                '/',
                self::windowsLocalAppDataDirectory()
            ));

            return MasterProcess::getProjectScopeToken()
                . '-u'
                . \substr(\hash('sha256', $profileIdentity), 0, 12);
        }
        if (\PHP_OS_FAMILY !== 'Linux') {
            return '';
        }

        $networkNamespace = @\readlink('/proc/self/ns/net');
        if (!\is_string($networkNamespace) || $networkNamespace === '') {
            $networkNamespace = (string)(@\gethostname() ?: @\php_uname('n'));
        }
        $machineIdentity = @\file_get_contents('/etc/machine-id');
        $machineIdentity = \is_string($machineIdentity) ? \trim($machineIdentity) : '';
        if ($machineIdentity === '') {
            $machineIdentity = (string)(@\gethostname() ?: @\php_uname('n'));
        }

        return 'linux-' . \substr(\hash('sha256', \implode("\0", [
            \PHP_OS_FAMILY,
            $machineIdentity !== '' ? $machineIdentity : 'unknown-machine',
            $networkNamespace !== '' ? $networkNamespace : 'unknown-network',
        ])), 0, 12);
    }

    /**
     * Identity used by shared sidecar process and service names.
     *
     * Keep Unix naming fully backward compatible. Windows needs the same
     * user-aware identity as its shared metadata so one user can never adopt
     * or stop another user's sidecar for a project mounted through UNC.
     */
    public static function sidecarIdentityToken(): string
    {
        return \PHP_OS_FAMILY === 'Windows'
            ? self::storageScopeToken()
            : MasterProcess::getProjectScopeToken();
    }

    private static function windowsLocalAppDataDirectory(): string
    {
        $basePath = \trim((string)\getenv('LOCALAPPDATA'));
        if ($basePath === '') {
            $userProfile = \trim((string)\getenv('USERPROFILE'));
            if ($userProfile !== '') {
                $basePath = \rtrim($userProfile, '\\/')
                    . \DIRECTORY_SEPARATOR . 'AppData'
                    . \DIRECTORY_SEPARATOR . 'Local';
            }
        }
        $basePath = \rtrim($basePath, '\\/');
        if ($basePath === ''
            || \preg_match('/^[A-Za-z]:[\\\\\/]/D', $basePath) !== 1
            || !\is_dir($basePath)
            || !\is_writable($basePath)
        ) {
            throw new \RuntimeException(
                'Windows shared-state tokens require a writable local LOCALAPPDATA or USERPROFILE\\AppData\\Local directory.'
            );
        }

        return $basePath;
    }
}
