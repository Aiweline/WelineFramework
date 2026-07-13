<?php

declare(strict_types=1);

namespace Weline\Framework\Module;

interface ModuleIdentityProviderInterface
{
    /**
     * @param list<string> $names
     * @return array<string, int> name => id
     */
    public function idsByNames(array $names): array;

    /**
     * @param list<int> $ids
     * @return array<int, string> id => name
     */
    public function namesByIds(array $ids): array;
}
