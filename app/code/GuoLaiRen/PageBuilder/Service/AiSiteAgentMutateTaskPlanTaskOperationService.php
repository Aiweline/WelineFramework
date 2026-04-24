<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;

class AiSiteAgentMutateTaskPlanTaskOperationService
{
    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $taskConfig
     * @return array<string,mixed>
     */
    public function run(
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        string $bucket,
        string $pageType,
        string $action,
        string $taskKey,
        array $taskConfig,
        string $instruction,
        int $round,
        string $targetScope,
        AiSiteAgentMutateTaskPlanTaskOperationPorts $ports
    ): array {
        $normalizedBucket = \strtolower($bucket) === 'shared' ? 'shared' : 'page';
        $resolvedTargetScope = \trim($targetScope);
        if ($resolvedTargetScope === '') {
            $resolvedTargetScope = $taskKey !== ''
                ? $taskKey
                : ($normalizedBucket === 'shared'
                    ? 'shared_tasks'
                    : ($pageType !== '' ? 'page_tasks.' . $pageType : 'task_plan'));
        }

        $result = ($ports->startOperation)(
            $session,
            $adminId,
            'task_plan',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            [
                'build_blueprint' => \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [],
                'build_tasks' => \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [],
                '_task_plan_sse_request' => [
                    'prompt_mode' => 'mutate_task_plan_task',
                    'instruction' => $instruction,
                    'target_scope' => $resolvedTargetScope,
                    'round' => $round,
                    'mutation' => [
                        'action' => $action,
                        'bucket' => $normalizedBucket,
                        'page_type' => $pageType,
                        'task_key' => $taskKey,
                        'task_config' => $taskConfig,
                    ],
                ],
            ],
            '',
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
        );

        if (empty($result['success'])) {
            if ((string)($result['operation'] ?? '') === 'task_plan') {
                return [
                    'success' => true,
                    'message' => 'Detected an in-flight stage-2 task-plan operation and resumed its SSE progress.',
                    'operation' => 'task_plan',
                    'start_sse' => true,
                    'data' => ($ports->buildWorkspaceState)($session, $adminId, 80, true),
                ];
            }

            return [
                'success' => false,
                'message' => (string)($result['message'] ?? 'Unable to start stage-2 task-plan mutation.'),
                'operation' => (string)($result['operation'] ?? ''),
            ];
        }

        $queuedMessage = match ($action) {
            'create' => 'Stage-2 task add request queued. Watch the SSE progress stream.',
            'delete' => 'Stage-2 task delete request queued. Watch the SSE progress stream.',
            'rebuild' => 'Stage-2 task rebuild request queued. Watch the SSE progress stream.',
            default => 'Stage-2 task refine request queued. Watch the SSE progress stream.',
        };
        $responseState = \is_array($result['data'] ?? null)
            ? $result['data']
            : ($ports->buildWorkspaceState)($session, $adminId, 80, true);

        return [
            'success' => true,
            'message' => $queuedMessage,
            'operation' => (string)($result['operation'] ?? 'task_plan'),
            'start_sse' => true,
            'queue_id' => (int)($result['queue_id'] ?? 0),
            'queue_dispatch' => \is_array($result['queue_dispatch'] ?? null) ? $result['queue_dispatch'] : null,
            'data' => $responseState,
        ];
    }
}

