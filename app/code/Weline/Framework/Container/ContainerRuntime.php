<?php

declare(strict_types=1);

namespace Weline\Framework\Container;

use Weline\Framework\Manager\ContainerInterface;
use Weline\Framework\Manager\ServiceScope;

final class ContainerRuntime
{
    private static ?ContainerInterface $container = null;

    private static string $registryDigest = '';

    public static function get(): ContainerInterface
    {
        if (self::$container === null) {
            self::install(new CompiledContainer());
        }

        return self::$container;
    }

    public static function set(?ContainerInterface $container): void
    {
        if ($container === null) {
            self::$container = null;
            self::$registryDigest = '';
            return;
        }

        self::install($container);
    }

    /**
     * Strict cold-start gate for PROD/WLS. It never enables the ObjectManager
     * migration bridge and atomically replaces the process container only
     * after the complete registry and optional expected digest are valid.
     */
    public static function preflight(?string $expectedDigest = null): string
    {
        $expectedDigest = \strtolower(\trim((string)$expectedDigest));
        if ($expectedDigest !== '' && \preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1) {
            throw new ContainerException('Expected compiled container registry digest is invalid.');
        }

        $container = new CompiledContainer(null, false);
        $actualDigest = $container->registryDigest();
        if (\preg_match('/^[a-f0-9]{64}$/D', $actualDigest) !== 1) {
            throw new ContainerException('Compiled container registry digest is missing or invalid.');
        }
        if ($expectedDigest !== '' && !\hash_equals($expectedDigest, $actualDigest)) {
            throw new ContainerException(
                "Compiled container registry digest mismatch: expected={$expectedDigest}, actual={$actualDigest}.",
            );
        }

        self::install($container);
        return $actualDigest;
    }

    public static function registryDigest(): string
    {
        return self::$registryDigest;
    }

    public static function reset(ServiceScope $scope): void
    {
        self::$container?->reset($scope);
    }

    private static function install(ContainerInterface $container): void
    {
        self::$container = $container;
        self::$registryDigest = $container instanceof CompiledContainer
            ? $container->registryDigest()
            : '';
    }

    private function __construct()
    {
    }
}
