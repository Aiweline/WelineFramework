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
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan', 'data' => ['state' => 'direct']]);

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
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan']);

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
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan']);

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
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan']);

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
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan']);

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
        $ports = $this->ports($state, ['success' => false, 'operation' => 'task_plan']);

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
        self::assertSame(['state' => 'rebuilt'], $result['data']);
        self::assertCount(1, $state['build_workspace_state_calls']);
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
        $ports = $this->ports($state, ['success' => true, 'operation' => 'task_plan', 'queue_id' => 9]);

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
}
