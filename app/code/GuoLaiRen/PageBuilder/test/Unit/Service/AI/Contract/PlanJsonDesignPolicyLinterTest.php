<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\PlanJsonDesignPolicyLinter;
use GuoLaiRen\PageBuilder\Service\AiSiteDesignPolicyRegistry;
use PHPUnit\Framework\TestCase;

final class PlanJsonDesignPolicyLinterTest extends TestCase
{
    public function testValidDesignPolicyProjectionPasses(): void
    {
        $result = (new PlanJsonDesignPolicyLinter())->validate($this->contract());

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
    }

    public function testRejectsUnknownRulesAndIncompleteTokens(): void
    {
        $contract = $this->contract();
        $contract['policy_projection']['applied_rule_ids'][] = 'unknown.rule';
        unset($contract['design_manifest']['tokens']['motion']);

        $result = (new PlanJsonDesignPolicyLinter())->validate($contract);

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'unknown.rule'));
        self::assertTrue($this->hasErrorContaining($result['errors'], 'tokens.motion'));
    }

    /**
     * @return array<string, mixed>
     */
    private function contract(): array
    {
        $registry = new AiSiteDesignPolicyRegistry();

        return [
            'source_of_truth' => [
                'design_policy_id' => 'premium_web_v1',
                'user_style' => 'clean product website',
            ],
            'policy_ref' => $registry->policyRef(),
            'policy_projection' => [
                'applied_rule_ids' => [
                    'priority.user_requirements_first',
                    'layout.4_8_spacing',
                    'image.integrated_not_pasted',
                    'responsive.no_horizontal_scroll',
                ],
                'user_overrides' => [
                    ['field' => 'style', 'value' => 'clean product website'],
                ],
                'banned_rule_ids' => ['ban.reason_fields', 'ban.lorem_ipsum'],
            ],
            'design_manifest' => [
                'tokens' => [
                    'layout' => ['container_max_width' => '1280px'],
                    'spacing' => ['desktop_section_padding' => '120px'],
                    'typography' => ['h1' => '64px desktop / 44px mobile'],
                    'colors' => ['primary' => '#2563eb'],
                    'radius' => ['component_radius' => '12px'],
                    'motion' => ['duration' => '240ms'],
                ],
            ],
            'pages' => [
                'home_page' => [
                    'page_type' => 'home_page',
                    'gallery' => [
                    'block_id' => 'home.gallery',
                    'block_type' => 'image_gallery',
                    'visual' => ['image_integration' => 'masked cards with shared radius'],
                ],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function hasErrorContaining(array $errors, string $needle): bool
    {
        foreach ($errors as $error) {
            if (\str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
