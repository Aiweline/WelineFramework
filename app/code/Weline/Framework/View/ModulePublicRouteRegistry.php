<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\Compilation\ServiceProviderRegistry;

/**
 * Collects module-owned public-route handlers registered through the
 * `theme.public_route.*` capability prefix.
 */
final class ModulePublicRouteRegistry
{
    public const CAPABILITY_PREFIX = 'theme.public_route.';

    /** @var list<class-string<ModulePublicRouteInterface>>|null */
    private static ?array $handlers = null;

    /** @return list<class-string<ModulePublicRouteInterface>> */
    public static function handlers(): array
    {
        if (self::$handlers !== null) {
            return self::$handlers;
        }
        self::$handlers = [];
        try {
            $registry = new ServiceProviderRegistry();
            foreach ($registry->implementationsWithPrefix(self::CAPABILITY_PREFIX) as $class) {
                if (\is_string($class) && \class_exists($class)
                    && \is_a($class, ModulePublicRouteInterface::class, true)
                ) {
                    self::$handlers[] = $class;
                }
            }
        } catch (\Throwable) {
            self::$handlers = [];
        }

        return self::$handlers;
    }

    public static function reset(): void
    {
        self::$handlers = null;
    }
}
