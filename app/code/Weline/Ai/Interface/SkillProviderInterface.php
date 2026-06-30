<?php

declare(strict_types=1);

namespace Weline\Ai\Interface;

interface SkillProviderInterface
{
    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function listSkills(): array;
}
