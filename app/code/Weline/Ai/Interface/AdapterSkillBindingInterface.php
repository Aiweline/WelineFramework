<?php

declare(strict_types=1);

namespace Weline\Ai\Interface;

/** @deprecated Implement \Weline\Ai\Api\AdapterSkillBindingInterface. */
interface AdapterSkillBindingInterface extends \Weline\Ai\Api\AdapterSkillBindingInterface
{
    /**
     * Code-level bindings are system locked and cannot be removed by admins.
     *
     * @return list<string>
     */
    public function getDefaultSkillCodes(): array;
}
