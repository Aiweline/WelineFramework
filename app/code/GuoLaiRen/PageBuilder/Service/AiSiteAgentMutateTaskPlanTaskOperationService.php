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
                $responseState = \is_array($result['data'] ?? null)
                    ? $result['data']
                    : ($ports->buildWorkspaceState)($session, $adminId, 80, true);
                $executionToken = $this->resolveExecutionToken($result, $responseState, 'task_plan');
                $streamUrl = $this->resolveStreamUrl($result, $responseState, 'task_plan');
                $queueId = \max(
                    (int)($result['queue_id'] ?? 0),
                    $this->resolveQueueId($responseState, 'task_plan')
                );
                if (!$this->isOperationReadyForStream($executionToken, $streamUrl, $queueId)) {
                    return [
                        'success' => false,
                        'message' => 'Task-plan operation did not provide a valid queue/SSE binding. Please retry.',
                        'operation' => 'task_plan',
                        'execution_token' => $executionToken,
                        'stream_url' => $streamUrl,
                        'queue_id' => $queueId,
                        'data' => $responseState,
                    ];
                }
                return [
                    'success' => true,
                    'message' => 'Detected an in-flight stage-2 task-plan operation and resumed its SSE progress.',
                    'operation' => 'task_plan',
                    'execution_token' => $executionToken,
                    'stream_url' => $streamUrl,
                    'start_sse' => true,
                    'queue_id' => $queueId,
                    'data' => $responseState,
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
        $operation = (string)($result['operation'] ?? 'task_plan');
        $executionToken = $this->resolveExecutionToken($result, $responseState, $operation);
        $streamUrl = $this->resolveStreamUrl($result, $responseState, $operation);
        $queueId = \max(
            (int)($result['queue_id'] ?? 0),
            $this->resolveQueueId($responseState, $operation)
        );
        if (!$this->isOperationReadyForStream($executionToken, $streamUrl, $queueId)) {
            return [
                'success' => false,
                'message' => 'Task-plan operation creation failed: queue or SSE binding is missing.',
                'operation' => $operation,
                'execution_token' => $executionToken,
                'stream_url' => $streamUrl,
                'queue_id' => $queueId,
                'queue_wait' => \is_array($result['queue_wait'] ?? null) ? $result['queue_wait'] : null,
                'data' => $responseState,
            ];
        }

        return [
            'success' => true,
            'message' => $queuedMessage,
            'operation' => $operation,
            'execution_token' => $executionToken,
            'stream_url' => $streamUrl,
            'start_sse' => true,
            'queue_id' => $queueId,
            'queue_wait' => \is_array($result['queue_wait'] ?? null) ? $result['queue_wait'] : null,
            'data' => $responseState,
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $responseState
     */
    private function resolveExecutionToken(array $result, array $responseState, string $operation): string
    {
        $token = \trim((string)($result['execution_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }
        $active = $this->resolveOperationPayload($responseState, $operation);
        return \trim((string)($active['execution_token'] ?? ''));
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $responseState
     */
    private function resolveStreamUrl(array $result, array $responseState, string $operation): string
    {
        $streamUrl = \trim((string)($result['stream_url'] ?? ''));
        if ($streamUrl !== '') {
            return $streamUrl;
        }
        $active = $this->resolveOperationPayload($responseState, $operation);
        return \trim((string)($active['stream_url'] ?? ''));
    }

    private function isOperationReadyForStream(string $executionToken, string $streamUrl, int $queueId): bool
    {
        return $executionToken !== '' && $streamUrl !== '' && $queueId > 0;
    }

    /**
     * @param array<string,mixed> $responseState
     */
    private function resolveQueueId(array $responseState, string $operation): int
    {
        $active = $this->resolveOperationPayload($responseState, $operation);
        $queueId = (int)($active['queue_id'] ?? 0);
        if ($queueId > 0) {
            return $queueId;
        }
        $queueInfoByOperation = [
            'plan' => 'plan_queue_info',
            'task_plan' => 'task_plan_queue_info',
            'build' => 'build_queue_info',
            'regenerate_page' => 'build_queue_info',
        ];
        $queueInfoKey = (string)($queueInfoByOperation[$operation] ?? '');
        if ($queueInfoKey !== '' && \is_array($responseState[$queueInfoKey] ?? null)) {
            $queueInfo = $responseState[$queueInfoKey];
            return (int)($queueInfo['queue_id'] ?? $queueInfo['snapshot']['queue_id'] ?? 0);
        }
        return 0;
    }

    /**
     * @param array<string,mixed> $responseState
     * @return array<string,mixed>
     */
    private function resolveOperationPayload(array $responseState, string $operation): array
    {
        $active = \is_array($responseState['active_operation'] ?? null) ? $responseState['active_operation'] : [];
        if ((string)($active['operation'] ?? '') === $operation) {
            return $active;
        }
        $activeOperations = \is_array($responseState['active_operations'] ?? null) ? $responseState['active_operations'] : [];
        $candidate = \is_array($activeOperations[$operation] ?? null) ? $activeOperations[$operation] : [];
        if ($candidate !== []) {
            return $candidate;
        }
        return $active;
    }
}
