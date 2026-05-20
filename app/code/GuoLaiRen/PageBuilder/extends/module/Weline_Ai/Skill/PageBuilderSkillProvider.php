<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Skill;

use GuoLaiRen\PageBuilder\Service\AI\Skill\BuiltinSkillProvider;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillNormalizer;
use Weline\Ai\Interface\SkillProviderInterface;
use Weline\Ai\Model\AiSkill;

final class PageBuilderSkillProvider implements SkillProviderInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSkills(): array
    {
        $provider = new BuiltinSkillProvider(new SkillNormalizer());
        $items = [];
        foreach ($provider->listSkills() as $skill) {
            $skill['source_type'] = AiSkill::SOURCE_MODULE;
            $skill['source_module'] = 'GuoLaiRen_PageBuilder';
            $skill['readonly'] = true;
            $items[] = $skill;
        }

        return $items;
    }
}
