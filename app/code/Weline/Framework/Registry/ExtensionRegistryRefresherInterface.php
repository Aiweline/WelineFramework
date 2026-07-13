<?php

declare(strict_types=1);

namespace Weline\Framework\Registry;

interface ExtensionRegistryRefresherInterface
{
    public function refresh(bool $allowConflict = false): bool;

    public function refreshModules(array $moduleNames, bool $allowConflict = false): bool;
}
