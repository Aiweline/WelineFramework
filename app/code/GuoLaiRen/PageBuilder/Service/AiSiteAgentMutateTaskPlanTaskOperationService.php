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
        AiSiteAgentMutateTaskPlanTaskOperationPorts $ports,
        array $taskKeys = [],
        array $taskConfigs = [],
        array $targetScopes = []
    ): array {
        $normalizedBucket = \strtolower($bucket) === 'shared' ? 'shared' : 'page';
        $taskKeys = \array_values(\array_unique(\array_filter(\array_map(static fn($value): string => \trim((string)$value), \array_merge(
            $taskKey !== '' ? [$taskKey] : [],
            $taskKeys
        )), static fn(string $value): bool => \trim($value) !== '')));
        if ($taskKey === '' && $taskKeys !== []) {
            $taskKey = (string)$taskKeys[0];
        }
        $resolvedTargetScope = \trim($targetScope);
        if ($resolvedTargetScope === '') {
            $resolvedTargetScope = \count($taskKeys) > 1
                ? \implode(',', $taskKeys)
                : ($taskKey !== ''
                ? $taskKey
                : ($normalizedBucket === 'shared'
                    ? 'shared_tasks'
                    : ($pageType !== '' ? 'page_tasks.' . $pageType : 'task_plan')));
        }
        if ($targetScopes === [] && $taskKeys !== []) {
            foreach ($taskKeys as $candidateTaskKey) {
                $targetScopes[] = $normalizedBucket === 'shared'
                    ? 'shared_tasks.' . $candidateTaskKey
                    : 'page_tasks.' . $pageType . '.' . $candidateTaskKey;
            }
        }
        if ($targetScopes === [] && $resolvedTargetScope !== '') {
            $targetScopes = [$resolvedTargetScope];
        }
        $targets = \array_map(
            static fn (string $candidateTaskKey): array => [
                'bucket' => $normalizedBucket,
                'page_type' => $pageType,
                'task_key' => $candidateTaskKey,
                'target_scope' => $normalizedBucket === 'shared'
                    ? 'shared_tasks.' . $candidateTaskKey
                    : 'page_tasks.' . $pageType . '.' . $candidateTaskKey,
            ],
            $taskKeys
        );
        $mutation = [
            'action' => $action,
            'bucket' => $normalizedBucket,
            'page_type' => $pageType,
            'task_key' => $taskKey,
            'task_keys' => $taskKeys,
            'task_config' => $taskConfig,
            'task_configs' => $taskConfigs,
            'target_scopes' => $targetScopes,
            'targets' => $targets,
        ];

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
                    'target_scopes' => $targetScopes,
                    'round' => $round,
                    'mutation' => $mutation,
                    'mutations' => [$mutation],
                    'task_key' => $taskKey,
                    'task_keys' => $taskKeys,
                    'task_config' => $taskConfig,
                    'task_configs' => $taskConfigs,
                    'selected_tasks' => $taskKeys,
                    'targets' => $targets,
                ],
            ],
            '',
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            [
                'stage_scope' => 'task_plan',
                'prompt_mode' => 'mutate_task_plan_task',
                'action' => $action,
                'bucket' => $normalizedBucket,
                'page_type' => $pageType,
                'task_key' => $taskKey,
                'task_keys' => $taskKeys,
                'target_scope' => $resolvedTargetScope,
                'target_scopes' => $targetScopes,
                'instruction' => $instruction,
                'round' => $round,
                'mutation' => $mutation,
                'mutations' => [$mutation],
                'task_config' => $taskConfig,
                'task_configs' => $taskConfigs,
                'selected_tasks' => $taskKeys,
                'targets' => $targets,
            ]
        );

        if (empty($result['success'])) {
            if ((string)($result['operation'] ?? '') === 'task_plan') {
                return [
                    'success' => true,
                    'message' => 'Detected an in-flight stage-2 task-plan operation and resumed its SSE progress.',
                    'operation' => 'task_plan',
                    'execution_token' => (string)($result['execution_token'] ?? ''),
                    'stream_url' => (string)($result['stream_url'] ?? ''),
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
            'execution_token' => (string)($result['execution_token'] ?? ''),
            'stream_url' => (string)($result['stream_url'] ?? ''),
            'start_sse' => true,
            'queue_id' => (int)($result['queue_id'] ?? 0),
            'queue_wait' => \is_array($result['queue_wait'] ?? null) ? $result['queue_wait'] : null,
            'data' => $responseState,
        ];
    }
}
