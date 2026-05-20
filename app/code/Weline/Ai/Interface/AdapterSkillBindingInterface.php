<?php

declare(strict_types=1);

namespace Weline\Ai\Interface;

interface AdapterSkillBindingInterface
{
    /**
     * Code-level bindings are system locked and cannot be removed by admins.
     *
     * @return list<string>
     */
    public function getDefaultSkillCodes(): array;
}
