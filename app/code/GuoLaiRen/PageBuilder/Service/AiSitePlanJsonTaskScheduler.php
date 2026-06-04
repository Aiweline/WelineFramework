<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSitePlanJsonTaskScheduler
{
    public function __construct(
        private readonly AiSitePlanJsonTaskService $planJsonTaskService
    ) {}

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function buildConfirmationScopePatch(array $scope, array $websiteProfile, string $workspaceTrack, int|string|null $sessionId = null): array
    {
        unset($websiteProfile, $workspaceTrack);

        $editor = new AiSitePlanJsonStateService($sessionId);
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $coverage = $this->planJsonTaskService->inspectConfirmedPlanJsonPageTypeCoverage($scope);
        $missingPages = \is_array($coverage['missing_page_types'] ?? null) ? $coverage['missing_page_types'] : [];
        if ($missingPages !== []) {
            $validation = [
                'valid' => false,
                'errors' => [
                    'PLAN_JSON_PAGES_INVALID: plan_json.pages missing selected page_types: '
                    . \implode(', ', $missingPages),
                ],
            ];

            return \array_replace($editor->setConfirmedScopePatch($planJson, false), [
                'plan_json_pages_validation' => $validation,
            ]);
        }
        $validation = [
            'valid' => true,
            'errors' => [],
        ];

        return \array_replace($editor->setConfirmedScopePatch($planJson, true), [
            'plan_json_pages_validation' => $validation,
        ]);
    }
}
