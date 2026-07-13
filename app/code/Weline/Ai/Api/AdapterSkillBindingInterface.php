<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

interface AdapterSkillBindingInterface
{
    /** @return list<string> */
    public function getDefaultSkillCodes(): array;
}
