<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AiSiteAgentQueueReuseTest extends TestCase
{
    public function testQueueBizKeyUsesReusableSlotsPerSession(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildAiSiteQueueBizKey');
        $method->setAccessible(true);

        self::assertSame(
            'glr_aisite:session:42:queue_slot:plan',
            $method->invoke($controller, 42, 'plan')
        );
        self::assertSame(
            'glr_aisite:session:42:queue_slot:task_plan',
            $method->invoke($controller, 42, 'task_plan')
        );
        self::assertSame(
            'glr_aisite:session:42:queue_slot:build',
            $method->invoke($controller, 42, 'build')
        );
        self::assertSame(
            'glr_aisite:session:42:queue_slot:block_regenerate',
            $method->invoke($controller, 42, 'block_regenerate')
        );
    }

    public function testQueueJobTypeStillDistinguishesPlanningAndBuildPhases(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'resolveAiSiteQueueJobType');
        $method->setAccessible(true);

        self::assertSame('stage1.requirement_expand', $method->invoke($controller, 'plan'));
        self::assertSame('stage2.shared.tasks', $method->invoke($controller, 'task_plan'));
        self::assertSame('virtual_theme.tree.build', $method->invoke($controller, 'build'));
        self::assertSame('virtual_theme.block.regenerate', $method->invoke($controller, 'block_regenerate'));
    }

    public function testLegacyFallbackKeysCoverOldPlanningAndBuildRows(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'resolveAiSiteLegacyQueueBizKeys');
        $method->setAccessible(true);

        self::assertSame(
            [
                'glr_aisite:session:7:queue_slot:planning',
                'glr_aisite:session:7:stage:plan:operation:plan',
            ],
            $method->invoke($controller, 7, 'plan')
        );
        self::assertSame(
            ['glr_aisite:session:7:stage:visual_edit:operation:task_plan'],
            $method->invoke($controller, 7, 'task_plan')
        );
        self::assertSame(
            ['glr_aisite:session:7:stage:visual_edit:operation:build'],
            $method->invoke($controller, 7, 'build')
        );
        self::assertSame(
            ['glr_aisite:session:7:stage:visual_edit:operation:block_regenerate'],
            $method->invoke($controller, 7, 'block_regenerate')
        );
    }

    public function testStageOnePlanPersistenceDetectionRequiresRealPlanArtifacts(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'scopeHasPersistedStageOnePlan');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($controller, [
            'active_operation' => [
                'operation' => 'plan',
                'status' => 'done',
                'message' => '认领跳过：duplicate_stream（重复阶段一生成）',
            ],
            'build_task_summary' => ['pending' => 5],
        ]));
        self::assertFalse($method->invoke($controller, ['plan_confirmed' => 1]));
        self::assertTrue($method->invoke($controller, ['execution_blueprint_draft' => ['tasks' => [['task_key' => 'stage1']]]]));
        self::assertTrue($method->invoke($controller, ['plan_json' => ['pages' => [['page_type' => 'home_page']]]]));
        self::assertTrue($method->invoke($controller, ['plan_workbench' => ['draft' => ['summary' => 'draft']]]));
        self::assertTrue($method->invoke($controller, ['plan_markdown' => '阶段一方案']));
    }

    public function testTaskPlanStartReusesExistingQueuedOperationOnlyForSameOperation(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldReuseRunningQueuedOperation');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, 'task_plan', 'task_plan'));
        self::assertFalse($method->invoke($controller, 'task_plan', 'plan'));
        self::assertFalse($method->invoke($controller, 'build', 'task_plan'));
    }

    public function testTaskPlanSchemeRebuildRequestIsRecognizedFromScopePatch(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'isTaskPlanSchemeRebuildRequest');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, 'task_plan', [
            '_task_plan_sse_request' => ['prompt_mode' => 'rebuild_task_plan'],
        ]));
        self::assertTrue($method->invoke($controller, 'task_plan', [
            '_task_plan_rebuild_in_progress' => 1,
        ]));
        self::assertFalse($method->invoke($controller, 'task_plan', [
            '_task_plan_sse_request' => ['prompt_mode' => 'refine_task_plan'],
        ]));
        self::assertFalse($method->invoke($controller, 'plan', [
            '_task_plan_sse_request' => ['prompt_mode' => 'rebuild_task_plan'],
        ]));
    }

    public function testTaskPlanRebuildMarksOldRunningOperationDiscardedBeforeNewQueueStarts(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'markRunningTaskPlanAsDiscardedForRebuild');
        $method->setAccessible(true);

        $scope = $method->invoke($controller, [], [
            'operation' => 'task_plan',
            'status' => 'running',
            'execution_token' => 'old-token',
        ]);

        self::assertSame('cancelled', $scope['active_operation']['status']);
        self::assertSame(1, $scope['active_operation']['discarded_by_rebuild']);
        self::assertSame('cancelled', $scope['active_operations']['task_plan']['status']);
    }

    public function testStageOneRebuildResetClearsStageOneAndDownstreamPlanArtifacts(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildStageOnePlanRegenerationResetScopePatch');
        $method->setAccessible(true);

        $patch = $method->invoke($controller);

        self::assertSame([], $patch['plan_json']);
        self::assertSame('', $patch['plan_markdown']);
        self::assertSame([], $patch['execution_blueprint']);
        self::assertSame([], $patch['execution_blueprint_draft']);
        self::assertSame(0, $patch['plan_confirmed']);
        self::assertSame([], $patch['virtual_theme_plan']['draft']);
        self::assertSame([], $patch['virtual_theme_plan']['confirmed']);
        self::assertSame('', $patch['task_plan_markdown']);
        self::assertSame(0, $patch['task_plan_confirmed']);
        self::assertSame([], $patch['build_blueprint']);
        self::assertSame([], $patch['build_tasks']);
        self::assertSame(0, $patch['_task_plan_rebuild_in_progress']);
    }

    public function testTaskPlanRebuildResetClearsConfirmedTaskPlanAndBuildArtifacts(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildTaskPlanRegenerationResetScopePatch');
        $method->setAccessible(true);

        $patch = $method->invoke($controller);

        self::assertSame([], $patch['virtual_theme_plan']['draft']);
        self::assertSame('', $patch['virtual_theme_plan']['draft_markdown']);
        self::assertSame([], $patch['virtual_theme_plan']['confirmed']);
        self::assertSame('', $patch['virtual_theme_plan']['confirmed_markdown']);
        self::assertSame('', $patch['task_plan_markdown']);
        self::assertSame([], $patch['task_plan_structured']);
        self::assertSame(0, $patch['task_plan_confirmed']);
        self::assertSame([], $patch['build_blueprint']);
        self::assertSame([], $patch['build_tasks']);
        self::assertSame(1, $patch['_task_plan_rebuild_in_progress']);
    }

    public function testForcedTaskPlanQueueRebuildAlsoClearsOldPlanArtifacts(): void
    {
        $queue = (new ReflectionClass(\GuoLaiRen\PageBuilder\Queue\AiSiteTaskPlanQueue::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(\GuoLaiRen\PageBuilder\Queue\AiSiteTaskPlanQueue::class, 'buildTaskPlanForceRebuildResetPatch');
        $method->setAccessible(true);

        $patch = $method->invoke($queue);

        self::assertSame([], $patch['virtual_theme_plan']['draft']);
        self::assertSame([], $patch['virtual_theme_plan']['confirmed']);
        self::assertSame('', $patch['task_plan_markdown']);
        self::assertSame([], $patch['task_plan_structured']);
        self::assertSame(0, $patch['task_plan_confirmed']);
        self::assertSame([], $patch['build_blueprint']);
        self::assertSame([], $patch['build_tasks']);
    }

    public function testBuildAutoResumeCountsOnlyRetryableIncompleteTasks(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'countIncompleteBuildTasks');
        $method->setAccessible(true);

        self::assertSame(6, $method->invoke($controller, [
            'pending' => 3,
            'running' => 2,
            'failed' => 1,
            'cancelled' => 4,
            'done' => 5,
        ]));
        self::assertSame(0, $method->invoke($controller, [
            'pending' => 0,
            'running' => 0,
            'failed' => 0,
            'cancelled' => 2,
            'done' => 8,
        ]));
    }

    public function testBuildAutoResumeDoesNotPreemptPlanOrTaskPlanQueues(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'hasBlockingQueuedOperationBeforeBuild');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, [], [
            'operation' => 'task_plan',
            'status' => 'running',
        ]));
        self::assertTrue($method->invoke($controller, [
            'active_operations' => [
                'plan' => [
                    'operation' => 'plan',
                    'status' => 'queued',
                ],
            ],
        ], [
            'operation' => 'build',
            'status' => 'queued',
        ]));
        self::assertTrue($method->invoke($controller, [
            'active_operations' => [
                'block_regenerate' => [
                    'operation' => 'block_regenerate',
                    'status' => 'running',
                ],
            ],
        ], [
            'operation' => 'build',
            'status' => 'queued',
        ]));
        self::assertFalse($method->invoke($controller, [
            'active_operations' => [
                'task_plan' => [
                    'operation' => 'task_plan',
                    'status' => 'done',
                ],
            ],
        ], [
            'operation' => 'build',
            'status' => 'running',
        ]));
    }

    public function testQueueRowBizKeyMatchDoesNotLetTaskPlanUseStageOnePlanningSlot(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'resolveAiSiteQueueRowBizKeyOperationMatch');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, ['biz_key' => 'glr_aisite:session:7:queue_slot:planning'], 'plan'));
        self::assertFalse($method->invoke($controller, ['biz_key' => 'glr_aisite:session:7:queue_slot:planning'], 'task_plan'));
        self::assertTrue($method->invoke($controller, ['biz_key' => 'glr_aisite:session:7:queue_slot:task_plan'], 'task_plan'));
        self::assertTrue($method->invoke($controller, ['biz_key' => 'glr_aisite:session:7:stage:visual_edit:operation:task_plan'], 'task_plan'));
        self::assertFalse($method->invoke($controller, ['biz_key' => 'glr_aisite:session:7:stage:plan:operation:plan'], 'task_plan'));
    }
}
