<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * 方案确认后：从 stage1 scope 生成并确认 build_plan_v2，供构建队列消费。
 */
final class AiSiteBuildPlanTaskScheduler
{
    public function __construct(
        private readonly AiSiteBuildPlanService $buildPlanService,
        private readonly AiSiteBuildTaskService $buildTaskService,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function buildConfirmationScopePatch(array $scope, array $websiteProfile, string $workspaceTrack): array
    {
        unset($workspaceTrack);

        try {
            $contract = $this->buildPlanService->buildFromScope(
                $scope,
                $websiteProfile
            );
        } catch (\Throwable $throwable) {
            return [
                'build_plan_confirmed' => 0,
                'build_plan_v2_validation' => [
                    'valid' => false,
                    'errors' => [$throwable->getMessage()],
                ],
            ];
        }

        $validation = $this->buildPlanService->validate($contract);
        if (!($validation['valid'] ?? false)) {
            return [
                'build_plan_v2' => $contract,
                'build_plan_confirmed' => 0,
                'has_build_plan_v2' => 1,
                'build_plan_v2_validation' => $validation,
            ];
        }

        $coverageScope = \array_replace($scope, [
            'build_plan_v2' => $contract,
            'build_plan_confirmed' => 0,
        ]);
        $coverage = $this->buildTaskService->inspectConfirmedBuildPlanPageTypeCoverage($coverageScope);
        $missingPages = \is_array($coverage['missing_page_types'] ?? null) ? $coverage['missing_page_types'] : [];
        if ($missingPages !== []) {
            return [
                'build_plan_v2' => $contract,
                'build_plan_confirmed' => 0,
                'has_build_plan_v2' => 1,
                'build_plan_v2_validation' => [
                    'valid' => false,
                    'errors' => [
                        'BUILD_PLAN_CONTRACT_INVALID: build_plan_v2.pages missing selected page_types: '
                        . \implode(', ', $missingPages),
                    ],
                ],
            ];
        }

        $confirmedContract = $this->buildPlanService->confirm($contract);
        $confirmedValidation = $this->buildPlanService->validate($confirmedContract);
        if (!($confirmedValidation['valid'] ?? false)) {
            return [
                'build_plan_v2' => $confirmedContract,
                'build_plan_confirmed' => 0,
                'has_build_plan_v2' => 1,
                'build_plan_v2_validation' => $confirmedValidation,
            ];
        }

        $meta = \is_array($confirmedContract['contract_meta'] ?? null) ? $confirmedContract['contract_meta'] : [];

        return [
            'build_plan_v2' => $confirmedContract,
            'plan_projection' => $this->buildPlanService->projection($confirmedContract),
            'content_manifest' => \is_array($confirmedContract['content_manifest'] ?? null)
                ? $confirmedContract['content_manifest']
                : [],
            'build_plan_confirmed' => 1,
            'build_plan_confirmed_at' => (string)($meta['confirmed_at'] ?? \date('Y-m-d H:i:s')),
            'has_build_plan_v2' => 1,
            'build_plan_v2_validation' => $confirmedValidation,
        ];
    }
}
