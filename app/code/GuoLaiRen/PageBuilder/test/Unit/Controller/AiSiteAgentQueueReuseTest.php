<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
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
        self::assertSame(
            'glr_aisite:session:42:queue_slot:block_partial_patch',
            $method->invoke($controller, 42, 'block_partial_patch')
        );
    }

    public function testQueueJobTypeStillDistinguishesPlanningAndBuildPhases(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'resolveAiSiteQueueJobType');
        $method->setAccessible(true);

        self::assertSame('stage1.requirement_expand', $method->invoke($controller, 'plan'));
        self::assertSame('', $method->invoke($controller, 'task_plan'));
        self::assertSame('virtual_theme.tree.build', $method->invoke($controller, 'build'));
        self::assertSame('virtual_theme.block.regenerate', $method->invoke($controller, 'block_regenerate'));
        self::assertSame('virtual_theme.block.partial_patch', $method->invoke($controller, 'block_partial_patch'));
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
            ['glr_aisite:session:7:stage:workspace:operation:task_plan'],
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
        self::assertSame(
            ['glr_aisite:session:7:stage:visual_edit:operation:block_partial_patch'],
            $method->invoke($controller, 7, 'block_partial_patch')
        );
    }

    public function testQueueBizKeyLookupPrefersLatestReusableSlotRecord(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        self::assertStringContainsString("->order(\\Weline\\Queue\\Model\\Queue::schema_fields_ID, 'DESC')", $source);
    }

    public function testStageOnePlanPersistenceDetectionRequiresRealPlanArtifacts(): void
    {
        $reflection = new ReflectionClass(AiSiteAgent::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('scopeCompatibilityService');
        $property->setAccessible(true);
        $property->setValue($controller, new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer()));
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
        self::assertFalse($method->invoke($controller, ['plan_json' => ['pages' => [['page_type' => 'home_page']]]]));
        self::assertFalse($method->invoke($controller, ['plan_workbench' => ['draft' => ['summary' => 'draft']]]));
        self::assertFalse($method->invoke($controller, ['plan_markdown' => 'stage-one markdown']));
        self::assertTrue($method->invoke($controller, ['plan_json' => $this->buildUsableStageOnePlanJson()]));
    }

    public function testQueuedOperationStartOnlyReusesSameNonPlanQueueOperation(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldReuseRunningQueuedOperation');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($controller, 'task_plan', 'task_plan'));
        self::assertTrue($method->invoke($controller, 'build', 'build'));
        self::assertTrue($method->invoke($controller, 'block_regenerate', 'block_regenerate'));
        self::assertTrue($method->invoke($controller, 'block_partial_patch', 'block_partial_patch'));
        self::assertTrue($method->invoke($controller, 'regenerate_page', 'regenerate_page'));
        self::assertFalse($method->invoke($controller, 'build', 'task_plan'));
        self::assertFalse($method->invoke($controller, 'block_regenerate', 'build'));
        self::assertFalse($method->invoke($controller, 'task_plan', 'build'));
        self::assertFalse($method->invoke($controller, 'regenerate_page', 'block_regenerate'));
        self::assertFalse($method->invoke($controller, 'block_partial_patch', 'block_regenerate'));
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

    public function testLegacyTaskPlanQueueOperationIsNotSupported(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $isQueueBacked = new ReflectionMethod(AiSiteAgent::class, 'isAiSiteQueueBackedOperation');
        $isQueueBacked->setAccessible(true);
        $resolveQueueClass = new ReflectionMethod(AiSiteAgent::class, 'resolveAiSiteQueueClass');
        $resolveQueueClass->setAccessible(true);

        self::assertFalse($isQueueBacked->invoke($controller, 'task_plan'));
        self::assertSame('', $resolveQueueClass->invoke($controller, 'task_plan'));
    }

    public function testBuildStartDoesNotCarryLegacyTaskPlanDiscardPath(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $handleSource = $this->extractControllerMethodSource($source, 'handleStartBuild');

        self::assertStringNotContainsString('markRunningTaskPlanAsDiscardedForRebuild', $source);
        self::assertStringNotContainsString("operation'] ?? '') === 'task_plan'", $handleSource);
        self::assertStringContainsString('isBuildPlanReadyForBuild($mergedScope)', $handleSource);
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
        self::assertSame([], $patch['build_plan_v2']);
        self::assertSame([], $patch['plan_projection']);
        self::assertSame([], $patch['content_manifest']);
        self::assertSame(0, $patch['build_plan_confirmed']);
        self::assertSame([], $patch['build_blueprint']);
        self::assertSame([], $patch['build_tasks']);
        self::assertArrayNotHasKey('task_plan_confirmed', $patch);
        self::assertArrayNotHasKey('virtual_theme_plan', $patch);
    }

    public function testLegacyTaskPlanRebuildResetMethodIsDeleted(): void
    {
        self::assertFalse((new ReflectionClass(AiSiteAgent::class))->hasMethod('buildTaskPlanRegenerationResetScopePatch'));
    }

    public function testLegacyTaskPlanQueueNoLongerProvidesRebuildResetPatch(): void
    {
        self::assertFileDoesNotExist(\dirname(__DIR__, 3) . '/Queue/AiSiteTaskPlanQueue.php');
    }

    public function testDefaultTaskPlanStartReuseShortcutIsDeleted(): void
    {
        self::assertFalse((new ReflectionClass(AiSiteAgent::class))->hasMethod('shouldReusePersistedTaskPlanWithoutQueue'));
    }

    public function testStageOneResumeModeBypassesPureReuseShortcutAndKeepsCheckpointPrompting(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        // resume_plan 必须与 rebuild / refine 共存于 plan prompt mode 白名单：refine 已被加入兼容老 UI 入口，
        // 但 resume 模式仍要绕过 pure reuse shortcut，保留 checkpoint prompting。
        self::assertStringContainsString("'rebuild', 'resume_plan'", $source);
        self::assertStringContainsString("\$effectivePlanPromptMode = 'resume_plan';", $source);
        self::assertStringContainsString("\$requestedPromptMode === 'resume_plan'", $source);
        self::assertStringContainsString("'resume_generation'", $source);
    }

    public function testRetryablePlanFailuresDefaultToResumeInsteadOfForcedRebuild(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $handleStartPlan = $this->extractControllerMethodSource($source, 'handleStartPlan');

        self::assertStringContainsString('if ($hasRetryablePlanFailures)', $handleStartPlan);
        self::assertStringContainsString("\$effectivePlanPromptMode = \$requestedPromptMode === 'rebuild'", $handleStartPlan);
        self::assertStringContainsString(": 'resume_plan';", $handleStartPlan);
        self::assertStringContainsString("\$planOperationDetails['resume_failed_tasks'] = 1;", $handleStartPlan);
        self::assertStringContainsString("elseif (\$operation === 'plan')", $source);
        self::assertStringContainsString("\$details['prompt_mode'] = 'resume_plan';", $source);
    }

    public function testTaskPlanQueueMutationGuardIsDeletedWithLegacyQueueExecution(): void
    {
        self::assertFileDoesNotExist(\dirname(__DIR__, 3) . '/Queue/AiSiteTaskPlanQueue.php');
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

    public function testForcedBuildQueuesIgnoreExistingArtifactsBeforePickingPendingTasks(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        foreach (['runHtmlBlocksBuildOperationV3', 'runVirtualThemeBuildOperationV3'] as $methodName) {
            $methodSource = $this->extractControllerMethodSource($source, $methodName);
            $forceOffset = \strpos($methodSource, '$queueForcedAiRebuild = (int)($scope[\'_queue_force_build\'][\'active\'] ?? 0) === 1;');
            $clearOffset = \strpos($methodSource, 'clearBuildArtifactsForRegeneration($scope)', $forceOffset === false ? 0 : $forceOffset);
            $resetOffset = \strpos($methodSource, 'resetBuildTasksToPendingForRebuild($scope, false)', $forceOffset === false ? 0 : $forceOffset);
            $reconcileOffset = \strpos($methodSource, 'reconcileGeneratedArtifactsWithTaskState($scope)', $forceOffset === false ? 0 : $forceOffset);
            $pendingOffset = \strpos($methodSource, 'listPendingTasks($scope)');

            self::assertNotFalse($forceOffset, $methodName . ' missing force rebuild marker check');
            self::assertNotFalse($clearOffset, $methodName . ' must clear stale generated artifacts on force rebuild');
            self::assertNotFalse($resetOffset, $methodName . ' must reset all tasks without artifact reuse on force rebuild');
            self::assertNotFalse($reconcileOffset, $methodName . ' must keep normal artifact reconcile for resume');
            self::assertNotFalse($pendingOffset, $methodName . ' does not list pending tasks');
            self::assertLessThan($pendingOffset, $clearOffset);
            self::assertLessThan($pendingOffset, $resetOffset);
            self::assertLessThan($pendingOffset, $reconcileOffset);
        }
    }

    public function testBuildResumeRetriesOnlyFailedOrInterruptedCheckpointTasks(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $handleStartBuild = $this->extractControllerMethodSource($source, 'handleStartBuild');
        $startOperation = $this->extractControllerMethodSource($source, 'startOperation');

        self::assertStringContainsString('$resumeTaskCount = (int)($resumeSummary[\'pending\'] ?? 0)', $handleStartBuild);
        self::assertStringContainsString('+ (int)($resumeSummary[\'failed\'] ?? 0)', $handleStartBuild);
        self::assertStringContainsString('+ (int)($resumeSummary[\'running\'] ?? 0)', $handleStartBuild);
        self::assertStringContainsString('summarizeRetryableAiFailures($mergedScope, \'build\')', $handleStartBuild);
        self::assertStringContainsString('$isResume ? [\'resume_failed_tasks\' => 1] : [\'fresh_repair_failed_tasks\' => 1]', $handleStartBuild);

        self::assertStringContainsString('$resetFailedOrInterruptedTasks', $startOperation);
        self::assertStringContainsString('$operationDetails[\'resume_failed_tasks\']', $startOperation);
        self::assertStringContainsString('resetFailedTasksForFreshRepair(', $startOperation);
        self::assertStringContainsString('resetRunningTasksForInterruptedBuild(', $startOperation);
        self::assertStringContainsString('Resume build after previous task failure', $startOperation);
    }

    public function testRetryAiOperationResumesBuildWithoutCloningFailedQueueScopePatch(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $handleRetry = $this->extractControllerMethodSource($source, 'handleRetryAiOperation');

        self::assertStringContainsString("!\\in_array(\$operation, ['plan', 'build'], true) && \$pageType === ''", $handleRetry);
        self::assertStringContainsString("unset(\$details['fresh_repair_failed_tasks']);", $handleRetry);
        self::assertStringContainsString("\$details['resume_failed_tasks'] = 1;", $handleRetry);
        self::assertStringContainsString('$scopePatch = [];', $handleRetry);
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
        self::assertStringContainsString('Build task missing build-plan section spec', $source);
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

    public function testVirtualThemeBuildPersistsDraftWithoutMaterializingEntityPages(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $persistSource = $this->extractControllerMethodSource($source, 'persistVirtualThemeBuildScope');

        self::assertStringContainsString('dropPrePublishMaterializedPagesFromVirtualThemeScope', $persistSource);
        self::assertStringNotContainsString('materializeGeneratedPages(', $persistSource);
        self::assertStringContainsString("\$scope['pagebuilder_pages_by_type'] = [];", $source);
        self::assertStringContainsString("\$scope['materialized_pages_by_type'] = [];", $source);
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

    public function testBuildQueueDoesNotTreatTaskKeysAsExtraComponentCodesWhenComponentsAreExplicit(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Queue/AiSiteBuildQueue.php');
        $methodSource = $this->extractControllerMethodSource($source, 'resolveQueuedOperationContexts');

        $componentListOffset = \strpos($methodSource, '$componentCodes = $this->mergeStringLists([');
        $taskFallbackOffset = \strpos($methodSource, 'if ($componentCodes === [])');
        self::assertIsInt($componentListOffset);
        self::assertIsInt($taskFallbackOffset);
        self::assertGreaterThan($componentListOffset, $taskFallbackOffset);

        $explicitComponentListSource = \substr($methodSource, $componentListOffset, $taskFallbackOffset - $componentListOffset);
        self::assertStringContainsString("\$content['component_codes'] ?? null", $explicitComponentListSource);
        self::assertStringContainsString("\$details['component_codes'] ?? null", $explicitComponentListSource);
        self::assertStringNotContainsString('task_keys', $explicitComponentListSource);
        self::assertStringNotContainsString('task_key', $explicitComponentListSource);

        $fallbackSource = \substr($methodSource, $taskFallbackOffset);
        self::assertStringContainsString("\$content['task_keys'] ?? null", $fallbackSource);
        self::assertStringContainsString("\$activeDetails['task_keys'] ?? null", $fallbackSource);
    }

    public function testPlanQueueTerminalErrorCreatesRetryablePlanFailure(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Queue/AiSitePlanQueue.php');
        $methodSource = $this->extractControllerMethodSource($source, 'updateSessionError');

        self::assertStringContainsString('markPlanGenerationFailureRetryable', $methodSource);
        self::assertStringContainsString("'plan'] = \$active", $methodSource);
        self::assertStringContainsString('replaceRetryableAiFailures($scope, \'plan\'', $source);
        self::assertStringContainsString("'item_key' => 'stage1_plan'", $source);
        self::assertStringContainsString("'retry_scope' => 'plan'", $source);
    }

    public function testStageScopeWhitelistKeepsPlanRetryStateVisible(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/AiSiteAgentSessionService.php');
        $commonKeysSource = $this->extractConstantArraySource($source, 'COMMON_STAGE_SCOPE_KEYS');

        self::assertStringContainsString("'plan_generation_last_error'", $commonKeysSource);
        self::assertStringContainsString("'retryable_ai_failures'", $commonKeysSource);
        self::assertStringContainsString("'retryable_ai_failure_count'", $commonKeysSource);
        self::assertStringContainsString("'next_stage_blocked_by_ai_failures'", $commonKeysSource);
        self::assertStringContainsString("'source_truth_contract'", $commonKeysSource);
        self::assertStringContainsString("'asset_manifest_hash'", $commonKeysSource);

        $planKeysSource = $this->extractConstantArraySource($source, 'PLAN_STAGE_SCOPE_KEYS');
        $visualEditKeysSource = $this->extractConstantArraySource($source, 'VISUAL_EDIT_STAGE_SCOPE_KEYS');
        self::assertStringContainsString("'_plan_generation_checkpoint'", $planKeysSource);
        self::assertStringContainsString("'plan_generation_progress'", $planKeysSource);
        self::assertStringNotContainsString("'_task_plan_generation_checkpoint'", $visualEditKeysSource);
    }

    public function testPlanResumeBypassesDuplicatePersistedArtifactGuardAndLegacyTaskPlanQueueIsDeleted(): void
    {
        $planQueueSource = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Queue/AiSitePlanQueue.php');
        $planExecuteSource = $this->extractFunctionSource($planQueueSource, 'execute');

        self::assertStringContainsString('hasQueuedPlanResumeRequest($content)', $planExecuteSource);
        self::assertStringContainsString('$hasQueuedPlanMutation || $hasQueuedPlanResume', $planExecuteSource);
        self::assertStringContainsString("return \$promptMode === 'resume_plan';", $planQueueSource);
        self::assertFileDoesNotExist(\dirname(__DIR__, 3) . '/Queue/AiSiteTaskPlanQueue.php');
    }

    public function testImageAssetQueueRecordsSlotErrorWithoutThrowingWholeQueue(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Queue/AiSiteAssetQueue.php');
        $executeSource = $this->extractFunctionSource($source, 'execute');

        self::assertStringContainsString('recordError(', $executeSource);
        self::assertStringContainsString("'asset_generation_failed'", $executeSource);
        self::assertStringContainsString('Image asset generation failed and was recorded for retry', $executeSource);
        self::assertStringNotContainsString(
            "throw new \\RuntimeException('Image asset generation failed:",
            $executeSource
        );
    }

    public function testImageAssetQueuePatchesVirtualThemeOnlyBeforePublish(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Queue/AiSiteAssetQueue.php');
        $patchSource = $this->extractFunctionSource($source, 'applyGeneratedImagePatchToScope');

        self::assertStringContainsString('$scope[\'virtual_pages_by_type\'] = $virtualPages;', $patchSource);
        self::assertStringNotContainsString('pagebuilder_pages_by_type', $patchSource);
        self::assertStringNotContainsString('setAiLayoutArray', $source);
        self::assertStringNotContainsString('persistMaterializedPageAiLayoutBlocks', $source);
    }

    public function testWorkspaceHtmlBlockResolverReadsVirtualThemeOnlyBeforePublish(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $resolverSource = $this->extractFunctionSource($source, 'resolveExistingAiHtmlBlocksForPage');

        self::assertStringContainsString('$scope[\'virtual_pages_by_type\'][$pageType][\'blocks\'] ?? null', $resolverSource);
        self::assertStringNotContainsString('$scope[\'pagebuilder_pages_by_type\'][$pageType][\'ai_layout\'][\'blocks\'] ?? null', $source);
        self::assertStringNotContainsString('$page->getAiLayoutArray()', $source);
    }

    public function testWorkspaceHydrateShowsPlanRetryButtonFromPersistedFailureState(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');
        $hydrateSource = $this->extractFunctionSource($source, 'hydrateWorkspaceFromState');
        $retryResolverSource = $this->extractFunctionSource($source, 'shouldShowPlanRetryButtonFromWorkspaceState');

        self::assertStringContainsString(
            'setPlanRetryButtonVisible(shouldShowPlanRetryButtonFromWorkspaceState(workspaceState))',
            $hydrateSource
        );
        self::assertStringContainsString("getRetryableAiFailureCount(state, 'plan') > 0", $retryResolverSource);
        self::assertStringContainsString('plan_generation_last_error', $retryResolverSource);
        self::assertStringContainsString("operations.plan", $retryResolverSource);
        // 失败状态识别已封装为 isPlanFailureStatusForWorkspaceUi（覆盖 error/failed/fail/stop/cancelled），
        // 比裸字符串 'error' 更鲁棒；测试同时确保它在 retry 解析中真的被调用。
        self::assertStringContainsString('isPlanFailureStatusForWorkspaceUi(status)', $retryResolverSource);
    }

    public function testOperationStreamServerErrorClosesEventSourceBeforeRetryUi(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        $streamSource = $this->extractFunctionSource($source, 'startOperationStream');
        $serverErrorOffset = \strpos($streamSource, "source.addEventListener('error'");
        self::assertNotFalse($serverErrorOffset, 'server error SSE handler missing');

        $serverErrorSource = \substr($streamSource, $serverErrorOffset, \strpos($streamSource, "source.addEventListener('warning'", $serverErrorOffset) - $serverErrorOffset);
        self::assertStringContainsString('settled = true;', $serverErrorSource);
        self::assertStringContainsString('closeOperationSource(source);', $serverErrorSource);
        self::assertStringContainsString("markBuildOperationTerminalForRuntimeUi(operation, 'error', lastServerError)", $serverErrorSource);
        self::assertStringContainsString('resetOperationUiOnFailure(operation)', $serverErrorSource);
        self::assertStringContainsString('offerRetryForFailedOperation(operation, payload)', $serverErrorSource);
        self::assertLessThan(
            \strpos($serverErrorSource, 'offerRetryForFailedOperation(operation, payload)'),
            \strpos($serverErrorSource, 'closeOperationSource(source);'),
            'server error must close EventSource before showing retry UI'
        );

        $transportErrorOffset = \strpos($streamSource, 'source.onerror = function ()');
        self::assertNotFalse($transportErrorOffset, 'transport error handler missing');
        $transportErrorSource = \substr($streamSource, $transportErrorOffset);
        self::assertStringContainsString('closeOperationSource(source);', $transportErrorSource);
        self::assertLessThan(
            \strpos($transportErrorSource, 'offerRetryForFailedOperation(operation, failurePayload)'),
            \strpos($transportErrorSource, 'closeOperationSource(source);'),
            'transport error must close EventSource before showing retry UI'
        );
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

    public function testBuildRequiresConfirmedBuildPlanBeforeQueueStart(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $handleSource = $this->extractControllerMethodSource($source, 'handleStartBuild');

        $confirmedOffset = \strpos($handleSource, "if (!\$this->isBuildPlanReadyForBuild(\$mergedScope))");
        $ensureTaskOffset = \strpos($handleSource, "\$mergedScope = \$this->buildTaskService->ensureTaskScope(");
        $startOffset = \strpos($handleSource, "\$startResult = \$this->startOperation(");

        self::assertNotFalse($confirmedOffset, 'build must guard on confirmed build_plan_v2');
        self::assertNotFalse($ensureTaskOffset, 'confirmed build_plan_v2 should hydrate build tasks before queue start');
        self::assertNotFalse($startOffset, 'build startOperation call missing');
        self::assertLessThan($confirmedOffset, $ensureTaskOffset);
        self::assertLessThan($startOffset, $confirmedOffset);
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

    public function testForeignSessionQueueRuntimeStateIsDiscarded(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'discardForeignAiSiteQueueRuntimeState');
        $method->setAccessible(true);

        $scope = [
            'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            'active_operation' => [
                'operation' => 'build',
                'status' => 'done',
                'job_key' => 'glr_aisite:session:1793:job:virtual_theme.tree.build',
                'queue_id' => 1910,
            ],
            'active_operations' => [
                'plan' => [
                    'operation' => 'plan',
                    'job_key' => 'glr_aisite:session:1793:job:stage1.requirement_expand',
                ],
                'publish' => [
                    'operation' => 'publish',
                    'job_key' => 'glr_aisite:session:1794:job:virtual_theme.publish',
                ],
            ],
            'build_queue_info' => [
                'content' => [
                    'job_key' => 'glr_aisite:session:1793:job:virtual_theme.tree.build',
                ],
            ],
        ];

        $cleaned = $method->invoke($controller, $scope, 1794);

        self::assertSame([], $cleaned['active_operation']);
        self::assertArrayNotHasKey('plan', $cleaned['active_operations']);
        self::assertArrayHasKey('publish', $cleaned['active_operations']);
        self::assertArrayNotHasKey('build_queue_info', $cleaned);
        self::assertSame(AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH, $cleaned['workspace_status']);
    }

    public function testCurrentSessionQueueRuntimeStateIsPreserved(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'discardForeignAiSiteQueueRuntimeState');
        $method->setAccessible(true);

        $scope = [
            'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            'active_operation' => [
                'operation' => 'build',
                'status' => 'queued',
                'job_key' => 'glr_aisite:session:1794:job:virtual_theme.tree.build',
            ],
            'active_operations' => [
                'build' => [
                    'operation' => 'build',
                    'job_key' => 'glr_aisite:session:1794:job:virtual_theme.tree.build',
                ],
            ],
        ];

        self::assertSame($scope, $method->invoke($controller, $scope, 1794));
    }

    public function testStartBuildDoesNotRunSynchronousAiPreflightBeforeQueue(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'requiresFrontendAiProviderReadinessCheck');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($controller, 'build'));
        self::assertFalse($method->invoke($controller, 'plan'));
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

    public function testOperationSseKeepsQueuedObserverStreamOpenForLongRunningQueueOperations(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $body = $this->extractControllerMethodSource($source, 'handleOperationSse');
        $observer = $this->extractControllerMethodSource($source, 'observeDuplicateOperationStream');

        self::assertStringContainsString(
            '!$this->shouldKeepQueuedObserverStreamOpen($operation)',
            $body
        );
        self::assertStringNotContainsString(
            "if (\$queueWaitingForScheduler && (bool)(\$observed['deferred_queue_progress'] ?? false)) {\n                return;",
            $body
        );
        self::assertStringContainsString('$maxObserveResumeCycles = 720', $body);
        self::assertStringContainsString('$emitDeferredQueueHandoff', $body);
        self::assertStringContainsString("'queue_status' => \$queueStatusForObserver", $body);
        self::assertStringNotContainsString("\$operation === 'plan'", $observer);
    }

    public function testQueueContentNoLongerCarriesTaskPlanPromptModeFallback(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $body = $this->extractControllerMethodSource($source, 'enqueueOperationQueueTask');

        self::assertStringContainsString("_plan_sse_request", $body);
        self::assertStringNotContainsString("_task_plan_sse_request", $body);
        self::assertStringNotContainsString("resume_task_plan", $body);
    }

    public function testStartOperationChecksLiveQueueRowsBeforeCreatingNewQueue(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $body = $this->extractControllerMethodSource($source, 'startOperation');
        $tableGuardOffset = \strpos($body, 'findActiveAiSiteSessionQueueRow($session, $operation)');
        $tokenOffset = \strpos($body, '\\bin2hex(\\random_bytes(16))');
        $enqueueOffset = \strpos($body, 'enqueueOperationQueueTask($freshForQueue');

        self::assertNotFalse($tableGuardOffset, 'startOperation must check queue table before allocating a new token');
        self::assertNotFalse($tokenOffset, 'new operation token allocation missing');
        self::assertNotFalse($enqueueOffset, 'queue enqueue missing');
        self::assertLessThan($tokenOffset, $tableGuardOffset);
        self::assertLessThan($enqueueOffset, $tableGuardOffset);
    }

    public function testStartOperationReturnsQueuedCheckpointWithoutFullWorkspaceHydration(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $body = $this->extractControllerMethodSource($source, 'startOperation');

        self::assertStringContainsString('buildQueuedOperationCheckpointState(', $body);
        self::assertStringContainsString("'data' => \$state", $body);
        self::assertStringNotContainsString('buildWorkspaceState($fresh', $body);
        self::assertStringNotContainsString('buildWorkspaceOperationPayload($state, $operation)', $body);
    }

    public function testQueueTableStartGuardScansAllSessionSlotsAndRequiresLiveRunningPid(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $finder = $this->extractControllerMethodSource($source, 'findActiveAiSiteSessionQueueRow');
        $operationResolver = $this->extractControllerMethodSource($source, 'resolveAiSiteQueueBackedOperationsForStartGuard');
        $liveness = $this->extractControllerMethodSource($source, 'isAiSiteQueueRowLiveInProgress');

        self::assertStringContainsString('resolveAiSiteQueueBackedOperationsForStartGuard($requestedOperation)', $finder);
        self::assertStringContainsString('resolveAiSiteQueueLookupBizKeys((int)$session->getId(), $operation)', $finder);
        self::assertStringContainsString('findAiSiteQueueRowsByBizKey($bizKey)', $finder);
        self::assertStringContainsString("'plan'", $operationResolver);
        self::assertStringContainsString("'build'", $operationResolver);
        self::assertStringContainsString("'block_regenerate'", $operationResolver);
        self::assertStringContainsString("'image_asset'", $operationResolver);
        self::assertStringContainsString('Processer::isRunningByPid($pid)', $liveness);
    }

    public function testRunningQueueReuseClearsPreviousLatestBuildFailureBeforeReturningSnapshot(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $body = $this->extractControllerMethodSource($source, 'buildActiveAiSiteQueueAlreadyRunningResult');

        self::assertStringContainsString('$shouldSuppressPriorBuildFailure = $sameOperation', $body);
        self::assertStringContainsString('&& $this->isPublishBlockingAiBuildOperation($runningOperation)', $body);
        self::assertStringContainsString('&& $this->isPublishBlockingAiRunningStatus($operationStatus);', $body);
        self::assertStringContainsString("\$scope['latest_build_failed'] = 0;", $body);
        self::assertStringContainsString("\$scope['publish_blocked_by_latest_ai_failure'] = 0;", $body);
        self::assertStringContainsString("unset(\$scope['latest_build_failure'], \$scope['publish_blocked_reason']);", $body);

        $workspaceStateBody = $this->extractControllerMethodSource($source, 'buildWorkspaceState');
        $failureResolverBody = $this->extractControllerMethodSource($source, 'resolveLatestPublishBlockingAiBuildFailure');
        $runningStateBody = $this->extractControllerMethodSource($source, 'hasPublishBlockingAiBuildRunningState');
        self::assertStringContainsString('$publishBlockingAiRunning = $this->hasPublishBlockingAiBuildRunningState($normalized, $activeOperation, $buildQueueInfo);', $workspaceStateBody);
        self::assertStringContainsString('$this->hasPublishBlockingAiBuildRunningState($scope, $activeOperation, $buildQueueInfo)', $failureResolverBody);
        self::assertStringContainsString('$this->readAiQueueInfoStatus($buildQueueInfo)', $runningStateBody);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUsableStageOnePlanJson(): array
    {
        return [
            'requirement_expansion' => [
                'original_brief' => 'Build a real website.',
                'expanded_brief' => 'Build a real conversion website for the selected audience.',
                'planning_summary' => 'Use a strong landing page and supporting trust pages.',
                'site_goal' => 'Convert visitors into leads.',
                'page_strategy' => [
                    ['page_type' => 'home_page', 'intent' => 'Explain value and drive action.'],
                ],
            ],
            'theme_design' => [
                'theme_purpose' => 'Create a coherent visual system.',
                'selection_reason' => 'Matches the target buyer and content needs.',
                'color_scheme' => [
                    'primary' => '#1D4ED8',
                    'accent' => '#F97316',
                ],
                'typography_spacing_radius' => [
                    'font_family' => 'Aptos',
                    'spacing_scale' => 'comfortable',
                ],
                'visual_keywords' => ['credible', 'direct'],
            ],
            'shared_components' => [
                'header' => [
                    'goal' => 'Make navigation clear.',
                    'implementation_detail' => 'Show logo, primary navigation, and CTA.',
                ],
                'footer' => [
                    'goal' => 'Close with trust and useful links.',
                    'implementation_detail' => 'Show links, contact notes, and legal navigation.',
                ],
            ],
        ];
    }

    private function extractControllerMethodSource(string $source, string $methodName): string
    {
        $methodOffset = \strpos($source, 'private function ' . $methodName);
        self::assertNotFalse($methodOffset, $methodName . ' missing');
        $nextMethodOffset = \strpos($source, 'private function ', $methodOffset + 1);

        $methodSource = $nextMethodOffset === false
            ? \substr($source, $methodOffset)
            : \substr($source, $methodOffset, $nextMethodOffset - $methodOffset);

        return \str_replace(["\r\n", "\r"], "\n", $methodSource);
    }

    private function extractConstantArraySource(string $source, string $constantName): string
    {
        $constantOffset = \strpos($source, 'const ' . $constantName . ' = [');
        self::assertNotFalse($constantOffset, $constantName . ' missing');
        $endOffset = \strpos($source, '];', $constantOffset);
        self::assertNotFalse($endOffset, $constantName . ' array end missing');

        return \str_replace(["\r\n", "\r"], "\n", \substr($source, $constantOffset, $endOffset - $constantOffset));
    }

    private function extractFunctionSource(string $source, string $functionName): string
    {
        $functionOffset = \strpos($source, 'function ' . $functionName);
        self::assertNotFalse($functionOffset, $functionName . ' missing');

        // 找下一个方法声明作为右边界：支持 `private/protected/public function`、`static function`、
        // 以及无修饰符 `function`（4 空格缩进，类成员层级）。
        // 旧版本只匹配 `\n    function ` 会漏掉所有带可见性修饰符的下一个方法，导致 $functionSource
        // 一路展开到文件末尾，把后续无关方法的内容当成本函数体，让 NotContains 类断言误报。
        $boundaryPatterns = [
            "\n    private function ",
            "\n    protected function ",
            "\n    public function ",
            "\n    private static function ",
            "\n    protected static function ",
            "\n    public static function ",
            "\n    static function ",
            "\n    function ",
        ];
        $nextFunctionOffset = false;
        foreach ($boundaryPatterns as $pattern) {
            $candidate = \strpos($source, $pattern, $functionOffset + 1);
            if ($candidate !== false && ($nextFunctionOffset === false || $candidate < $nextFunctionOffset)) {
                $nextFunctionOffset = $candidate;
            }
        }

        $functionSource = $nextFunctionOffset === false
            ? \substr($source, $functionOffset)
            : \substr($source, $functionOffset, $nextFunctionOffset - $functionOffset);

        return \str_replace(["\r\n", "\r"], "\n", $functionSource);
    }
}
