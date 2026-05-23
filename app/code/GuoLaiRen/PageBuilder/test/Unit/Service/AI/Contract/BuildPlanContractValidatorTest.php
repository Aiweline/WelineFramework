<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContractValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AiSiteDesignPolicyRegistry;
use PHPUnit\Framework\TestCase;

final class BuildPlanContractValidatorTest extends TestCase
{
    public function testValidBuildPlanContractPasses(): void
    {
        $result = (new BuildPlanContractValidator())->validate($this->validContract());

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
    }

    public function testRejectsMissingTopLevelFieldAndWrongVersion(): void
    {
        $contract = $this->validContract();
        unset($contract['policy_ref']);
        $contract['contract_meta']['version'] = '2.1';

        $result = (new BuildPlanContractValidator())->validate($contract);

        self::assertFalse($result['valid']);
        self::assertContains('Missing top-level field: policy_ref', $result['errors']);
        self::assertContains('contract_meta.version must be 2.2', $result['errors']);
    }

    public function testRejectsReasonFieldsAndFrozenMutableOverlap(): void
    {
        $contract = $this->validContract();
        $contract['blocks'][0]['reason'] = 'Premium layout explanation.';
        $contract['mutable_fields'][] = 'pages';

        $result = (new BuildPlanContractValidator())->validate($contract);

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'Forbidden explanatory field'));
        self::assertContains('Field cannot be both frozen and mutable: pages', $result['errors']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validContract(): array
    {
        $registry = new AiSiteDesignPolicyRegistry();

        return [
            'contract_meta' => [
                'version' => '2.2',
                'id' => 'build_plan_v2_contract',
                'type' => 'build_plan_v2',
                'stage' => 'stage1',
                'status' => 'draft',
                'creator' => 'AiSitePlanQueue',
                'adapter_type' => 'build_plan_contract_v2_2',
                'created_at' => '2026-05-15T00:00:00+00:00',
            ],
            'source_of_truth' => [
                'user_brief' => 'Build an AI tool website.',
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
                    'a11y.alt_focus_semantic',
                ],
                'user_overrides' => [
                    ['field' => 'style', 'value' => 'clean product website'],
                ],
                'quality_floor' => ['Clear hierarchy', 'Responsive layout'],
                'banned_rule_ids' => ['ban.reason_fields', 'ban.lorem_ipsum'],
            ],
            'site_brief' => [
                'site_name' => 'AI Tool Platform',
                'primary_goal' => 'Convert product-qualified visitors.',
            ],
            'design_manifest' => [
                'style_name' => 'Clean product website',
                'tokens' => [
                    'layout' => ['container_max_width' => '1280px'],
                    'spacing' => ['desktop_section_padding' => '120px'],
                    'typography' => ['h1' => 'clamp(40px, 6vw, 76px)'],
                    'colors' => ['primary' => '#2563eb'],
                    'radius' => ['component_radius' => '12px'],
                    'motion' => ['duration' => '240ms'],
                ],
            ],
            'i18n' => [
                'primary_locale' => 'en_US',
                'target_locales' => ['zh_CN'],
            ],
            'content_manifest' => [
                'primary_locale' => 'en_US',
                'items' => [
                    'home.title' => 'AI Tool Platform',
                    'home.desc' => 'A focused product website for AI teams.',
                    'home.hero.title' => 'Launch reliable AI workflows faster',
                    'home.hero.cta' => 'Start building',
                ],
            ],
            'pages' => [
                [
                    'page_id' => 'home',
                    'title_key' => 'home.title',
                    'description_key' => 'home.desc',
                    'blocks' => ['home.hero'],
                ],
            ],
            'blocks' => [
                [
                    'block_id' => 'home.hero',
                    'page_id' => 'home',
                    'block_type' => 'hero',
                    'page_flow_role' => 'opening',
                    'visual_signature' => [
                        'composition_pattern' => 'split hero',
                        'spatial_rhythm' => 'copy left media right',
                        'media_strategy' => 'integrated product image',
                        'surface_treatment' => 'clean product surface',
                    ],
                    'content_keys' => ['home.hero.title', 'home.hero.cta'],
                    'task_ids' => ['task.hero'],
                ],
            ],
            'tasks' => [
                [
                    'task_id' => 'task.hero',
                    'task_kind' => 'block_build',
                    'executor' => 'AiSiteBuildQueue',
                    'input_scope' => ['page_id' => 'home', 'block_id' => 'home.hero'],
                    'runtime_context' => [
                        'target' => ['page_id' => 'home', 'block_id' => 'home.hero'],
                        'block_contract' => ['content_keys' => ['home.hero.title', 'home.hero.cta']],
                    ],
                    'output_contract' => [
                        'format' => 'pagebuilder_component_payload',
                        'required_outputs' => ['html', 'css', 'render_data'],
                    ],
                    'policy_slices' => ['layout.4_8_spacing', 'image.integrated_not_pasted'],
                    'context_budget' => ['max_tokens' => 1800],
                    'acceptance' => [
                        'checks' => ['no_placeholder_or_prompt_copy'],
                    ],
                    'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic'],
                    'depends_on' => [],
                ],
            ],
            'build_order' => ['task.hero'],
            'source_contracts' => [
                ['id' => 'contract_source_truth', 'type' => ContractType::TYPE_SOURCE_TRUTH, 'version' => ContractType::VERSION_V1, 'status' => ContractType::STATUS_DRAFT],
                ['id' => 'contract_site_brief', 'type' => ContractType::TYPE_SITE_BRIEF, 'version' => ContractType::VERSION_V1, 'status' => ContractType::STATUS_DRAFT],
                ['id' => 'contract_design_manifest', 'type' => ContractType::TYPE_DESIGN_MANIFEST, 'version' => ContractType::VERSION_V1, 'status' => ContractType::STATUS_DRAFT],
                ['id' => 'contract_page_contract', 'type' => ContractType::TYPE_PAGE_CONTRACT, 'version' => ContractType::VERSION_V1, 'status' => ContractType::STATUS_DRAFT],
                ['id' => 'contract_block_plan', 'type' => ContractType::TYPE_BLOCK_PLAN, 'version' => ContractType::VERSION_V1, 'status' => ContractType::STATUS_DRAFT],
            ],
            'permission_matrix' => [
                'read' => ['policy_ref', 'policy_projection', 'design_manifest', 'content_manifest', 'pages', 'blocks', 'tasks'],
                'patch' => ['render_data.*', 'asset_manifest.*', 'content_manifest.items.*'],
                'forbidden' => ['policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks', 'tasks', 'build_order'],
            ],
            'frozen_fields' => ['pages', 'blocks', 'tasks', 'build_order', 'design_manifest', 'policy_ref', 'policy_projection'],
            'mutable_fields' => ['render_data.*', 'asset_manifest.*', 'content_manifest.items.*', 'qa_gates.*'],
            'qa_gates' => [
                ['id' => 'policy_ref_valid', 'status' => 'pending'],
            ],
            'presentation_projection' => [
                'never_feed_to_build' => true,
                'headline_key' => 'home.title',
            ],
        ];
    }

    public function testRejectsMissingRequiredSourceContracts(): void
    {
        $contract = $this->validContract();
        $contract['source_contracts'] = [
            ['id' => 'contract_page_contract', 'type' => ContractType::TYPE_PAGE_CONTRACT, 'version' => ContractType::VERSION_V1, 'status' => ContractType::STATUS_DRAFT],
        ];

        $result = (new BuildPlanContractValidator())->validate($contract);

        self::assertFalse($result['valid']);
        self::assertContains('Missing source contract: ' . ContractType::TYPE_SOURCE_TRUTH, $result['errors']);
        self::assertContains('Missing source contract: ' . ContractType::TYPE_SITE_BRIEF, $result['errors']);
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
