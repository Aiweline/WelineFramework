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
}
