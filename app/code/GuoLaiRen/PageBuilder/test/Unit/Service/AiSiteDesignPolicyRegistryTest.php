<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteDesignPolicyPromptBuilder;
use GuoLaiRen\PageBuilder\Service\AiSiteDesignPolicyRegistry;
use PHPUnit\Framework\TestCase;

final class AiSiteDesignPolicyRegistryTest extends TestCase
{
    public function testDefaultPremiumPolicyIsStableAndAddressable(): void
    {
        $registry = new AiSiteDesignPolicyRegistry();
        $policy = $registry->get();

        self::assertSame(AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID, $policy['policy_id']);
        self::assertSame(AiSiteDesignPolicyRegistry::DEFAULT_POLICY_VERSION, $policy['version']);
        self::assertStringStartsWith('sha256:', $policy['hash']);
        self::assertSame($policy['hash'], $registry->get()['hash']);
        self::assertTrue($registry->hasRule('layout.4_8_spacing'));
        self::assertTrue($registry->hasRule('image.integrated_not_pasted'));
        self::assertTrue($registry->hasRule('image.text_safe_zone'));
        self::assertTrue($registry->hasRule('background.scrim_text_panel'));
        self::assertTrue($registry->hasRule('ban.reason_fields'));
        self::assertStringContainsString('visible scrim/text-panel safe zone', (string)$policy['full_policy_prompt']);
        self::assertStringContainsString('readable scrim/text-panel safe zones', (string)$policy['compact_policy_prompt']);
    }

    public function testPolicyRefUsesLightweightRegistryPointer(): void
    {
        $ref = (new AiSiteDesignPolicyRegistry())->policyRef();

        self::assertSame('premium_web_v1', $ref['policy_id']);
        self::assertSame('1.0.0', $ref['policy_version']);
        self::assertStringStartsWith('sha256:', $ref['policy_hash']);
        self::assertSame(AiSiteDesignPolicyRegistry::class, $ref['source']);
    }

    public function testPromptBuilderCanReturnScopedRuleSlice(): void
    {
        $prompt = (new AiSiteDesignPolicyPromptBuilder())->buildPolicySlicePrompt([
            'layout.4_8_spacing',
            'responsive.no_horizontal_scroll',
            'missing.rule',
        ]);

        self::assertStringContainsString('premium_web_v1@1.0.0', $prompt);
        self::assertStringContainsString('layout.4_8_spacing', $prompt);
        self::assertStringContainsString('responsive.no_horizontal_scroll', $prompt);
        self::assertStringNotContainsString('missing.rule', $prompt);
    }
}
