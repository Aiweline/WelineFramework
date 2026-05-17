<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthCoverageLinter;

final class AiSiteBuildPlanTaskScheduler
{
    public function __construct(
        private readonly ?AiSiteBuildPlanService $buildPlanService = null
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function buildConfirmationScopePatch(array $scope, array $websiteProfile = [], string $workspaceTrack = ''): array
    {
        $service = $this->buildPlanService();
        $contract = $service->confirm($service->buildFromScope($scope, $websiteProfile));
        $validation = $service->validate($contract);
        $validation = $this->mergeValidation($validation, $this->validateSourceTruthCoverage($scope, $contract));
        if (!($validation['valid'] ?? false)) {
            return [
                'build_plan_v2_validation' => $validation,
                'build_plan_confirmed' => 0,
            ];
        }

        $confirmedAt = (string)($contract['contract_meta']['confirmed_at'] ?? \date('Y-m-d H:i:s'));

        return [
            'build_plan_v2' => $contract,
            'plan_projection' => $service->projection($contract),
            'content_manifest' => \is_array($contract['content_manifest'] ?? null) ? $contract['content_manifest'] : [],
            'build_plan_confirmed' => 1,
            'build_plan_confirmed_at' => $confirmedAt,
            'build_plan_v2_validation' => $validation,
            'has_build_plan_v2' => 1,
            'workspace_track' => $workspaceTrack !== '' ? $workspaceTrack : (string)($scope['workspace_track'] ?? ''),
        ];
    }

    private function buildPlanService(): AiSiteBuildPlanService
    {
        return $this->buildPlanService ?? new AiSiteBuildPlanService();
    }

    /**
     * @param array{valid:bool,errors:list<string>} $base
     * @param array{valid:bool,errors:list<string>,warnings?:list<string>,findings?:list<array<string,mixed>>} $extra
     * @return array{valid:bool,errors:list<string>,warnings?:list<string>,findings?:list<array<string,mixed>>}
     */
    private function mergeValidation(array $base, array $extra): array
    {
        $errors = \array_values(\array_unique(\array_merge(
            \is_array($base['errors'] ?? null) ? \array_map('strval', $base['errors']) : [],
            \is_array($extra['errors'] ?? null) ? \array_map('strval', $extra['errors']) : []
        )));
        $warnings = \array_values(\array_unique(\array_merge(
            \is_array($base['warnings'] ?? null) ? \array_map('strval', $base['warnings']) : [],
            \is_array($extra['warnings'] ?? null) ? \array_map('strval', $extra['warnings']) : []
        )));
        $findings = \array_values(\array_merge(
            \is_array($base['findings'] ?? null) ? $base['findings'] : [],
            \is_array($extra['findings'] ?? null) ? $extra['findings'] : []
        ));

        return \array_filter([
            'valid' => $errors === [] && (bool)($base['valid'] ?? false) && (bool)($extra['valid'] ?? false),
            'errors' => $errors,
            'warnings' => $warnings !== [] ? $warnings : null,
            'findings' => $findings !== [] ? $findings : null,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{valid:bool,errors:list<string>,warnings:list<string>,findings:list<array<string,mixed>>}
     */
    private function validateSourceTruthCoverage(array $scope, array $contract = []): array
    {
        $sourceTruth = $this->sanitizeSourceTruthContract(
            \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : []
        );
        if ($sourceTruth !== [] && $contract !== []) {
            $lint = (new SourceTruthCoverageLinter())->lintBuildPlanContract($sourceTruth, $contract);
            return $this->coverageLintResultToValidation($lint);
        }

        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if ($sourceTruth === [] || $planJson === []) {
            return ['valid' => true, 'errors' => [], 'warnings' => [], 'findings' => []];
        }

        $lint = (new SourceTruthCoverageLinter())->lintPlanJson($sourceTruth, $planJson);
        return $this->coverageLintResultToValidation($lint);
    }

    /**
     * @param array<string, mixed> $lint
     * @return array{valid:bool,errors:list<string>,warnings:list<string>,findings:list<array<string,mixed>>}
     */
    private function coverageLintResultToValidation(array $lint): array
    {
        $errors = [];
        $warnings = [];
        foreach (\is_array($lint['findings'] ?? null) ? $lint['findings'] : [] as $finding) {
            if (!\is_array($finding)) {
                continue;
            }
            $message = \trim((string)($finding['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            if ((string)($finding['severity'] ?? '') === 'error') {
                $errors[] = $message;
            } else {
                $warnings[] = $message;
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => \array_values(\array_unique($errors)),
            'warnings' => \array_values(\array_unique($warnings)),
            'findings' => \is_array($lint['findings'] ?? null) ? \array_values($lint['findings']) : [],
        ];
    }

    /**
     * @param array<string, mixed> $sourceTruth
     * @return array<string, mixed>
     */
    private function sanitizeSourceTruthContract(array $sourceTruth): array
    {
        if ($sourceTruth === []) {
            return [];
        }

        $facts = [];
        foreach (\is_array($sourceTruth['must_include_facts'] ?? null) ? $sourceTruth['must_include_facts'] : [] as $fact) {
            if (!\is_array($fact)) {
                continue;
            }
            $text = \trim((string)($fact['text'] ?? ''));
            if ($text === '' || $this->isInternalControlFact($text)) {
                continue;
            }
            $facts[] = $fact;
        }
        $sourceTruth['must_include_facts'] = \array_values($facts);

        return $sourceTruth;
    }

    private function isInternalControlFact(string $text): bool
    {
        return \preg_match('/(?:^\s*\[FORCE\]|queue:run|--force|\s-f\b|强制重建建站方案|重新跑队列)/iu', $text) === 1;
    }
}
