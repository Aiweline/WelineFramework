<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI;

use GuoLaiRen\PageBuilder\Service\AI\AiSiteSkillRegistry;
use GuoLaiRen\PageBuilder\Service\AI\Skill\BuiltinSkillProvider;
use GuoLaiRen\PageBuilder\Service\AI\Skill\CustomSkillProvider;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillNormalizer;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillSelectionResolver;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillSnapshotBuilder;
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
        self::assertStringContainsString('Palette role discipline', $payload);
        self::assertStringContainsString('Contrast gate', $payload);
        self::assertStringContainsString('Code craft gate', $payload);
        self::assertStringContainsString('FRONTEND-DESIGN COMPATIBILITY RULES', $payload);
        self::assertStringContainsString('artifacts, posters, or applications', $payload);
        self::assertStringContainsString('Visually striking and memorable', $payload);
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

    public function testPrependPromptGuideIsIdempotent(): void
    {
        $registry = new AiSiteSkillRegistry();

        $prompt = $registry->prependPromptGuide('Return JSON only.', 'stage3');
        $second = $registry->prependPromptGuide($prompt, 'stage3');

        self::assertStringContainsString('AI BUILDER SKILL CAPABILITY', $prompt);
        self::assertStringContainsString('CLAUDE-DESIGN HARD RULES', $prompt);
        self::assertStringContainsString('Return JSON only.', $prompt);
        self::assertSame($prompt, $second);
    }

    public function testBuildSkillSnapshotsDefaultsToClaudeDesign(): void
    {
        $registry = new AiSiteSkillRegistry();

        $snapshots = $registry->buildSkillSnapshots([]);

        self::assertCount(1, $snapshots);
        self::assertSame('claude-design', $snapshots[0]['code']);
        self::assertSame('builtin_file', $snapshots[0]['source']);
        self::assertNotSame('', $snapshots[0]['normalized_body']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshots[0]['body_hash']);
    }

    public function testCustomSkillSnapshotUsesNormalizedBodyAndHash(): void
    {
        $normalizer = new SkillNormalizer();
        $customProvider = new CustomSkillProvider(null, [
            'conversion-copy' => [
                'code' => 'conversion-copy',
                'name' => 'Conversion Copy',
                'description' => 'Write specific offer copy.',
                'body' => "Line one\r\n\r\n\r\n\r\nLine two\r\n",
                'status' => 'active',
                'source' => 'custom_db',
                'local_path' => '',
                'abs_path' => '',
                'exists' => true,
            ],
        ]);
        $resolver = new SkillSelectionResolver(new BuiltinSkillProvider($normalizer), $customProvider);
        $registry = new AiSiteSkillRegistry(
            $normalizer,
            null,
            $customProvider,
            $resolver,
            new SkillSnapshotBuilder($resolver, $normalizer)
        );

        $snapshots = $registry->buildSkillSnapshots(['conversion-copy']);

        self::assertSame('conversion-copy', $snapshots[0]['code']);
        self::assertSame("Line one\n\n\nLine two", $snapshots[0]['normalized_body']);
        self::assertSame(\hash('sha256', "Line one\n\n\nLine two"), $snapshots[0]['body_hash']);
    }

    public function testSelectedCustomSkillBodyIsInjectedIntoPromptGuide(): void
    {
        $customProvider = new CustomSkillProvider(null, [
            'conversion-copy' => [
                'code' => 'conversion-copy',
                'name' => 'Conversion Copy',
                'description' => 'Write offer copy.',
                'body' => 'Always use direct offer copy with a measurable promise.',
                'status' => 'active',
                'source' => 'custom_db',
                'exists' => true,
            ],
        ]);
        $resolver = new SkillSelectionResolver(new BuiltinSkillProvider(), $customProvider);
        $registry = new AiSiteSkillRegistry(
            null,
            null,
            $customProvider,
            $resolver,
            new SkillSnapshotBuilder($resolver)
        );

        $payload = \implode("\n", $registry->buildPromptGuideLines('stage1', ['conversion-copy']));

        self::assertStringContainsString('Skill code: conversion-copy', $payload);
        self::assertStringContainsString('Always use direct offer copy with a measurable promise.', $payload);
    }

    public function testPromptGuideForScopePrefersFrozenSkillSnapshot(): void
    {
        $customProvider = new CustomSkillProvider(null, [
            'conversion-copy' => [
                'code' => 'conversion-copy',
                'name' => 'Conversion Copy',
                'description' => 'Current DB version.',
                'body' => 'CURRENT DB BODY SHOULD NOT BE USED.',
                'status' => 'active',
                'source' => 'custom_db',
                'exists' => true,
            ],
        ]);
        $resolver = new SkillSelectionResolver(new BuiltinSkillProvider(), $customProvider);
        $registry = new AiSiteSkillRegistry(
            null,
            null,
            $customProvider,
            $resolver,
            new SkillSnapshotBuilder($resolver)
        );

        $payload = \implode("\n", $registry->buildPromptGuideLinesForScope('plan_json', [
            'plan_json' => [
                'selected_skill_codes' => ['conversion-copy'],
                'skill_snapshots' => [[
                    'code' => 'conversion-copy',
                    'name' => 'Conversion Copy',
                    'description' => 'Frozen version.',
                    'source' => 'custom_db',
                    'normalized_body' => 'FROZEN SNAPSHOT BODY MUST BE USED.',
                    'body_hash' => \hash('sha256', 'FROZEN SNAPSHOT BODY MUST BE USED.'),
                ]],
            ],
        ]));

        self::assertStringContainsString('FROZEN SNAPSHOT BODY MUST BE USED.', $payload);
        self::assertStringNotContainsString('CURRENT DB BODY SHOULD NOT BE USED.', $payload);
    }

    public function testDisabledOrMissingSkillCannotBeSelectedForSnapshot(): void
    {
        $customProvider = new CustomSkillProvider(null, [
            'disabled-skill' => [
                'code' => 'disabled-skill',
                'name' => 'Disabled',
                'description' => '',
                'body' => 'Disabled body',
                'status' => 'disabled',
                'source' => 'custom_db',
                'exists' => true,
            ],
        ]);
        $resolver = new SkillSelectionResolver(new BuiltinSkillProvider(), $customProvider);
        $registry = new AiSiteSkillRegistry(
            null,
            null,
            $customProvider,
            $resolver,
            new SkillSnapshotBuilder($resolver)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disabled');

        $registry->buildSkillSnapshots(['disabled-skill']);
    }

    public function testCustomSkillCannotOverrideBuiltinCodeInMergedRegistry(): void
    {
        $registry = new AiSiteSkillRegistry(
            null,
            null,
            new CustomSkillProvider(null, [
                'claude-design' => [
                    'code' => 'claude-design',
                    'name' => 'Hijack',
                    'description' => 'Should not replace builtin.',
                    'body' => 'Custom body',
                    'status' => 'active',
                    'source' => 'custom_db',
                    'exists' => true,
                ],
            ])
        );

        $skills = $registry->listAvailableSkills();

        self::assertSame('builtin_file', $skills['claude-design']['source'] ?? null);
        self::assertNotSame('Hijack', $skills['claude-design']['name'] ?? null);
    }
}
