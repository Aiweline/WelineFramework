<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/**
 * 智能体声明自带、不可卸下的 AI 技能码。
 * 技能正文由 Weline_Ai Skill 扩展点提供，执行时注入提示词。
 */
interface AgentSkillBindingInterface
{
    /** @return list<string> */
    public function getDefaultSkillCodes(): array;
}
