<?php

declare(strict_types=1);

namespace Weline\Hook\Api;

use Weline\Framework\Registry\ExtensionRegistryRefresherInterface;
use Weline\Hook\HookRegistry;

final class RegistryRefresher implements ExtensionRegistryRefresherInterface
{
    public function __construct(
        private readonly HookRegistry $registry,
    ) {
    }

    public function refresh(bool $allowConflict = false): bool
    {
        return $this->registry->refresh($allowConflict);
    }

    public function refreshModules(array $moduleNames, bool $allowConflict = false): bool
    {
        return $this->registry->refreshForModules($moduleNames, $allowConflict);
    }
}
