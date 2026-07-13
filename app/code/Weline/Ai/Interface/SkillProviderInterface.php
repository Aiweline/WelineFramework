<?php

declare(strict_types=1);

namespace Weline\Ai\Interface;

/** @deprecated Implement \Weline\Ai\Api\SkillProviderInterface. */
interface SkillProviderInterface extends \Weline\Ai\Api\SkillProviderInterface
{
    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function listSkills(): array;
}
