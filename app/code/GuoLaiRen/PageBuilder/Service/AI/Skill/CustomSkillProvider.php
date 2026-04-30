<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Skill;

use Weline\Framework\Manager\ObjectManager;

final class CustomSkillProvider
{
    /** @param array<string, array<string, mixed>> $seedSkills */
    public function __construct(
        private readonly ?CustomSkillRepository $repository = null,
        private readonly array $seedSkills = []
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listSkills(): array
    {
        if ($this->seedSkills !== []) {
            return $this->seedSkills;
        }
        try {
            return $this->repository()->listByCode();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSkill(string $code): ?array
    {
        if ($this->seedSkills !== []) {
            $code = \trim($code);
            return $this->seedSkills[$code] ?? null;
        }
        try {
            return $this->repository()->findArrayByCode($code);
        } catch (\Throwable) {
            return null;
        }
    }

    private function repository(): CustomSkillRepository
    {
        return $this->repository ?? ObjectManager::getInstance(CustomSkillRepository::class);
    }
}
