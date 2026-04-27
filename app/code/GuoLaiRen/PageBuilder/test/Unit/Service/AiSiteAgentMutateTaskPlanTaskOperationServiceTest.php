<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentMutateTaskPlanTaskOperationPorts;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentMutateTaskPlanTaskOperationService;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentMutateTaskPlanTaskOperationServiceTest extends TestCase
{
    private function session(): AiSiteAgentSession
    {
        return $this->createStub(AiSiteAgentSession::class);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function ports(array &$state, array $startOperationResult): AiSiteAgentMutateTaskPlanTaskOperationPorts
    {
        $state['start_operation_calls'] = [];
        $state['build_workspace_state_calls'] = [];

        return new AiSiteAgentMutateTaskPlanTaskOperationPorts(
            startOperation: function (...$args) use (&$state, $startOperationResult): array {
                $state['start_operation_calls'][] = $args;
                return $startOperationResult;
            },
            buildWorkspaceState: function (...$args) use (&$state): array {
                $state['build_workspace_state_calls'][] = $args;
                return ['state' => 'rebuilt'];
            }
        );
    }

    public function testUsesTaskKeyAsDefaultTargetScopeWhenProvided(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, [
            'success' => true,
            'operation' => 'task_plan',
            'queue_id' => 1,
            'execution_token' => 'token-1',
            'stream_url' => '/operation-sse?execution_token=token-1',
            'data' => ['state' => 'direct'],
        ]);

        $result = $service->run(
            $this->session(),
            8,
            ['virtual_theme_plan' => ['last_round' => 6]],
            'page',
            'home_page',
            'refine',
            'task.home.hero',
            ['instruction' => 'x'],
            'x',
            7,
            '',
            $ports
        );

        self::assertTrue($result['success']);
        self::assertSame(['state' => 'direct'], $result['data']);
        $scopePatch = $state['start_operation_calls'][0][4];
        self::assertSame('task.home.hero', $scopePatch['_task_plan_sse_request']['target_scope']);
        $operationDetails = $state['start_operation_calls'][0][7];
        self::assertSame('task_plan', $operationDetails['stage_scope']);
        self::assertSame('mutate_task_plan_task', $operationDetails['prompt_mode']);
        self::assertSame('refine', $operationDetails['action']);
        self::assertSame('task.home.hero', $operationDetails['task_key']);
        self::assertSame('task.home.hero', $operationDetails['target_scope']);
        self::assertSame($scopePatch['_task_plan_sse_request']['mutation'], $operationDetails['mutation']);
    }

    public function testUsesSharedTasksAsDefaultTargetScopeForSharedCreate(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan', 'queue_id' => 2, 'execution_token' => 'token-2', 'stream_url' => '/operation-sse?execution_token=token-2']);

        $service->run(
            $this->session(),
            8,
            [],
            'shared',
            '',
            'create',
            '',
            [],
            '',
            2,
            '',
            $ports
        );

        $scopePatch = $state['start_operation_calls'][0][4];
        self::assertSame('shared_tasks', $scopePatch['_task_plan_sse_request']['target_scope']);
        self::assertSame('shared', $scopePatch['_task_plan_sse_request']['mutation']['bucket']);
    }

    public function testUsesPageTasksScopeForPageCreateWithoutTaskKey(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan', 'queue_id' => 3, 'execution_token' => 'token-3', 'stream_url' => '/operation-sse?execution_token=token-3']);

        $service->run(
            $this->session(),
            8,
            [],
            'page',
            'about_page',
            'create',
            '',
            [],
            '',
            3,
            '',
            $ports
        );

        $scopePatch = $state['start_operation_calls'][0][4];
        self::assertSame('page_tasks.about_page', $scopePatch['_task_plan_sse_request']['target_scope']);
    }

    public function testFallsBackToTaskPlanWhenPageTypeMissing(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan', 'queue_id' => 4, 'execution_token' => 'token-4', 'stream_url' => '/operation-sse?execution_token=token-4']);

        $service->run(
            $this->session(),
            8,
            [],
            'page',
            '',
            'create',
            '',
            [],
            '',
            3,
            '',
            $ports
        );

        $scopePatch = $state['start_operation_calls'][0][4];
        self::assertSame('task_plan', $scopePatch['_task_plan_sse_request']['target_scope']);
    }

    public function testRespectsExplicitTargetScope(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan', 'queue_id' => 5, 'execution_token' => 'token-5', 'stream_url' => '/operation-sse?execution_token=token-5']);

        $service->run(
            $this->session(),
            8,
            [],
            'page',
            'home',
            'rebuild',
            'task.a',
            [],
            '',
            4,
            'page_tasks.custom_scope',
            $ports
        );

        $scopePatch = $state['start_operation_calls'][0][4];
        self::assertSame('page_tasks.custom_scope', $scopePatch['_task_plan_sse_request']['target_scope']);
    }

    public function testReturnsResumedSuccessWhenInFlightTaskPlanDetected(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, [
            'success' => false,
            'operation' => 'task_plan',
            'data' => [
                'active_operations' => [
                    'task_plan' => [
                        'operation' => 'task_plan',
                        'execution_token' => 'resume-token',
                        'stream_url' => '/sse?execution_token=resume-token',
                        'queue_id' => 21,
                    ],
                ],
            ],
        ]);

        $result = $service->run(
            $this->session(),
            8,
            [],
            'page',
            'home',
            'delete',
            'task.a',
            [],
            '',
            5,
            '',
            $ports
        );

        self::assertTrue($result['success']);
        self::assertSame('task_plan', $result['operation']);
        self::assertTrue($result['start_sse']);
        self::assertSame('resume-token', $result['execution_token']);
        self::assertSame('/sse?execution_token=resume-token', $result['stream_url']);
        self::assertSame([
            'active_operations' => [
                'task_plan' => [
                    'operation' => 'task_plan',
                    'execution_token' => 'resume-token',
                    'stream_url' => '/sse?execution_token=resume-token',
                    'queue_id' => 21,
                ],
            ],
        ], $result['data']);
        self::assertSame(21, $result['queue_id']);
        self::assertCount(0, $state['build_workspace_state_calls']);
    }

    public function testReturnsFailureWhenStartOperationFailsWithOtherOperation(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, ['success' => false, 'operation' => 'build', 'message' => 'nope']);

        $result = $service->run(
            $this->session(),
            8,
            [],
            'page',
            'home',
            'refine',
            'task.a',
            [],
            '',
            5,
            '',
            $ports
        );

        self::assertFalse($result['success']);
        self::assertSame('nope', $result['message']);
        self::assertSame('build', $result['operation']);
        self::assertCount(0, $state['build_workspace_state_calls']);
    }

    public function testBuildsFallbackWorkspaceStateWhenSuccessHasNoData(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan', 'queue_id' => 9, 'execution_token' => 'token-9', 'stream_url' => '/operation-sse?execution_token=token-9']);

        $result = $service->run(
            $this->session(),
            8,
            [],
            'page',
            'home',
            'refine',
            'task.a',
            [],
            '',
            5,
            '',
            $ports
        );

        self::assertTrue($result['success']);
        self::assertSame(['state' => 'rebuilt'], $result['data']);
        self::assertSame(9, $result['queue_id']);
        self::assertCount(1, $state['build_workspace_state_calls']);
    }

    public function testResolvesExecutionTokenAndStreamUrlFromOperationStateWhenMissingOnResult(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, [
            'success' => true,
            'operation' => 'task_plan',
            'queue_id' => 11,
            'data' => [
                'active_operations' => [
                    'task_plan' => [
                        'operation' => 'task_plan',
                        'execution_token' => 'nested-token',
                        'stream_url' => '/operation-sse?execution_token=nested-token',
                        'queue_id' => 11,
                    ],
                ],
            ],
        ]);

        $result = $service->run(
            $this->session(),
            8,
            [],
            'page',
            'home',
            'create',
            '',
            [],
            '',
            5,
            '',
            $ports
        );

        self::assertTrue($result['success']);
        self::assertSame('nested-token', $result['execution_token']);
        self::assertSame('/operation-sse?execution_token=nested-token', $result['stream_url']);
        self::assertSame(11, $result['queue_id']);
    }

    public function testReturnsFailureWhenQueueOrSseBindingMissing(): void
    {
        $state = [];
        $service = new AiSiteAgentMutateTaskPlanTaskOperationService();
        $ports = $this->ports($state, [
            'success' => true,
            'operation' => 'task_plan',
            'queue_id' => 0,
            'data' => [
                'active_operations' => [
                    'task_plan' => [
                        'operation' => 'task_plan',
                        'execution_token' => '',
                        'stream_url' => '',
                        'queue_id' => 0,
                    ],
                ],
            ],
        ]);

        $result = $service->run(
            $this->session(),
            8,
            [],
            'page',
            'home',
            'create',
            '',
            [],
            '',
            5,
            '',
            $ports
        );

        self::assertFalse($result['success']);
        self::assertSame('task_plan', $result['operation']);
        self::assertSame(0, $result['queue_id']);
    }
}
