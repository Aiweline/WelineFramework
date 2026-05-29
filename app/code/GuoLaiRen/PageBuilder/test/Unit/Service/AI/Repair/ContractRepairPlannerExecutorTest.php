<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Repair;

use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PermissionMatrix;
use GuoLaiRen\PageBuilder\Service\AI\Contract\QaGateHelper;
use GuoLaiRen\PageBuilder\Service\AI\Repair\ContractRepairExecutor;
use GuoLaiRen\PageBuilder\Service\AI\Repair\ContractRepairPlanner;
use PHPUnit\Framework\TestCase;

final class ContractRepairPlannerExecutorTest extends TestCase
{
    public function testPlannerAndExecutorApplyAllowedHumanNoteRepair(): void
    {
        $target = $this->renderDataContract();
        $qaReport = [
            'contract_meta' => [
                'id' => 'contract_qa',
                'type' => ContractType::TYPE_QA_REPORT,
                'version' => ContractType::VERSION_V1,
                'status' => ContractType::STATUS_PENDING,
            ],
            'payload' => [
                'structure_quality' => [
                    'findings' => [
                        [
                            'severity' => 'warning',
                            'category' => 'structure',
                            'rule' => 'structure.missing_section_identity',
                            'message' => 'Section code is missing.',
                            'target_path' => 'payload.page_type_layouts.home_page.content.0',
                        ],
                    ],
                ],
            ],
        ];

        $repairPatch = (new ContractRepairPlanner())->plan($target, $qaReport);
        $result = (new ContractRepairExecutor())->apply($target, $repairPatch);

        self::assertSame(ContractType::TYPE_REPAIR_PATCH, $repairPatch['contract_meta']['type']);
        self::assertCount(1, $result['applied']);
        self::assertSame([], $result['blocked']);
        self::assertSame(
            'structure.missing_section_identity',
            $result['contract']['payload']['human_notes']['repair_suggestions'][0]['rule'] ?? null
        );
        self::assertSame(
            $target['payload']['page_type_layouts'],
            $result['contract']['payload']['page_type_layouts']
        );
        self::assertSame(QaGateHelper::STATUS_PASS, $result['qa_report']['payload']['contract_quality']['status']);
    }

    public function testExecutorBlocksFrozenFieldPatchEvenWhenMutablePatternIsLoose(): void
    {
        $target = $this->renderDataContract();
        $target['mutable_fields'][] = 'payload.page_type_layouts.*';
        $repairPatch = [
            'payload' => [
                'patch_candidates' => [
                    [
                        'op' => 'set',
                        'path' => 'payload.page_type_layouts.home_page.content.0.title',
                        'value' => 'Mutated frozen title',
                    ],
                ],
            ],
        ];

        $result = (new ContractRepairExecutor())->apply($target, $repairPatch);

        self::assertSame([], $result['applied']);
        self::assertCount(1, $result['blocked']);
        self::assertStringContainsString('frozen', \strtolower($result['blocked'][0]['blocked_reason']));
        self::assertSame(
            'Original title',
            $result['contract']['payload']['page_type_layouts']['home_page']['content'][0]['title']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function renderDataContract(): array
    {
        $permissionMatrix = new PermissionMatrix();

        return [
            'contract_meta' => [
                'id' => 'contract_render',
                'type' => ContractType::TYPE_RENDER_DATA,
                'version' => ContractType::VERSION_V1,
                'status' => ContractType::STATUS_DRAFT,
            ],
            'permission_matrix' => $permissionMatrix->forStage(ContractType::STAGE_BUILD),
            'frozen_fields' => [
                'payload.page_type_layouts',
            ],
            'mutable_fields' => [
                'payload.human_notes',
                'qa_gates.*',
            ],
            'payload' => [
                'page_type_layouts' => [
                    'home_page' => [
                        'content' => [
                            [
                                'code' => 'hero',
                                'title' => 'Original title',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
