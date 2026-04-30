<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

class AiSiteBuildTaskServiceTest extends TestCase
{
    public function testEnsureTaskScopeBuildsSharedAndPageTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertIsArray($scope['build_blueprint'] ?? null);
        $this->assertIsArray($scope['build_tasks'] ?? null);
        $this->assertArrayHasKey('shared:header', $scope['build_tasks']);
        $this->assertArrayHasKey('shared:footer', $scope['build_tasks']);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['shared:header']['status']);
        $this->assertNotEmpty($scope['build_blueprint']['tasks']);
    }

    public function testBuildScopePatchCannotMutatePlanContractBeforeBuild(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $patch = $service->stripBuildPlanMutationScopePatch([
            'site_title' => 'Keep editable profile field',
            'task_plan_confirmed' => 1,
            'task_plan_structured' => ['shared_tasks' => [['task_key' => 'shared:header']]],
            'virtual_theme_plan' => ['confirmed' => ['shared_tasks' => [['task_key' => 'shared:header']]]],
            'build_blueprint' => ['tasks' => [['task_key' => 'shared:header']]],
            'build_tasks' => ['shared:header' => ['status' => AiSiteBuildTaskService::TASK_STATUS_PENDING]],
        ], []);

        $this->assertSame(['site_title' => 'Keep editable profile field'], $patch);
    }

    public function testEnsureTaskScopeUsesConfirmedStageTwoExecutionBlueprintWhenAvailable(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'stage2-confirmed-signature',
                    'shared_tasks' => [
                        [
                            'task_key' => 'shared:header',
                            'label' => 'Header',
                            'region' => 'header',
                            'sort_order' => 10,
                        ],
                        [
                            'task_key' => 'shared:footer',
                            'label' => 'Footer',
                            'region' => 'footer',
                            'sort_order' => 20,
                        ],
                    ],
                    'page_tasks' => [
                        'home_page' => [
                            [
                                'task_key' => 'page:home_page:hero',
                                'page_type' => 'home_page',
                                'section_code' => 'hero',
                                'label' => 'Hero',
                                'sort_order' => 110,
                                'runtime_context' => [
                                    'block_key' => 'hero',
                                ],
                            ],
                        ],
                    ],
                    'execution_blueprint' => [
                        'tasks' => [
                            [
                                'task_key' => 'shared:header',
                                'sort_order' => 10,
                                'can_parallel' => false,
                                'progress_weight' => 1.0,
                            ],
                            [
                                'task_key' => 'shared:footer',
                                'sort_order' => 20,
                                'can_parallel' => false,
                                'progress_weight' => 1.0,
                            ],
                            [
                                'task_key' => 'page:home_page:hero',
                                'page_type' => 'home_page',
                                'sort_order' => 110,
                                'dependencies' => ['shared:header', 'shared:footer'],
                                'can_parallel' => true,
                                'progress_weight' => 2.5,
                                'runtime_context' => [
                                    'block_key' => 'hero',
                                    'origin' => 'stage2',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertSame('stage2_confirmed_task_plan', $scope['build_blueprint']['source'] ?? null);
        $this->assertSame('stage2-confirmed-signature', $scope['build_blueprint']['task_plan_signature'] ?? null);
        $this->assertSame(
            ['shared:header', 'shared:footer', 'page:home_page:hero'],
            \array_column($scope['build_blueprint']['tasks'] ?? [], 'task_key')
        );
        $pageTaskDefinition = \array_values(\array_filter(
            $scope['build_blueprint']['tasks'] ?? [],
            static fn(array $task): bool => ($task['task_key'] ?? '') === 'page:home_page:hero'
        ));
        $this->assertCount(1, $pageTaskDefinition);
        $this->assertSame('content/home-page-hero', $pageTaskDefinition[0]['section_code'] ?? null);
        $this->assertSame('home_page', $pageTaskDefinition[0]['page_type'] ?? null);
        $this->assertSame(['shared:header', 'shared:footer'], $pageTaskDefinition[0]['dependencies'] ?? []);
        $this->assertSame(
            ['block_key' => 'hero', 'origin' => 'stage2'],
            $pageTaskDefinition[0]['runtime_context'] ?? []
        );
        $this->assertSame(
            ['task_key', 'status', 'attempt_no', 'message', 'result_ref', 'updated_at', 'started_at', 'finished_at'],
            \array_keys($scope['build_tasks']['page:home_page:hero'] ?? [])
        );
    }

    public function testEnsureTaskScopeIgnoresTopLevelStageOneExecutionBlueprintForStageTwoBuild(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $stageOneTasks = [
            ['task_key' => 'shared:header', 'sort_order' => 10],
            ['task_key' => 'shared:footer', 'sort_order' => 20],
            ['task_key' => 'page:home_page:a', 'page_type' => 'home_page', 'sort_order' => 30],
            ['task_key' => 'page:home_page:b', 'page_type' => 'home_page', 'sort_order' => 40],
        ];

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'task_plan_confirmed' => 1,
            'execution_blueprint' => [
                'tasks' => $stageOneTasks,
            ],
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'sig',
                    'execution_blueprint' => [
                        'tasks' => [
                            ['task_key' => 'shared:header', 'sort_order' => 10],
                            ['task_key' => 'shared:footer', 'sort_order' => 20],
                        ],
                    ],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertSame(
            ['shared:header', 'shared:footer'],
            \array_column($scope['build_blueprint']['tasks'] ?? [], 'task_key')
        );
        $this->assertArrayNotHasKey('page:home_page:a', $scope['build_tasks']);
        $this->assertArrayNotHasKey('page:home_page:b', $scope['build_tasks']);
    }

    public function testEnsureTaskScopeUsesStructuredTaskListsWhenRicherThanConfirmedEmbeddedTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'sig',
                    'shared_tasks' => [
                        ['task_key' => 'shared:header', 'sort_order' => 10],
                        ['task_key' => 'shared:footer', 'sort_order' => 20],
                    ],
                    'page_tasks' => [
                        'home_page' => [
                            ['task_key' => 'page:home_page:t1', 'sort_order' => 30],
                            ['task_key' => 'page:home_page:t2', 'sort_order' => 40],
                            ['task_key' => 'page:home_page:t3', 'sort_order' => 50],
                        ],
                    ],
                    'execution_blueprint' => [
                        'tasks' => [
                            ['task_key' => 'shared:header', 'sort_order' => 10],
                            ['task_key' => 'shared:footer', 'sort_order' => 20],
                            ['task_key' => 'page:home_page:t1', 'page_type' => 'home_page', 'sort_order' => 30],
                        ],
                    ],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertSame(
            ['shared:header', 'shared:footer', 'page:home_page:t1', 'page:home_page:t2', 'page:home_page:t3'],
            \array_column($scope['build_blueprint']['tasks'] ?? [], 'task_key')
        );
    }

    public function testEnsureTaskScopeUsesStructuredTaskListsWhenRicherThanTopLevelBlueprint(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'task_plan_confirmed' => 1,
            'execution_blueprint' => [
                'tasks' => [
                    ['task_key' => 'shared:header', 'sort_order' => 10],
                    ['task_key' => 'page:home_page:t1', 'page_type' => 'home_page', 'sort_order' => 30],
                ],
            ],
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'sig',
                    'shared_tasks' => [
                        ['task_key' => 'shared:header', 'sort_order' => 10],
                        ['task_key' => 'shared:footer', 'sort_order' => 20],
                    ],
                    'page_tasks' => [
                        'home_page' => [
                            ['task_key' => 'page:home_page:t1', 'sort_order' => 30],
                            ['task_key' => 'page:home_page:t2', 'sort_order' => 40],
                            ['task_key' => 'page:home_page:t3', 'sort_order' => 50],
                        ],
                    ],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertSame(
            ['shared:header', 'shared:footer', 'page:home_page:t1', 'page:home_page:t2', 'page:home_page:t3'],
            \array_column($scope['build_blueprint']['tasks'] ?? [], 'task_key')
        );
        $this->assertArrayHasKey('page:home_page:t3', $scope['build_tasks']);
    }

    public function testEnsureTaskScopeUsesFullStructuredPlanWhenConfirmedSnapshotIsCurrentPageOnly(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'task_plan_confirmed' => 1,
            'task_plan_structured' => [
                'signature' => 'full-plan',
                'shared_tasks' => [
                    ['task_key' => 'shared:header', 'sort_order' => 10],
                    ['task_key' => 'shared:footer', 'sort_order' => 20],
                ],
                'page_tasks' => [
                    'home_page' => [
                        ['task_key' => 'page:home_page:hero', 'sort_order' => 30],
                        ['task_key' => 'page:home_page:trust', 'sort_order' => 40],
                        ['task_key' => 'page:home_page:cta', 'sort_order' => 50],
                    ],
                    'about_page' => [
                        ['task_key' => 'page:about_page:story', 'sort_order' => 60],
                        ['task_key' => 'page:about_page:team', 'sort_order' => 70],
                    ],
                    'contact_page' => [
                        ['task_key' => 'page:contact_page:form', 'sort_order' => 80],
                        ['task_key' => 'page:contact_page:faq', 'sort_order' => 90],
                    ],
                ],
            ],
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'partial-current-page',
                    'shared_tasks' => [
                        ['task_key' => 'shared:header', 'sort_order' => 10],
                        ['task_key' => 'shared:footer', 'sort_order' => 20],
                    ],
                    'page_tasks' => [
                        'home_page' => [
                            ['task_key' => 'page:home_page:hero', 'sort_order' => 30],
                            ['task_key' => 'page:home_page:trust', 'sort_order' => 40],
                            ['task_key' => 'page:home_page:cta', 'sort_order' => 50],
                        ],
                    ],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertSame(
            [
                'shared:header',
                'shared:footer',
                'page:home_page:hero',
                'page:home_page:trust',
                'page:home_page:cta',
                'page:about_page:story',
                'page:about_page:team',
                'page:contact_page:form',
                'page:contact_page:faq',
            ],
            \array_column($scope['build_blueprint']['tasks'] ?? [], 'task_key')
        );
        $this->assertSame(['home_page', 'about_page', 'contact_page'], $scope['build_blueprint']['page_types'] ?? []);
        $this->assertCount(9, $scope['build_tasks']);
    }

    public function testEnsureTaskScopeRepairsStaleConfirmedFlagWhenConfirmedExecutionBlueprintExists(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'task_plan_confirmed' => 0,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'confirmed-but-flag-stale',
                    'execution_blueprint' => [
                        'tasks' => [
                            [
                                'task_key' => 'shared:header',
                                'sort_order' => 10,
                            ],
                            [
                                'task_key' => 'page:home_page:hero',
                                'page_type' => 'home_page',
                                'section_code' => 'hero',
                                'sort_order' => 20,
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertSame(1, (int)($scope['task_plan_confirmed'] ?? 0));
        $this->assertSame('stage2_confirmed_task_plan', $scope['build_blueprint']['source'] ?? null);
        $this->assertSame(
            ['shared:header', 'page:home_page:hero'],
            \array_column($scope['build_blueprint']['tasks'] ?? [], 'task_key')
        );
    }

    public function testFreshRepairResetsAttemptCountForQualityRetry(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = [
            'build_tasks' => [
                'page:home_page:hero' => [
                    'task_key' => 'page:home_page:hero',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
                    'attempt_no' => 3,
                    'message' => 'old failure',
                    'result_ref' => ['page_type' => 'home_page'],
                    'started_at' => '2026-01-01 00:00:00',
                    'finished_at' => '2026-01-01 00:01:00',
                ],
            ],
        ];

        $scope = $service->markTaskPendingForFreshRepair($scope, 'page:home_page:hero', 'Quality gate retry');

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['page:home_page:hero']['status']);
        self::assertSame(0, $scope['build_tasks']['page:home_page:hero']['attempt_no']);
        self::assertSame('Quality gate retry', $scope['build_tasks']['page:home_page:hero']['message']);
        self::assertSame([], $scope['build_tasks']['page:home_page:hero']['result_ref']);
        self::assertSame('', $scope['build_tasks']['page:home_page:hero']['started_at']);
        self::assertSame('', $scope['build_tasks']['page:home_page:hero']['finished_at']);
    }

    public function testFreshRepairResetOnlyTouchesFailedBlueprintTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = [
            'build_blueprint' => [
                'tasks' => [
                    ['task_key' => 'page:home_page:hero'],
                    ['task_key' => 'page:about_page:story'],
                    ['task_key' => 'page:contact_page:form'],
                ],
            ],
            'build_tasks' => [
                'page:home_page:hero' => [
                    'task_key' => 'page:home_page:hero',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
                    'attempt_no' => 3,
                    'message' => 'old failure',
                    'result_ref' => ['page_type' => 'home_page'],
                    'started_at' => '2026-01-01 00:00:00',
                    'finished_at' => '2026-01-01 00:01:00',
                ],
                'page:about_page:story' => [
                    'task_key' => 'page:about_page:story',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
                    'attempt_no' => 1,
                    'message' => '',
                    'result_ref' => ['page_type' => 'about_page'],
                ],
                'orphan:failed' => [
                    'task_key' => 'orphan:failed',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
                    'attempt_no' => 3,
                    'message' => 'stale orphan',
                ],
            ],
            'retryable_ai_failures' => [
                'build' => [
                    'items' => [
                        'page:contact_page:form' => [
                            'operation' => 'build',
                            'item_key' => 'page:contact_page:form',
                            'item_type' => 'page_section',
                            'retry_scope' => 'build_task',
                            'message' => 'ledger-only failure',
                        ],
                        'orphan:ledger' => [
                            'operation' => 'build',
                            'item_key' => 'orphan:ledger',
                            'item_type' => 'page_section',
                            'retry_scope' => 'build_task',
                            'message' => 'orphan ledger failure',
                        ],
                    ],
                    'updated_at' => '2026-01-01 00:02:00',
                ],
            ],
        ];

        $scope = $service->resetFailedTasksForFreshRepair($scope, 'Fresh build repair');

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['page:home_page:hero']['status']);
        self::assertSame(0, $scope['build_tasks']['page:home_page:hero']['attempt_no']);
        self::assertSame([], $scope['build_tasks']['page:home_page:hero']['result_ref']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $scope['build_tasks']['page:about_page:story']['status']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['page:contact_page:form']['status']);
        self::assertSame(0, $scope['build_tasks']['page:contact_page:form']['attempt_no']);
        self::assertSame([], $scope['build_tasks']['page:contact_page:form']['result_ref']);
        self::assertSame('Fresh build repair', $scope['build_tasks']['page:contact_page:form']['message']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_FAILED, $scope['build_tasks']['orphan:failed']['status']);
        self::assertArrayNotHasKey('orphan:ledger', $scope['build_tasks']);
        self::assertSame([], $scope['retryable_ai_failures'] ?? []);
        self::assertSame(0, (int)($scope['retryable_ai_failure_count'] ?? 0));
    }

    public function testInterruptedBuildResetOnlyTouchesRunningBlueprintTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = [
            'build_blueprint' => [
                'tasks' => [
                    ['task_key' => 'page:contact_page:faq'],
                    ['task_key' => 'page:about_page:story'],
                    ['task_key' => 'page:home_page:hero'],
                ],
            ],
            'build_tasks' => [
                'page:contact_page:faq' => [
                    'task_key' => 'page:contact_page:faq',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
                    'attempt_no' => 2,
                    'message' => 'generating',
                    'result_ref' => ['page_type' => 'contact_page'],
                    'started_at' => '2026-01-01 00:00:00',
                    'finished_at' => '',
                ],
                'page:about_page:story' => [
                    'task_key' => 'page:about_page:story',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
                    'attempt_no' => 1,
                    'message' => '',
                    'result_ref' => ['page_type' => 'about_page'],
                ],
                'page:home_page:hero' => [
                    'task_key' => 'page:home_page:hero',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
                    'attempt_no' => 3,
                    'message' => 'quality failure',
                ],
                'orphan:running' => [
                    'task_key' => 'orphan:running',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
                    'attempt_no' => 2,
                ],
            ],
        ];

        $scope = $service->resetRunningTasksForInterruptedBuild($scope, 'Provider interrupted build');

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['page:contact_page:faq']['status']);
        self::assertSame(0, $scope['build_tasks']['page:contact_page:faq']['attempt_no']);
        self::assertSame('Provider interrupted build', $scope['build_tasks']['page:contact_page:faq']['message']);
        self::assertSame([], $scope['build_tasks']['page:contact_page:faq']['result_ref']);
        self::assertSame('', $scope['build_tasks']['page:contact_page:faq']['started_at']);
        self::assertSame('', $scope['build_tasks']['page:contact_page:faq']['finished_at']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $scope['build_tasks']['page:about_page:story']['status']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_FAILED, $scope['build_tasks']['page:home_page:hero']['status']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_RUNNING, $scope['build_tasks']['orphan:running']['status']);
    }

    public function testSyncBuildTaskFailuresKeepsRetryEntryForLatestBuildFailureWithoutBlueprintTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = [
            'latest_build_failed' => 1,
            'publish_blocked_by_latest_ai_failure' => 1,
            'publish_blocked_reason' => 'Home hero generation failed.',
            'latest_build_failure' => [
                'blocked' => true,
                'operation' => 'build',
                'status' => 'error',
                'message' => 'Home hero generation failed.',
                'page_type' => 'home_page',
                'section_code' => 'hero',
            ],
            'retryable_ai_failures' => [],
        ];

        $scope = $service->syncBuildTaskFailuresToRetryableLedger($scope);

        self::assertSame(1, (int)($scope['retryable_ai_failure_count'] ?? 0));
        self::assertArrayHasKey('build', $scope['retryable_ai_failures']);
        self::assertArrayHasKey('home_page', $scope['retryable_ai_failures']['build']['items']);
        self::assertSame(
            'Home hero generation failed.',
            $scope['retryable_ai_failures']['build']['items']['home_page']['message'] ?? ''
        );
    }

    public function testEnsureTaskScopeReusesExistingConfirmedBuildBlueprintWhenConfirmedPlanWasCompacted(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $existingBlueprint = [
            'version' => 'v1',
            'source' => 'stage2_confirmed_task_plan',
            'signature' => 'existing-blueprint-signature',
            'task_plan_signature' => 'confirmed-task-plan-signature',
            'workspace_track' => 'virtual_theme',
            'page_types' => ['home_page'],
            'tasks' => [
                [
                    'task_key' => 'page:home_page:hero',
                    'task_type' => 'page_section',
                    'page_type' => 'home_page',
                    'section_code' => 'content/home-page-hero',
                    'sort_order' => 20,
                    'runtime_context' => ['block_key' => 'hero'],
                ],
            ],
        ];

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'confirmed-task-plan-signature',
                    '_storage_compacted' => 1,
                    'execution_blueprint_ref' => [
                        'signature' => 'existing-blueprint-signature',
                        'task_count' => 1,
                    ],
                ],
            ],
            'build_blueprint' => $existingBlueprint,
            'build_tasks' => [
                'page:home_page:hero' => [
                    'task_key' => 'page:home_page:hero',
                    'runtime_context' => ['block_key' => 'stale-duplicate'],
                    'task_script' => ['story_goal' => 'duplicate'],
                    'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
                    'attempt_no' => 2,
                    'message' => 'working',
                    'result_ref' => ['component_code' => 'hero'],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertTrue($service->hasConfirmedTaskPlanForBuild($scope));
        $this->assertSame(1, (int)($scope['task_plan_confirmed'] ?? 0));
        $this->assertSame($existingBlueprint, $scope['build_blueprint']);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_RUNNING, $scope['build_tasks']['page:home_page:hero']['status'] ?? null);
        $this->assertSame(2, $scope['build_tasks']['page:home_page:hero']['attempt_no'] ?? null);
        $this->assertSame(['component_code' => 'hero'], $scope['build_tasks']['page:home_page:hero']['result_ref'] ?? null);
        $this->assertArrayNotHasKey('runtime_context', $scope['build_tasks']['page:home_page:hero']);
        $this->assertArrayNotHasKey('task_script', $scope['build_tasks']['page:home_page:hero']);
    }

    public function testSummarizeReflectsDoneAndPendingTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'html_blocks');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $summary = $service->summarize($scope);

        $this->assertGreaterThan(0, $summary['total']);
        $this->assertSame(1, $summary['done']);
        $this->assertGreaterThan(0, $summary['pending']);
        $this->assertArrayHasKey('shared', $summary['groups']);
        $this->assertIsArray($summary['groups']['shared']['tasks'] ?? null);
        $this->assertNotEmpty($summary['groups']['shared']['tasks'] ?? []);
    }

    public function testPickConcurrentTasksKeepsSharedTasksExclusiveUntilSharedGateIsSatisfied(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $initial = $service->pickConcurrentTasks($scope, 3);
        $this->assertSame(['shared:header', 'shared:footer'], \array_column($initial, 'task_key'));

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $stillSharedOnly = $service->pickConcurrentTasks($scope, 3);
        $this->assertSame(['shared:footer'], \array_column($stillSharedOnly, 'task_key'));

        $scope['build_tasks']['shared:footer']['status'] = AiSiteBuildTaskService::TASK_STATUS_CANCELLED;
        $pageTasks = $service->pickConcurrentTasks($scope, 2);
        $pageTaskKeys = \array_column($pageTasks, 'task_key');

        $this->assertCount(2, $pageTasks);
        $this->assertContains('page:home_page:content/home-page-hero', $pageTaskKeys);
        $this->assertContains('page:about_page:content/about-page-hero', $pageTaskKeys);
    }

    public function testPickConcurrentTasksDefaultsToAllCurrentlySchedulableTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $service->markTaskDone($scope, 'shared:footer', ['region' => 'footer']);

        $picked = $service->pickConcurrentTasks($scope);
        $pickedKeys = \array_column($picked, 'task_key');

        $this->assertContains('page:home_page:content/home-page-hero', $pickedKeys);
        $this->assertContains('page:about_page:content/about-page-hero', $pickedKeys);
        $this->assertGreaterThan(2, \count($pickedKeys));
    }

    public function testPickConcurrentTasksHonorsNonParallelTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'unit-test-signature',
                    'shared_tasks' => [
                        [
                            'task_key' => 'shared:header',
                            'region' => 'header',
                            'label' => 'Header',
                            'sort_order' => 10,
                        ],
                        [
                            'task_key' => 'shared:footer',
                            'region' => 'footer',
                            'label' => 'Footer',
                            'sort_order' => 20,
                        ],
                    ],
                    'page_tasks' => [
                        'home_page' => [
                            [
                                'task_key' => 'page:home_page:content/home-page-hero',
                                'section_code' => 'content/home-page-hero',
                                'label' => 'Home Hero',
                                'sort_order' => 30,
                            ],
                        ],
                        'about_page' => [
                            [
                                'task_key' => 'page:about_page:content/about-page-hero',
                                'section_code' => 'content/about-page-hero',
                                'label' => 'About Hero',
                                'sort_order' => 40,
                            ],
                        ],
                    ],
                    'execution_blueprint' => [
                        'tasks' => [
                            [
                                'task_key' => 'shared:header',
                                'dependencies' => [],
                                'can_parallel' => true,
                                'sort_order' => 10,
                            ],
                            [
                                'task_key' => 'shared:footer',
                                'dependencies' => [],
                                'can_parallel' => true,
                                'sort_order' => 20,
                            ],
                            [
                                'task_key' => 'page:home_page:content/home-page-hero',
                                'page_type' => 'home_page',
                                'dependencies' => ['shared:header', 'shared:footer'],
                                'can_parallel' => false,
                                'sort_order' => 30,
                            ],
                            [
                                'task_key' => 'page:about_page:content/about-page-hero',
                                'page_type' => 'about_page',
                                'dependencies' => ['shared:header', 'shared:footer'],
                                'can_parallel' => true,
                                'sort_order' => 40,
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $service->markTaskDone($scope, 'shared:footer', ['region' => 'footer']);

        $picked = $service->pickConcurrentTasks($scope, 3);

        $this->assertSame(['page:home_page:content/home-page-hero'], \array_column($picked, 'task_key'));
    }

    public function testListPendingTasksSkipsCancelledAndRunningTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $scope = $service->markTaskRunning($scope, 'shared:header');
        $scope['build_tasks']['shared:footer']['status'] = AiSiteBuildTaskService::TASK_STATUS_CANCELLED;

        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertNotContains('shared:header', $pendingKeys);
        $this->assertNotContains('shared:footer', $pendingKeys);
        $this->assertTrue($service->hasPendingTasks($scope));
    }

    public function testReconcileGeneratedArtifactsMarksPersistedPendingTasksDoneBeforeDispatch(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $service->markTaskRunning($scope, 'shared:header');
        $scope = $service->markTaskRunning($scope, $pageTaskKey);
        $scope['shared_components']['header'] = ['region' => 'header', 'html' => '<header>Ready</header>'];
        $scope['page_type_layouts']['home_page']['content'][] = [
            'code' => 'content/home-page-hero',
            'component' => 'content/home-page-hero',
        ];

        $scope = $service->reconcileGeneratedArtifactsWithTaskState($scope);
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $scope['build_tasks']['shared:header']['status'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $scope['build_tasks'][$pageTaskKey]['status'] ?? null);
        $this->assertNotContains('shared:header', $pendingKeys);
        $this->assertNotContains($pageTaskKey, $pendingKeys);
    }

    public function testForceRebuildResetPreservesPersistedGeneratedArtifactsForResume(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope['build_tasks']['shared:header']['status'] = AiSiteBuildTaskService::TASK_STATUS_PENDING;
        $scope['build_tasks'][$pageTaskKey]['status'] = AiSiteBuildTaskService::TASK_STATUS_PENDING;
        $scope['shared_components']['header'] = ['region' => 'header', 'html' => '<header>Ready</header>'];
        $scope['page_type_layouts']['home_page']['content'][] = [
            'code' => 'content/home-page-hero',
            'component' => 'content/home-page-hero',
        ];

        $scope = $service->resetBuildTasksToPendingForRebuild($scope);
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $scope['build_tasks']['shared:header']['status'] ?? null);
        $this->assertSame(['region' => 'header'], $scope['build_tasks']['shared:header']['result_ref'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $scope['build_tasks'][$pageTaskKey]['status'] ?? null);
        $this->assertSame(
            ['page_type' => 'home_page', 'section_code' => 'content/home-page-hero'],
            $scope['build_tasks'][$pageTaskKey]['result_ref'] ?? null
        );
        $this->assertNotContains('shared:header', $pendingKeys);
        $this->assertNotContains($pageTaskKey, $pendingKeys);
    }

    public function testForceRebuildResetRetriesDoneTaskWhenGeneratedArtifactIsMissing(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);

        $scope = $service->resetBuildTasksToPendingForRebuild($scope);

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['shared:header']['status'] ?? null);
        $this->assertSame([], $scope['build_tasks']['shared:header']['result_ref'] ?? null);
    }

    public function testSummarizeTracksCancelledTasksWithoutPretendingTheyArePending(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope['build_tasks']['shared:footer']['status'] = AiSiteBuildTaskService::TASK_STATUS_CANCELLED;

        $summary = $service->summarize($scope);

        $this->assertSame(1, $summary['done']);
        $this->assertSame(1, $summary['cancelled']);
        $this->assertGreaterThan(0, $summary['pending']);
        $this->assertSame(1, $summary['groups']['shared']['cancelled']);
        $sharedFooterTask = \array_values(\array_filter(
            $summary['groups']['shared']['tasks'],
            static fn(array $task): bool => ($task['task_key'] ?? '') === 'shared:footer'
        ));
        $this->assertCount(1, $sharedFooterTask);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_CANCELLED, $sharedFooterTask[0]['status']);
    }

    public function testResetPageTasksForRetryOnlyTouchesTargetPageTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'html_blocks');

        $scope = $service->markTaskRunning($scope, 'page:home_page:content/home-page-hero');
        $scope = $service->markTaskDone($scope, 'page:home_page:content/home-page-hero', ['page_type' => 'home_page']);
        $scope = $service->markTaskRunning($scope, 'page:about_page:content/about-page-hero');
        $scope = $service->markTaskDone($scope, 'page:about_page:content/about-page-hero', ['page_type' => 'about_page']);

        $homeAttemptsBefore = (int)($scope['build_tasks']['page:home_page:content/home-page-hero']['attempt_no'] ?? 0);
        $aboutAttemptsBefore = (int)($scope['build_tasks']['page:about_page:content/about-page-hero']['attempt_no'] ?? 0);
        $this->assertSame(1, $homeAttemptsBefore);
        $this->assertSame(1, $aboutAttemptsBefore);

        $scope = $service->resetPageTasksForRetry($scope, 'home_page');

        $this->assertSame(
            AiSiteBuildTaskService::TASK_STATUS_PENDING,
            $scope['build_tasks']['page:home_page:content/home-page-hero']['status']
        );
        $this->assertSame(
            AiSiteBuildTaskService::TASK_STATUS_DONE,
            $scope['build_tasks']['page:about_page:content/about-page-hero']['status']
        );
        $this->assertSame(2, (int)($scope['build_tasks']['page:home_page:content/home-page-hero']['attempt_no'] ?? 0));
        $this->assertSame(1, (int)($scope['build_tasks']['page:about_page:content/about-page-hero']['attempt_no'] ?? 0));
    }

    public function testArePageTasksCompleteOnlyTurnsTrueAfterAllPageTasksAreDone(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertFalse($service->arePageTasksComplete($scope, 'home_page'));

        foreach ($service->listTaskKeysByPageType($scope, 'home_page') as $taskKey) {
            $scope = $service->markTaskDone($scope, $taskKey, ['page_type' => 'home_page']);
        }

        $this->assertTrue($service->arePageTasksComplete($scope, 'home_page'));
        $this->assertFalse($service->arePageTasksComplete($scope, 'about_page'));
    }

    public function testHasUnfinishedBlueprintTasksCountsRunningMarkers(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = [
            'build_blueprint' => [
                'tasks' => [
                    [
                        'task_key' => 'page:contact_page:faq',
                        'task_type' => 'page_section',
                        'page_type' => 'contact_page',
                        'section_code' => 'faq',
                        'group_key' => 'contact_page',
                    ],
                ],
            ],
            'build_tasks' => [
                'page:contact_page:faq' => [
                    'task_key' => 'page:contact_page:faq',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
                ],
            ],
        ];

        self::assertTrue($service->hasUnfinishedBlueprintTasks($scope));
        self::assertSame([], $service->listPendingTasks($scope));
    }

    public function testFinalizeBuildTaskStatesMarksDoneWhenArtifactAlreadyPresent(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = [
            'build_blueprint' => [
                'tasks' => [
                    [
                        'task_key' => 'page:contact_page:faq',
                        'task_type' => 'page_section',
                        'page_type' => 'contact_page',
                        'section_code' => 'faq',
                        'group_key' => 'contact_page',
                    ],
                ],
            ],
            'build_tasks' => [
                'page:contact_page:faq' => [
                    'task_key' => 'page:contact_page:faq',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
                ],
            ],
            'page_type_layouts' => [
                'contact_page' => [
                    'content' => [
                        ['code' => 'faq'],
                    ],
                ],
            ],
        ];

        $next = $service->finalizeBuildTaskStatesAfterRunLoop($scope);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $next['build_tasks']['page:contact_page:faq']['status']);
        self::assertFalse($service->hasUnfinishedBlueprintTasks($next));
    }

    public function testFinalizeBuildTaskStatesLeavesPendingWhenNoArtifact(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = [
            'build_blueprint' => [
                'tasks' => [
                    [
                        'task_key' => 'page:contact_page:missing',
                        'task_type' => 'page_section',
                        'page_type' => 'contact_page',
                        'section_code' => 'missing_section',
                        'group_key' => 'contact_page',
                    ],
                ],
            ],
            'build_tasks' => [
                'page:contact_page:missing' => [
                    'task_key' => 'page:contact_page:missing',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
                ],
            ],
            'page_type_layouts' => [
                'contact_page' => ['content' => []],
            ],
        ];

        $next = $service->finalizeBuildTaskStatesAfterRunLoop($scope);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $next['build_tasks']['page:contact_page:missing']['status']);
        self::assertTrue($service->hasUnfinishedBlueprintTasks($next));
    }
}
