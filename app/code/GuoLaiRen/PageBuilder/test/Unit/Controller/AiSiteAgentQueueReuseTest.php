<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
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

    public function testQueueBizKeyLookupPrefersLatestReusableSlotRecord(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        self::assertStringContainsString("->order(\\Weline\\Queue\\Model\\Queue::schema_fields_ID, 'DESC')", $source);
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

    public function testQueuedOperationStartOnlyReusesSameNonPlanQueueOperation(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldReuseRunningQueuedOperation');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, 'task_plan', 'task_plan'));
        self::assertTrue($method->invoke($controller, 'build', 'build'));
        self::assertTrue($method->invoke($controller, 'block_regenerate', 'block_regenerate'));
        self::assertTrue($method->invoke($controller, 'regenerate_page', 'regenerate_page'));
        self::assertFalse($method->invoke($controller, 'build', 'task_plan'));
        self::assertFalse($method->invoke($controller, 'block_regenerate', 'build'));
        self::assertFalse($method->invoke($controller, 'task_plan', 'build'));
        self::assertFalse($method->invoke($controller, 'regenerate_page', 'block_regenerate'));
        self::assertFalse($method->invoke($controller, 'task_plan', 'plan'));
        self::assertFalse($method->invoke($controller, 'plan', 'build'));
        self::assertFalse($method->invoke($controller, 'build', ''));
    }

    public function testQueuedOperationStartBlocksDifferentRunningQueueOperationWithoutReusingStream(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractControllerMethodSource($source, 'startOperation');

        $busyPos = \strpos($methodSource, "'code' => 'AI_SITE_OPERATION_BUSY'");
        $baseScopePos = \strpos($methodSource, '$baseScope = $scope;');

        self::assertIsInt($busyPos);
        self::assertIsInt($baseScopePos);
        self::assertLessThan($baseScopePos, $busyPos);
        self::assertStringContainsString('resolveRunningQueuedOperationState($scope, $operation)', $methodSource);
        self::assertStringContainsString('buildRunningOperationBusyMessage($operation, $runningOperation)', $methodSource);
        self::assertStringContainsString("'operation' => \$operation", $methodSource);
        self::assertStringContainsString("'running_operation' => \$runningOperation", $methodSource);
        self::assertStringContainsString("'stream_url' => ''", $methodSource);
    }

    public function testQueuedOperationConflictReadsRunningOperationSlotsBeyondActiveOperation(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'resolveRunningQueuedOperationState');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'active_operation' => [
                'operation' => 'block_regenerate',
                'status' => 'done',
                'execution_token' => 'old-block',
            ],
            'active_operations' => [
                'build' => [
                    'operation' => 'build',
                    'status' => 'running',
                    'execution_token' => 'build-token',
                    'queue_id' => 0,
                ],
            ],
        ], 'block_regenerate');

        self::assertSame('build', $result['operation'] ?? null);
        self::assertSame('running', $result['status'] ?? null);
        self::assertSame('build-token', $result['execution_token'] ?? null);
    }

    public function testQueuedOperationStartChecksLinkedQueueBeforeReusingActiveOperation(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        $queueIssuePos = \strpos($source, 'resolveActiveOperationLinkedQueueIssue($activeOperation)');
        $reusePos = \strpos($source, 'shouldReuseRunningQueuedOperation($operation, $runningOperation)');

        self::assertIsInt($queueIssuePos);
        self::assertIsInt($reusePos);
        self::assertLessThan($reusePos, $queueIssuePos);
        self::assertStringContainsString("['done', 'error', 'stop', 'cancelled', 'canceled']", $source);
        self::assertStringContainsString('linked_queue_duplicate_skip', $source);
    }

    public function testFrontendAiStartPreflightsRealProviderBeforeQueueCreation(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractControllerMethodSource($source, 'startOperation');

        $preflightPos = \strpos($methodSource, 'assertFrontendAiProviderReadyBeforeQueue($operation)');
        $tokenPos = \strpos($methodSource, '$executionToken = \\bin2hex(\\random_bytes(16));');
        $enqueuePos = \strpos($methodSource, 'enqueueOperationQueueTask($freshForQueue');

        self::assertIsInt($preflightPos);
        self::assertIsInt($tokenPos);
        self::assertIsInt($enqueuePos);
        self::assertLessThan($tokenPos, $preflightPos);
        self::assertLessThan($enqueuePos, $preflightPos);
        self::assertStringContainsString("AiService::generateText(", $source);
        self::assertStringContainsString("'allow_zero_balance_provider' => true", $source);
        self::assertStringContainsString("'disable_conversation_history' => true", $source);
        self::assertStringContainsString("'disable_conversation_persist' => true", $source);
        self::assertStringContainsString("'code' => 'AI_PROVIDER_NOT_READY'", $source);
        self::assertStringContainsString("'queue_id' => 0", $source);
    }

    public function testPreflightFailureIsNotOverwrittenByOldQueueSnapshot(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'reconcileActiveOperationWithQueueInfo');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            [
                'operation' => 'build',
                'status' => 'error',
                'queue_id' => 0,
                'message' => 'AI provider readiness check failed before queue creation.',
                'preflight_failed' => 1,
            ],
            [
                'queue_id' => 582,
                'snapshot' => ['status' => 'error', 'message' => 'old queue failure'],
            ],
            'build'
        );

        self::assertSame(0, $result['queue_id']);
        self::assertSame(1, $result['preflight_failed']);
        self::assertSame('AI provider readiness check failed before queue creation.', $result['message']);
    }

    public function testDuplicateStreamQueueResultIsRecognizedAsRetryableTerminalSkip(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'isAiSiteQueueDuplicateSkipResult');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, [
            'process' => 'claim skipped: duplicate_stream',
            'result' => '',
        ]));
        self::assertTrue($method->invoke($controller, [
            'process' => '',
            'result' => 'Superseded by newer PageBuilder queue #9; skipped duplicate AI execution.',
        ]));
        self::assertFalse($method->invoke($controller, [
            'process' => 'AI component generation failed: provider returned HTTP 402',
            'result' => '',
        ]));
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

    public function testQueueRowReuseOnlyPreservesExplicitlyRunningTaskPlanRow(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldReuseAiSiteQueueRow');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, ['queue_id' => 9, 'status' => 'done']));
        self::assertTrue($method->invoke($controller, ['queue_id' => 9, 'status' => 'running']));
        self::assertFalse($method->invoke($controller, ['queue_id' => 9, 'status' => 'error']));
        self::assertFalse($method->invoke($controller, ['queue_id' => 9, 'status' => 'running'], true));
        self::assertFalse($method->invoke($controller, ['queue_id' => 0, 'status' => 'done']));
    }

    public function testQueueReusePatchResetsOldQueueStateInsteadOfCreatingAnotherRow(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildAiSiteQueueReusePatch');
        $method->setAccessible(true);

        $patch = $method->invoke(
            $controller,
            'PageBuilder plan #abcd1234',
            ['operation' => 'plan', 'execution_token' => 'tok-1'],
            'glr_aisite:session:7:queue_slot:plan',
            88
        );

        self::assertSame('PageBuilder plan #abcd1234', $patch['name']);
        self::assertSame('GuoLaiRen_PageBuilder', $patch['module']);
        self::assertSame(88, $patch['type_id']);
        self::assertSame(['operation' => 'plan', 'execution_token' => 'tok-1'], $patch['content']);
        self::assertSame('pending', $patch['status']);
        self::assertTrue($patch['auto']);
        self::assertSame('glr_aisite:session:7:queue_slot:plan', $patch['biz_key']);
        self::assertSame('', $patch['result']);
        self::assertSame('', $patch['process']);
        self::assertSame(0, $patch['pid']);
        self::assertSame(0, $patch['finished']);
    }

    public function testBuildQueueSkipsSupersededDuplicateRowsBeforeAiExecution(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Queue/AiSiteBuildQueue.php');

        self::assertStringContainsString('findSupersedingQueueRow', $source);
        self::assertStringContainsString('skipped duplicate AI execution', $source);
        self::assertStringContainsString('Active operation is already owned by newer queue', $source);
    }

    public function testEmptyGeneratedSectionBlockIsNotAcceptedAsSuccessfulBuildTask(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'isGeneratedSectionBlockUsable');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($controller, ['code' => 'content/home-hero'], ['html' => '']));
        self::assertFalse($method->invoke($controller, ['code' => ''], ['html' => '<section>Real copy</section>']));
        self::assertTrue($method->invoke($controller, ['code' => 'content/home-hero'], ['html' => '<section>Real copy</section>']));
        self::assertTrue($method->invoke($controller, ['code' => 'content/home-visual'], ['html' => '<svg viewBox="0 0 20 20"></svg>']));
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

    public function testDefaultTaskPlanStartReusesPersistedDraftWithoutQueue(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $buildTaskService = $this->createMock(AiSiteBuildTaskService::class);
        $buildTaskService->method('hasConfirmedTaskPlanForBuild')->willReturn(false);
        $property = (new ReflectionClass(AiSiteAgent::class))->getProperty('buildTaskService');
        $property->setAccessible(true);
        $property->setValue($controller, $buildTaskService);

        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldReusePersistedTaskPlanWithoutQueue');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, ['task_plan_markdown' => 'draft'], 'detect_bootstrap_task_plan', '', ''));
        self::assertTrue($method->invoke($controller, ['task_plan_structured' => ['shared_tasks' => [['task_key' => 'shared:header']]]], '', '', ''));
        self::assertFalse($method->invoke($controller, ['task_plan_markdown' => 'draft'], 'refine_task_plan', '', ''));
        self::assertFalse($method->invoke($controller, ['task_plan_markdown' => 'draft'], 'resume_task_plan', '', ''));
        self::assertFalse($method->invoke($controller, ['task_plan_markdown' => 'draft'], 'detect_bootstrap_task_plan', 'change copy', ''));
        self::assertFalse($method->invoke($controller, [], 'detect_bootstrap_task_plan', '', ''));
    }

    public function testStageOneResumeModeBypassesPureReuseShortcutAndKeepsCheckpointPrompting(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        self::assertStringContainsString("['rebuild', 'resume_plan']", $source);
        self::assertStringContainsString("\$effectivePlanPromptMode = 'resume_plan';", $source);
        self::assertStringContainsString("\$requestedPromptMode === 'resume_plan'", $source);
        self::assertStringContainsString("'resume_generation'", $source);
    }

    public function testTaskPlanQueueDuplicateGuardAllowsOnlyExplicitMutationRequests(): void
    {
        $queue = (new ReflectionClass(\GuoLaiRen\PageBuilder\Queue\AiSiteTaskPlanQueue::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(\GuoLaiRen\PageBuilder\Queue\AiSiteTaskPlanQueue::class, 'hasQueuedTaskPlanMutationRequest');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($queue, [
            '_task_plan_sse_request' => ['prompt_mode' => 'detect_bootstrap_task_plan'],
        ]));
        self::assertTrue($method->invoke($queue, [
            '_task_plan_sse_request' => [
                'prompt_mode' => 'mutate_task_plan_task',
                'mutation' => [
                    'action' => 'refine',
                    'task_key' => 'page:home_page:content/home-page-hero',
                ],
            ],
        ]));
        self::assertTrue($method->invoke($queue, [
            'details' => [
                '_task_plan_sse_request' => ['prompt_mode' => 'refine_task_plan'],
            ],
        ]));
        self::assertTrue($method->invoke($queue, [
            'target_scope' => 'page_tasks.home_page.content/home-page-hero',
        ]));
    }

    public function testBuildQueuesReconcilePersistedArtifactsBeforePickingPendingTasks(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        foreach (['runHtmlBlocksBuildOperationV3', 'runVirtualThemeBuildOperationV3'] as $methodName) {
            $methodOffset = \strpos($source, 'private function ' . $methodName);
            self::assertNotFalse($methodOffset, $methodName . ' missing');
            $nextMethodOffset = \strpos($source, 'private function ', $methodOffset + 1);
            $methodSource = $nextMethodOffset === false
                ? \substr($source, $methodOffset)
                : \substr($source, $methodOffset, $nextMethodOffset - $methodOffset);

            $reconcileOffset = \strpos($methodSource, 'reconcileGeneratedArtifactsWithTaskState($scope)');
            $pendingOffset = \strpos($methodSource, 'listPendingTasks($scope)');

            self::assertNotFalse($reconcileOffset, $methodName . ' does not reconcile persisted artifacts');
            self::assertNotFalse($pendingOffset, $methodName . ' does not list pending tasks');
            self::assertLessThan($pendingOffset, $reconcileOffset, $methodName . ' must reconcile before pending selection');
        }
    }

    public function testVirtualThemeBuildInvalidatesQualityFailedPagesBeforePendingSelection(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractControllerMethodSource($source, 'runVirtualThemeBuildOperationV3');

        $reconcileOffset = \strpos($methodSource, 'reconcileGeneratedArtifactsWithTaskState($scope)');
        $invalidateOffset = \strpos($methodSource, 'invalidateQualityFailedVirtualThemeBuildTasks($scope)');
        $pendingOffset = \strpos($methodSource, 'listPendingTasks($scope)');

        self::assertNotFalse($reconcileOffset, 'virtual theme build must reconcile generated artifacts first');
        self::assertNotFalse($invalidateOffset, 'virtual theme build must invalidate quality-failed tasks');
        self::assertNotFalse($pendingOffset, 'virtual theme build must list pending tasks');
        self::assertLessThan($invalidateOffset, $reconcileOffset, 'quality invalidation must inspect reconciled artifacts');
        self::assertLessThan($pendingOffset, $invalidateOffset, 'quality invalidation must run before pending task selection');

        $htmlBlocksSource = $this->extractControllerMethodSource($source, 'runHtmlBlocksBuildOperationV3');
        self::assertStringNotContainsString(
            'invalidateQualityFailedVirtualThemeBuildTasks($scope)',
            $htmlBlocksSource,
            'virtual-theme quality retry must not mutate HTML-block build track'
        );
    }

    public function testQualityInvalidationMarksOnlyFailedPageTasksPendingForRetry(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractControllerMethodSource($source, 'invalidateQualityFailedVirtualThemeBuildTasks');

        self::assertStringContainsString('inspectScope($scope)', $methodSource);
        self::assertStringContainsString("\$qualityReport['page_reports']", $methodSource);
        self::assertStringContainsString("\$pageReport['bad_matches']", $methodSource);
        self::assertStringContainsString('listTaskKeysByPageType($scope, $pageType)', $methodSource);
        self::assertStringContainsString('clearQualityFailedVirtualThemePageArtifacts($scope, $pageType)', $methodSource);
        self::assertStringContainsString('markTaskPendingForFreshRepair($scope, $taskKey, $message)', $methodSource);
        self::assertStringContainsString("'bad_matches' => \$badMatchesByPage", $methodSource);
    }

    public function testVirtualThemeBatchKeepsFirstFatalFailureReason(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractControllerMethodSource($source, 'runVirtualThemeBuildOperationV3');

        self::assertStringContainsString(
            'if (!$fatalBatchThrowable instanceof \Throwable)',
            $methodSource,
            'later materialization errors must not hide the original AI task failure'
        );
    }

    public function testBuildQueuesDoNotRunAiPlaceholderHydrationBeforeTaskLoop(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        foreach (['runHtmlBlocksBuildOperationV3', 'runVirtualThemeBuildOperationV3'] as $methodName) {
            $methodOffset = \strpos($source, 'private function ' . $methodName);
            self::assertNotFalse($methodOffset, $methodName . ' missing');
            $nextMethodOffset = \strpos($source, 'private function ', $methodOffset + 1);
            $methodSource = $nextMethodOffset === false
                ? \substr($source, $methodOffset)
                : \substr($source, $methodOffset, $nextMethodOffset - $methodOffset);
            $loopOffset = \strpos($methodSource, 'while (true)');
            self::assertNotFalse($loopOffset, $methodName . ' missing task loop');
            $beforeTaskLoop = \substr($methodSource, 0, $loopOffset);

            self::assertStringContainsString(
                'buildVirtualPagesByType($pageTypes, $scope, false)',
                $beforeTaskLoop,
                $methodName . ' must not trigger AI placeholder generation before tasks can checkpoint'
            );
        }
    }

    public function testWorkspacePreviewDoesNotRunAiPlaceholderHydrationOnGet(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractControllerMethodSource($source, 'resolveWorkspacePreviewContext');

        self::assertStringContainsString(
            "\$this->scopeCompatibilityService->resolveScopedPageTypes(\$scope),\n            \$scope,\n            false",
            $methodSource,
            'workspace preview GET must render stored/generated artifacts only; AI generation belongs to queue operations'
        );
    }

    public function testVirtualThemeBuildDoesNotSynthesizeFallbackSectionSpecs(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        self::assertStringNotContainsString('buildFallbackPageTaskSectionSpec', $source);
        self::assertStringContainsString('Build task missing stage-two section spec', $source);
    }

    public function testIncrementalMaterializationKeepsExactSinglePageSubset(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractControllerMethodSource($source, 'materializeGeneratedPages');

        self::assertStringNotContainsString(
            'normalizePageTypes($pageTypes)',
            $methodSource,
            'single-page materialization must not inject home_page into a resumable page build'
        );
    }

    public function testBuildQueueTerminalErrorClearsSchedulerWaitingFlags(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Queue/AiSiteBuildQueue.php');
        $methodSource = $this->extractControllerMethodSource($source, 'updateSessionError');

        self::assertStringContainsString("'queue_waiting_for_scheduler'] = false", $methodSource);
        self::assertStringContainsString("'can_close_stream'] = false", $methodSource);
        self::assertStringContainsString("'continue_other_operations'] = false", $methodSource);
        self::assertStringContainsString('WORKSPACE_STATUS_FAILED', $methodSource);
        self::assertStringContainsString("'latest_build_failed'] = 1", $methodSource);
        self::assertStringContainsString("'publish_blocked_by_latest_ai_failure'] = 1", $methodSource);
        self::assertStringContainsString('resetRunningTasksForInterruptedBuild', $methodSource);
    }

    public function testNewBuildQueueStopsOlderPendingDuplicateRowsBeforeSchedulerCanRunThem(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractControllerMethodSource($source, 'stopSupersededPendingAiSiteQueueRows');

        self::assertStringContainsString("['pending', 'queued']", $methodSource);
        self::assertStringContainsString("'status' => 'stop'", $methodSource);
        self::assertStringContainsString('Superseded by newer PageBuilder queue #', $methodSource);
        self::assertStringContainsString('stopSupersededPendingAiSiteQueueRows($bizKey, $queueId, $operation)', $source);
    }

    public function testNewFrontendBuildResetsFailedAndInterruptedTasksForFreshRepair(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $handleSource = $this->extractControllerMethodSource($source, 'handleStartBuild');
        $startSource = $this->extractControllerMethodSource($source, 'startOperation');
        $failedResetOffset = \strpos($startSource, 'resetFailedTasksForFreshRepair');
        $runningResetOffset = \strpos($startSource, 'resetRunningTasksForInterruptedBuild');
        $enqueueOffset = \strpos($startSource, 'enqueueOperationQueueTask');

        self::assertNotFalse($failedResetOffset, 'failed tasks must get a fresh retry budget before a new frontend build');
        self::assertNotFalse($runningResetOffset, 'interrupted running tasks must be pending before a new frontend build');
        self::assertNotFalse($enqueueOffset, 'build queue enqueue missing');
        self::assertLessThan($enqueueOffset, $failedResetOffset);
        self::assertLessThan($enqueueOffset, $runningResetOffset);
        self::assertStringContainsString("'fresh_repair_failed_tasks' => 1", $handleSource);
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

    public function testQueueWorkerCanClaimObserverMirroredRunningBuildOperation(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $body = $this->extractControllerMethodSource($source, 'claimActiveOperationExecution');

        $allowUnclaimedQueueOffset = \strpos($body, "\$claimSource === 'queue'\n                    && \$claimedBy === ''");
        $duplicateStreamOffset = \strpos($body, "return ['ok' => false, 'reason' => 'duplicate_stream']");

        self::assertNotFalse($allowUnclaimedQueueOffset, 'Queue worker must claim observer-mirrored running operations.');
        self::assertNotFalse($duplicateStreamOffset, 'Duplicate-stream guard missing.');
        self::assertLessThan($duplicateStreamOffset, $allowUnclaimedQueueOffset);
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

    public function testBuildStartFromPublishStageUsesVisualEditScope(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $body = $this->extractControllerMethodSource($source, 'handleStartBuild');

        $publishStageOffset = \strpos($body, '$startStage === AiSiteAgentSession::STAGE_PUBLISH');
        $visualEditStageOffset = \strpos($body, '$startStage = AiSiteAgentSession::STAGE_VISUAL_EDIT;');
        $planFallbackOffset = \strpos($body, '$startStage = AiSiteAgentSession::STAGE_PLAN;');

        self::assertIsInt($publishStageOffset);
        self::assertIsInt($visualEditStageOffset);
        self::assertIsInt($planFallbackOffset);
        self::assertLessThan(
            $visualEditStageOffset,
            $publishStageOffset,
            'Build repair launched from publish stage must remap to the visual-edit build scope.'
        );
        self::assertLessThan(
            $planFallbackOffset,
            $visualEditStageOffset,
            'Publish-stage remap must happen before the non-visual fallback.'
        );
    }

    private function extractControllerMethodSource(string $source, string $methodName): string
    {
        $methodOffset = \strpos($source, 'private function ' . $methodName);
        self::assertNotFalse($methodOffset, $methodName . ' missing');
        $nextMethodOffset = \strpos($source, 'private function ', $methodOffset + 1);

        return $nextMethodOffset === false
            ? \substr($source, $methodOffset)
            : \substr($source, $methodOffset, $nextMethodOffset - $methodOffset);
    }
}
