<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Repair;

use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractMetaBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PermissionMatrix;
use GuoLaiRen\PageBuilder\Service\AI\Contract\QaGateHelper;

final class ContractRepairPlanner
{
    public function __construct(
        private readonly ?ContractMetaBuilder $metaBuilder = null,
        private readonly ?PermissionMatrix $permissionMatrix = null,
        private readonly ?QaGateHelper $qaGateHelper = null
    ) {
    }

    /**
     * @param array<string, mixed> $targetContract
     * @param array<string, mixed> $qaReportContract
     * @return array<string, mixed>
     */
    public function plan(array $targetContract, array $qaReportContract): array
    {
        $findings = $this->extractFindings($qaReportContract);
        $candidates = [];
        foreach ($findings as $index => $finding) {
            $candidates[] = [
                'op' => 'append',
                'path' => 'payload.human_notes.repair_suggestions',
                'value' => [
                    'source_finding_index' => $index,
                    'category' => (string)($finding['category'] ?? ''),
                    'rule' => (string)($finding['rule'] ?? ''),
                    'severity' => (string)($finding['severity'] ?? ''),
                    'message' => (string)($finding['message'] ?? ''),
                    'target_path' => (string)($finding['target_path'] ?? $finding['path'] ?? ''),
                ],
            ];
        }

        $targetRef = $this->contractRef($targetContract);
        $qaReportRef = $this->contractRef($qaReportContract);
        $qa = $this->qaGateHelper ?? new QaGateHelper();
        $matrix = ($this->permissionMatrix ?? new PermissionMatrix())->forStage(ContractType::STAGE_REPAIR);

        return [
            'contract_meta' => ($this->metaBuilder ?? new ContractMetaBuilder())->build(
                ContractType::TYPE_REPAIR_PATCH,
                ContractType::STAGE_REPAIR,
                ContractType::STATUS_PENDING,
                'repair_planner',
                'rules_repair_plan',
                [
                    'target_contract_id' => $targetRef['id'],
                    'qa_report_id' => $qaReportRef['id'],
                    'candidate_count' => \count($candidates),
                ]
            ),
            'permission_matrix' => $matrix,
            'frozen_fields' => ($this->permissionMatrix ?? new PermissionMatrix())->defaultFrozenFields(ContractType::STAGE_REPAIR),
            'mutable_fields' => [
                'payload.patch_candidates.*',
                'qa_gates.*',
            ],
            'source_contracts' => \array_values(\array_filter([$targetRef, $qaReportRef], static fn(array $ref): bool => $ref['id'] !== '')),
            'qa_gates' => [
                'candidate_generation' => $qa->gate(
                    'candidate_generation',
                    $candidates !== [] ? QaGateHelper::STATUS_PASS : QaGateHelper::STATUS_WARN,
                    $candidates !== [] ? 'Repair candidates generated from QA findings.' : 'No QA findings available for repair planning.'
                ),
                'permission_validation' => $qa->gate('permission_validation', QaGateHelper::STATUS_PENDING, 'Repair executor must validate every candidate before applying.'),
            ],
            'payload' => [
                'target_contract' => $targetRef,
                'qa_report' => $qaReportRef,
                'patch_candidates' => $candidates,
                'finding_count' => \count($findings),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $qaReportContract
     * @return list<array<string, mixed>>
     */
    private function extractFindings(array $qaReportContract): array
    {
        $payload = \is_array($qaReportContract['payload'] ?? null) ? $qaReportContract['payload'] : [];
        $findings = [];
        foreach (\is_array($payload['findings'] ?? null) ? $payload['findings'] : [] as $finding) {
            if (\is_array($finding)) {
                $findings[] = $finding;
            }
        }
        $contentQuality = \is_array($payload['content_quality'] ?? null) ? $payload['content_quality'] : [];
        foreach (\is_array($contentQuality['findings'] ?? null) ? $contentQuality['findings'] : [] as $finding) {
            if (\is_array($finding)) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{id:string,type:string,version:string,status:string}
     */
    private function contractRef(array $contract): array
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];

        return [
            'id' => \trim((string)($meta['id'] ?? $meta['contract_id'] ?? $contract['id'] ?? $contract['contract_id'] ?? '')),
            'type' => \trim((string)($meta['type'] ?? $contract['type'] ?? '')),
            'version' => \trim((string)($meta['version'] ?? $contract['version'] ?? ContractType::VERSION_V1)),
            'status' => \trim((string)($meta['status'] ?? $contract['status'] ?? '')),
        ];
    }
}
