<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class ContractQaReportBuilder
{
    public function __construct(
        private readonly ?ContractMetaBuilder $metaBuilder = null,
        private readonly ?PermissionMatrix $permissionMatrix = null,
        private readonly ?QaGateHelper $qaGateHelper = null,
        private readonly ?SourceContractHelper $sourceContractHelper = null,
        private readonly ?ContractPatchValidator $patchValidator = null
    ) {
    }

    /**
     * @param array<int|string, mixed> $contracts
     * @param array<string, list<string>> $requiredSourceTypesByContractType
     * @param array<int|string, mixed> $previousContracts
     * @param list<array<string, mixed>> $contentQualityFindings
     * @return array<string, mixed>
     */
    public function build(
        array $contracts,
        array $requiredSourceTypesByContractType = [],
        array $previousContracts = [],
        array $contentQualityFindings = []
    ): array {
        $contractSet = $this->normalizeContractSet($contracts);
        $previousSet = $this->normalizeContractSet($previousContracts);
        $findings = [];
        $sourceRefs = [];

        foreach ($contractSet as $type => $contract) {
            $sourceRefs[] = $this->buildContractRef($type, $contract);
            foreach ($this->lintSourceContracts($type, $contract, $requiredSourceTypesByContractType[$type] ?? []) as $finding) {
                $findings[] = $finding;
            }
            if (\is_array($previousSet[$type] ?? null)) {
                foreach ($this->lintFrozenMutation($type, $previousSet[$type], $contract) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        $sourceRefs = $this->dedupeRefs($sourceRefs);
        $summary = $this->summarizeFindings($findings, \count($contractSet));
        $contractStatus = $this->resolveReportStatus($summary);
        $contentQuality = $this->buildContentQualityPayload($contentQualityFindings);
        $reportStatus = $this->resolveWorstStatus($contractStatus, (string)$contentQuality['status']);
        $matrix = ($this->permissionMatrix ?? new PermissionMatrix())->forStage(ContractType::STAGE_QA);

        return [
            'contract_meta' => ($this->metaBuilder ?? new ContractMetaBuilder())->build(
                ContractType::TYPE_QA_REPORT,
                ContractType::STAGE_QA,
                $reportStatus === QaGateHelper::STATUS_FAIL ? ContractType::STATUS_FAILED : ContractType::STATUS_PENDING,
                'contract_linter',
                'contract_qa_report',
                [
                    'checked_contract_types' => \array_keys($contractSet),
                    'finding_count' => $summary['finding_count'],
                    'status' => $reportStatus,
                ]
            ),
            'permission_matrix' => $matrix,
            'frozen_fields' => ($this->permissionMatrix ?? new PermissionMatrix())->defaultFrozenFields(ContractType::STAGE_QA),
            'mutable_fields' => [
                'payload.human_notes',
                'qa_gates.*',
            ],
            'source_contracts' => $sourceRefs,
            'contract_context' => [
                'version' => 1,
                'stage' => ContractType::STAGE_QA,
                'checked_contract_types' => \array_keys($contractSet),
            ],
            'qa_gates' => $this->buildGates($findings, $contentQualityFindings),
            'payload' => [
                'status' => $reportStatus,
                'summary' => $summary,
                'contract_quality' => [
                    'status' => $contractStatus,
                    'summary' => $summary,
                    'findings' => $findings,
                ],
                'findings' => $findings,
                'content_quality' => $contentQuality,
            ],
        ];
    }

    /**
     * @param array<int|string, mixed> $contracts
     * @return array<string, array<string, mixed>>
     */
    private function normalizeContractSet(array $contracts): array
    {
        $normalized = [];
        foreach ($contracts as $key => $contract) {
            if (!\is_array($contract) || $contract === []) {
                continue;
            }
            $type = $this->extractContractType($contract, $key);
            if ($type === '' || isset($normalized[$type])) {
                continue;
            }
            $normalized[$type] = $contract;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function extractContractType(array $contract, int|string $fallbackKey): string
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $type = \trim((string)($meta['type'] ?? $contract['type'] ?? ''));
        if ($type !== '') {
            return $type;
        }

        return \is_string($fallbackKey) ? \trim($fallbackKey) : '';
    }

    /**
     * @param array<string, mixed> $contract
     * @param list<string> $requiredTypes
     * @return list<array<string, mixed>>
     */
    private function lintSourceContracts(string $type, array $contract, array $requiredTypes): array
    {
        if ($requiredTypes === []) {
            return [];
        }

        $result = ($this->sourceContractHelper ?? new SourceContractHelper())->validateRequired($contract, $requiredTypes);
        if ((bool)($result['valid'] ?? false)) {
            return [];
        }

        $findings = [];
        foreach (\is_array($result['errors'] ?? null) ? $result['errors'] : [] as $message) {
            $findings[] = $this->finding(
                'error',
                'source_contracts',
                $type,
                (string)$message,
                'source_contracts'
            );
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     * @return list<array<string, mixed>>
     */
    private function lintFrozenMutation(string $type, array $previous, array $next): array
    {
        $matrix = \is_array($next['permission_matrix'] ?? null) ? $next['permission_matrix'] : [];
        $frozenFields = \array_values(\array_filter(\array_map(
            'strval',
            \is_array($next['frozen_fields'] ?? null) ? $next['frozen_fields'] : []
        )));
        $result = ($this->patchValidator ?? new ContractPatchValidator())->validate($previous, $next, $matrix, $frozenFields);
        if ((bool)($result['valid'] ?? false)) {
            return [];
        }

        $findings = [];
        foreach (\is_array($result['errors'] ?? null) ? $result['errors'] : [] as $message) {
            $category = \str_starts_with((string)$message, 'Read-only field changed:') ? 'permissions' : 'frozen_fields';
            $findings[] = $this->finding(
                'error',
                $category,
                $type,
                (string)$message,
                $category
            );
        }

        return $findings;
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $severity, string $category, string $contractType, string $message, string $path): array
    {
        return [
            'severity' => $severity,
            'category' => $category,
            'contract_type' => $contractType,
            'message' => $message,
            'path' => $path,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return list<array<string, mixed>>
     */
    public function buildContentQualityFindings(array $args): array
    {
        $findings = [];

        foreach (\is_array($args['missing_facts'] ?? null) ? $args['missing_facts'] : [] as $factId => $factText) {
            $findings[] = $this->finding(
                'error',
                'content_quality',
                $args['contract_type'] ?? 'source_truth',
                "Missing must-include fact [{$factId}]: {$factText}",
                'content_quality.missing_must_include_fact'
            );
        }

        foreach (\is_array($args['missing_blocks'] ?? null) ? $args['missing_blocks'] : [] as $blockKey) {
            $blockKey = (string)$blockKey;
            $findings[] = $this->finding(
                'error',
                'content_quality',
                $args['contract_type'] ?? 'page_contract',
                "Missing required block: {$blockKey}",
                'content_quality.missing_required_block'
            );
        }

        if (!empty($args['fallback_used'])) {
            $findings[] = $this->finding(
                'warning',
                'content_quality',
                $args['contract_type'] ?? 'execution',
                'Stage-1 fallback plan was used. Content quality may be degraded.',
                'content_quality.fallback_plan_used'
            );
        }

        if (!empty($args['visual_contract_unused'])) {
            foreach (\is_array($args['visual_contract_unused']) ? $args['visual_contract_unused'] : [] as $item) {
                $item = (string)$item;
                $findings[] = $this->finding(
                    'warning',
                    'content_quality',
                    $args['contract_type'] ?? 'page_contract',
                    "Visual contract item not used in any block: {$item}",
                    'content_quality.visual_contract_not_used'
                );
            }
        }

        foreach (\is_array($args['forbidden_visuals_hit'] ?? null) ? $args['forbidden_visuals_hit'] : [] as $hit) {
            $hit = (string)$hit;
            $findings[] = $this->finding(
                'error',
                'content_quality',
                $args['contract_type'] ?? 'theme_design',
                "Forbidden visual pattern detected: {$hit}",
                'content_quality.forbidden_visuals_violation'
            );
        }

        return $findings;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, int>
     */
    private function summarizeFindings(array $findings, int $checkedContractCount): array
    {
        $errorCount = 0;
        $warningCount = 0;
        foreach ($findings as $finding) {
            $severity = (string)($finding['severity'] ?? '');
            if ($severity === 'error') {
                ++$errorCount;
            } elseif ($severity === 'warning') {
                ++$warningCount;
            }
        }

        return [
            'checked_contract_count' => $checkedContractCount,
            'finding_count' => \count($findings),
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
        ];
    }

    /**
     * @param array<string, int> $summary
     */
    private function resolveReportStatus(array $summary): string
    {
        if ((int)($summary['error_count'] ?? 0) > 0) {
            return QaGateHelper::STATUS_FAIL;
        }
        if ((int)($summary['warning_count'] ?? 0) > 0) {
            return QaGateHelper::STATUS_WARN;
        }

        return QaGateHelper::STATUS_PASS;
    }

    private function resolveWorstStatus(string $contractStatus, string $contentStatus): string
    {
        $rank = [
            QaGateHelper::STATUS_PASS => 0,
            'not_evaluated' => 0,
            QaGateHelper::STATUS_PENDING => 0,
            QaGateHelper::STATUS_WARN => 1,
            QaGateHelper::STATUS_FAIL => 2,
        ];

        return (($rank[$contentStatus] ?? 0) > ($rank[$contractStatus] ?? 0)) ? $contentStatus : $contractStatus;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, mixed>
     */
    private function buildContentQualityPayload(array $findings): array
    {
        if ($findings === []) {
            return [
                'status' => 'not_evaluated',
                'summary' => [
                    'finding_count' => 0,
                    'error_count' => 0,
                    'warning_count' => 0,
                ],
                'findings' => [],
                'message' => 'Content quality is evaluated only when a content QA linter supplies findings.',
            ];
        }

        $summary = $this->summarizeFindings($findings, 0);

        return [
            'status' => $this->resolveReportStatus($summary),
            'summary' => [
                'finding_count' => $summary['finding_count'],
                'error_count' => $summary['error_count'],
                'warning_count' => $summary['warning_count'],
            ],
            'findings' => \array_values($findings),
        ];
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param list<array<string, mixed>> $contentQualityFindings
     * @return array<string, array<string, mixed>>
     */
    private function buildGates(array $findings, array $contentQualityFindings): array
    {
        $qa = $this->qaGateHelper ?? new QaGateHelper();
        $categories = [
            'source_contracts' => [],
            'frozen_fields' => [],
            'permissions' => [],
        ];
        foreach ($findings as $finding) {
            $category = (string)($finding['category'] ?? '');
            if (isset($categories[$category])) {
                $categories[$category][] = $finding;
            }
        }

        return [
            'source_contracts' => $this->gateFromCategory($qa, 'source_contracts', $categories['source_contracts']),
            'frozen_fields' => $this->gateFromCategory($qa, 'frozen_fields', $categories['frozen_fields']),
            'permissions' => $this->gateFromCategory($qa, 'permissions', $categories['permissions']),
            'content_quality' => $this->gateFromContentFindings($qa, $contentQualityFindings),
        ];
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, mixed>
     */
    private function gateFromContentFindings(QaGateHelper $qa, array $findings): array
    {
        if ($findings === []) {
            return $qa->gate(
                'content_quality',
                QaGateHelper::STATUS_PENDING,
                'Content quality was not evaluated by this contract-only pass.'
            );
        }

        $summary = $this->summarizeFindings($findings, 0);

        return $qa->gate(
            'content_quality',
            $this->resolveReportStatus($summary),
            'Content quality findings are available.',
            [
                'finding_count' => $summary['finding_count'],
                'error_count' => $summary['error_count'],
                'warning_count' => $summary['warning_count'],
            ]
        );
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, mixed>
     */
    private function gateFromCategory(QaGateHelper $qa, string $key, array $findings): array
    {
        if ($findings === []) {
            return $qa->gate($key, QaGateHelper::STATUS_PASS, 'No contract violations found.');
        }

        return $qa->gate(
            $key,
            QaGateHelper::STATUS_FAIL,
            'Contract violations found.',
            ['finding_count' => \count($findings)]
        );
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{id:string,type:string,version:string,status:string}
     */
    private function buildContractRef(string $type, array $contract): array
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $id = \trim((string)($meta['id'] ?? $meta['contract_id'] ?? $contract['id'] ?? $contract['contract_id'] ?? ''));
        if ($id === '') {
            $id = 'contract_' . \substr(\sha1((string)\json_encode($contract, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)), 0, 16);
        }

        return [
            'id' => $id,
            'type' => $type,
            'version' => \trim((string)($meta['version'] ?? $contract['version'] ?? ContractType::VERSION_V1)),
            'status' => \trim((string)($meta['status'] ?? $contract['status'] ?? '')),
        ];
    }

    /**
     * @param list<array{id:string,type:string,version:string,status:string}> $refs
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    private function dedupeRefs(array $refs): array
    {
        $deduped = [];
        $seen = [];
        foreach (($this->sourceContractHelper ?? new SourceContractHelper())->normalize($refs) as $ref) {
            $key = $ref['type'] . ':' . $ref['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $ref;
        }

        return $deduped;
    }
}
