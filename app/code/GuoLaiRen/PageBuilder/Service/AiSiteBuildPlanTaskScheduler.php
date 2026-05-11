<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

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
}
