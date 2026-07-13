<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

interface SkillProviderInterface
{
    /** @return array<int|string, array<string, mixed>> */
    public function listSkills(): array;
}
