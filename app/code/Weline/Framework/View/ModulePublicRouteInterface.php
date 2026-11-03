<?php

declare(strict_types=1);

namespace Weline\Framework\View;

/**
 * Public-route handler owned by a business module, invoked by the theme shell
 * router for unmatched storefront paths.
 *
 * Registered through the module manifest `provides` key `theme.public_route.<module>`.
 */
interface ModulePublicRouteInterface
{
    /**
     * Whether this module owns the given normalized public path (only consulted
     * in the known-namespace pass; the fallback pass calls every handler).
     */
    public static function handles(string $normalizedPath): bool;

    /**
     * Try to claim the path by filling `$rule['module']` (and rewriting `$path`
     * when the module mounts its own namespace).
     */
    public static function process(string &$path, array &$rule): void;
}
