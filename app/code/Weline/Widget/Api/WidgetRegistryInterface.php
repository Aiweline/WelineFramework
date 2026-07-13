<?php

declare(strict_types=1);

namespace Weline\Widget\Api;

/**
 * Read-only public contract for the compiled Widget registry.
 */
interface WidgetRegistryInterface
{
    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function getRegistry(bool $forceReload = false): array;
}
