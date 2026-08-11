<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Locates the immutable, platform-specific gateway package distributed with
 * one project release. It never builds, downloads or trusts package bytes;
 * HostGatewayPackageManager remains the signature and component authority.
 */
final class GatewayProjectReleasePackageResolver
{
    private const DISTRIBUTION_ROOT = 'extend/server/wls-gateway';

    private const TARGETS = [
        'linux-x86_64',
        'linux-arm64',
        'darwin-x86_64',
        'darwin-arm64',
        'windows-x86_64',
    ];

    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $targetProfileOverride = null,
    ) {
    }

    /**
     * @return array{ok:bool,state:string,reason:string,path:string,project_root:string,target_profile:string}
     */
    public function resolve(): array
    {
        $configuredRoot = $this->projectRoot
            ?? (\defined('BP') ? (string)\constant('BP') : (string)\getcwd());
        if ($configuredRoot === '' || \str_contains($configuredRoot, "\0")) {
            throw new \RuntimeException('Gateway project release root is invalid.');
        }
        $root = @\realpath($configuredRoot);
        $rootStatus = @\lstat($configuredRoot);
        if (!\is_string($root)
            || $root === ''
            || !\is_array($rootStatus)
            || \is_link($configuredRoot)
            || !\is_dir($configuredRoot)
        ) {
            throw new \RuntimeException(
                'Gateway project release root is missing, linked, or unsafe.',
            );
        }
        $target = $this->targetProfileOverride === null
            ? self::targetProfile()
            : self::normalizeTargetProfile($this->targetProfileOverride);
        $relativeParts = ['extend', 'server', 'wls-gateway', $target];
        $current = $root;
        foreach ($relativeParts as $offset => $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            $status = @\lstat($current);
            if (!\is_array($status)) {
                if (\file_exists($current) || \is_link($current)) {
                    throw new \RuntimeException(
                        'Gateway project release package path is indeterminate or unsafe.',
                    );
                }
                return self::unavailable($target);
            }
            if (\is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException(
                    'Gateway project release package path contains a linked or non-directory component.',
                );
            }
            if ($offset === \array_key_last($relativeParts)
                && (int)($status['nlink'] ?? 0) < 1
            ) {
                throw new \RuntimeException(
                    'Gateway project release package target is unsafe.',
                );
            }
        }
        $resolved = @\realpath($current);
        if (!\is_string($resolved)
            || !self::canonicalPathsEqual($current, $resolved)
            || !self::pathIsWithin($resolved, $root)
        ) {
            throw new \RuntimeException(
                'Gateway project release package escaped its canonical project root.',
            );
        }
        $manifest = $resolved . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifestStatus = @\lstat($manifest);
        if (!\is_array($manifestStatus)) {
            if (\file_exists($manifest) || \is_link($manifest)) {
                throw new \RuntimeException(
                    'Gateway project release manifest path is indeterminate or unsafe.',
                );
            }
            return self::unavailable($target);
        }
        if (\is_link($manifest)
            || ((((int)($manifestStatus['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($manifestStatus['nlink'] ?? 0) !== 1
            || (int)($manifestStatus['size'] ?? 0) < 1
            || (int)($manifestStatus['size'] ?? 0) > 8_388_608
        ) {
            throw new \RuntimeException(
                'Gateway project release manifest is linked, special, empty, or oversized.',
            );
        }
        return [
            'ok' => true,
            'state' => 'AVAILABLE',
            'reason' => 'A platform-matched project gateway release package is available for signature verification.',
            'path' => $resolved,
            'project_root' => $root,
            'target_profile' => $target,
        ];
    }

    public static function targetProfile(
        ?string $osFamily = null,
        ?string $machine = null,
    ): string {
        $osFamily ??= \PHP_OS_FAMILY;
        $machine ??= (string)\php_uname('m');
        $os = match (\strtolower(\trim($osFamily))) {
            'linux' => 'linux',
            'darwin' => 'darwin',
            'windows' => 'windows',
            default => '',
        };
        $arch = match (\strtolower(\trim($machine))) {
            'amd64', 'x86_64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => '',
        };
        if ($os === '' || $arch === '') {
            throw new \RuntimeException(
                'The current OS or architecture is unsupported by WLS Gateway releases.',
            );
        }
        return self::normalizeTargetProfile($os . '-' . $arch);
    }

    private static function normalizeTargetProfile(string $target): string
    {
        $target = \strtolower(\trim($target));
        if (!\in_array($target, self::TARGETS, true)) {
            throw new \RuntimeException(
                'The requested WLS Gateway release target is unsupported.',
            );
        }
        return $target;
    }

    /** @return array{ok:false,state:string,reason:string,path:string,project_root:string,target_profile:string} */
    private static function unavailable(string $target): array
    {
        return [
            'ok' => false,
            'state' => 'PACKAGE_UNAVAILABLE',
            'reason' => 'No signed project gateway release package exists at '
                . self::DISTRIBUTION_ROOT . '/' . $target . '.',
            'path' => '',
            'project_root' => '',
            'target_profile' => $target,
        ];
    }

    private static function pathIsWithin(
        string $path,
        string $root,
        ?string $osFamily = null,
    ): bool
    {
        $path = \rtrim(\str_replace('\\', '/', $path), '/');
        $root = \rtrim(\str_replace('\\', '/', $root), '/');
        $osFamily ??= \PHP_OS_FAMILY;
        if (\strcasecmp($osFamily, 'Windows') === 0) {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return $path !== $root && \str_starts_with($path . '/', $root . '/');
    }

    private static function canonicalPathsEqual(
        string $left,
        string $right,
        ?string $osFamily = null,
    ): bool
    {
        $left = \rtrim(\str_replace('\\', '/', $left), '/');
        $right = \rtrim(\str_replace('\\', '/', $right), '/');
        $osFamily ??= \PHP_OS_FAMILY;
        if (\strcasecmp($osFamily, 'Windows') === 0) {
            $left = \strtolower($left);
            $right = \strtolower($right);
        }
        return \hash_equals($left, $right);
    }
}
