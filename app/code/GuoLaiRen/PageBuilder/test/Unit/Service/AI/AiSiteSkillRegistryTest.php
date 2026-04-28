<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI;

use GuoLaiRen\PageBuilder\Service\AI\AiSiteSkillRegistry;
use PHPUnit\Framework\TestCase;

final class AiSiteSkillRegistryTest extends TestCase
{
    public function testDefaultSkillCodesContainClaudeDesign(): void
    {
        $registry = new AiSiteSkillRegistry();

        $codes = $registry->getDefaultSkillCodes();

        self::assertContains('claude-design', $codes, '默认加载技能必须包含 claude-design');
    }

    public function testListAvailableSkillsExposesClaudeDesignFromSkillsDirectory(): void
    {
        $registry = new AiSiteSkillRegistry();

        $skills = $registry->listAvailableSkills();

        self::assertArrayHasKey('claude-design', $skills, 'skills/claude-design 必须被注册表识别');
        $skill = $skills['claude-design'];
        self::assertSame('claude-design', $skill['code']);
        self::assertNotSame('', (string)$skill['name']);
        self::assertNotSame('', (string)$skill['description']);
        self::assertSame(
            'app/code/GuoLaiRen/PageBuilder/skills/claude-design/SKILL.md',
            $skill['local_path']
        );
        self::assertTrue((bool)$skill['exists']);
    }

    public function testStageOnePromptGuideEmitsCapabilityAndHardRulesSections(): void
    {
        $registry = new AiSiteSkillRegistry();

        $lines = $registry->buildPromptGuideLines('stage1');

        self::assertNotEmpty($lines);
        $payload = \implode("\n", $lines);
        self::assertStringContainsString('AI BUILDER SKILL CAPABILITY', $payload);
        self::assertStringContainsString('CLAUDE-DESIGN HARD RULES', $payload);
        self::assertStringContainsString('claude-design', $payload);
        self::assertStringContainsString(
            'app/code/GuoLaiRen/PageBuilder/skills/claude-design/SKILL.md',
            $payload
        );
        self::assertStringContainsString('Skill loading protocol', $payload);
        self::assertStringContainsString('aggressive multi-hue gradient backgrounds', $payload);
    }

    public function testStageTwoComponentSkillGuideMergesClaudeDesignAndFrontendDesign(): void
    {
        $registry = new AiSiteSkillRegistry();

        $sharedLines = $registry->buildStageTwoComponentSkillGuide(['type' => 'shared']);
        $sharedPayload = \implode("\n", $sharedLines);
        self::assertStringContainsString('AI BUILDER SKILL CAPABILITY', $sharedPayload);
        self::assertStringContainsString('CLAUDE-DESIGN HARD RULES', $sharedPayload);
        self::assertStringContainsString('Frontend design skill reference', $sharedPayload);
        self::assertStringContainsString(
            'app/code/GuoLaiRen/PageBuilder/Service/AI/prompt_guides/frontend-design/SKILL.md',
            $sharedPayload
        );
        self::assertStringContainsString('shared theme component such as header/footer', $sharedPayload);

        $pageLines = $registry->buildStageTwoComponentSkillGuide(['type' => 'page']);
        $pagePayload = \implode("\n", $pageLines);
        self::assertStringContainsString('page-owned theme block component', $pagePayload);
    }

    public function testGetSkillReturnsFallbackForUnknownCode(): void
    {
        $registry = new AiSiteSkillRegistry();

        $skill = $registry->getSkill('non-existent-skill-xyz');

        self::assertSame('non-existent-skill-xyz', $skill['code']);
        self::assertSame('', (string)$skill['description']);
        self::assertFalse((bool)$skill['exists']);
        self::assertSame(
            'app/code/GuoLaiRen/PageBuilder/skills/non-existent-skill-xyz/SKILL.md',
            $skill['local_path']
        );
    }
}
