<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
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

    public function testEnsureTaskScopeUsesConfirmedBuildPlanV2ContractWhenAvailable(): void
    {
        $buildPlanService = new AiSiteBuildPlanService();
        $buildPlan = $buildPlanService->confirm($buildPlanService->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'execution_blueprint_draft' => [
                'signature' => 'stage-one-signature',
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Convert qualified buyers with clear proof.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'title' => 'Launch reliable AI workflows',
                                'goal' => 'Show the core value with a direct CTA.',
                            ],
                            [
                                'block_key' => 'trust',
                                'title' => 'Proof that operations stay reliable',
                                'goal' => 'Show concrete evidence before conversion.',
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'build_plan_v2' => $buildPlan,
            'build_plan_confirmed' => 1,
        ], [], 'virtual_theme');

        $this->assertSame('build_plan_v2', $scope['build_blueprint']['source'] ?? null);
        $this->assertSame($buildPlan['contract_meta']['id'], $scope['build_blueprint']['build_plan_contract_id'] ?? null);
        $this->assertSame($buildPlan['contract_meta']['signature'], $scope['build_blueprint']['build_plan_signature'] ?? null);
        $this->assertSame(
            ['shared:header', 'shared:footer', 'page:home_page:hero', 'page:home_page:trust'],
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
            $buildPlan['contract_meta']['id'],
            $pageTaskDefinition[0]['runtime_context']['build_plan_contract_id'] ?? null
        );
        $this->assertSame(
            ['task_key', 'status', 'attempt_no', 'message', 'result_ref', 'updated_at', 'started_at', 'finished_at'],
            \array_keys($scope['build_tasks']['page:home_page:hero'] ?? [])
        );
    }

    public function testEnsureTaskScopeUsesConfirmedBuildPlanV2BeforeLegacyTaskPlan(): void
    {
        $buildPlanService = new AiSiteBuildPlanService();
        $buildPlan = $buildPlanService->confirm($buildPlanService->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
        ]));
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'build_plan_v2' => $buildPlan,
            'build_plan_confirmed' => 1,
            'task_plan_confirmed' => 0,
        ], [], 'virtual_theme');

        $this->assertSame('build_plan_v2', $scope['build_blueprint']['source'] ?? null);
        $this->assertSame(1, $scope['build_plan_confirmed'] ?? 0);
        $this->assertArrayHasKey('shared:header', $scope['build_tasks']);
        $this->assertArrayHasKey('page:home_page:hero', $scope['build_tasks']);
    }

    public function testEnsureTaskScopeIgnoresLegacyTaskPlanContractsWhenBuildPlanV2IsMissing(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'legacy-should-not-win',
                    'shared_tasks' => [
                        ['task_key' => 'shared:legacy-only', 'sort_order' => 10],
                    ],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertNotSame('stage2_confirmed_task_plan', $scope['build_blueprint']['source'] ?? '');
        $this->assertSame(0, (int)($scope['build_plan_confirmed'] ?? 0));
        $this->assertArrayHasKey('shared:header', $scope['build_tasks']);
        $this->assertArrayHasKey('page:home_page:content/home-page-hero', $scope['build_tasks']);
        $this->assertArrayNotHasKey('shared:legacy-only', $scope['build_tasks']);
    }

    public function testHasConfirmedBuildPlanForBuildIgnoresLegacyTaskPlanOnlyScope(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $this->assertFalse($service->hasConfirmedBuildPlanForBuild([
            'task_plan_confirmed' => 1,
            'task_plan_structured' => [
                'shared_tasks' => [['task_key' => 'shared:header']],
            ],
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'legacy-confirmed-signature',
                    'page_tasks' => [
                        'home_page' => [['task_key' => 'page:home_page:hero']],
                    ],
                ],
            ],
        ]));
    }

    public function testHasConfirmedBuildPlanForBuildAcceptsConfirmedExecutionBlueprint(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $this->assertTrue($service->hasConfirmedBuildPlanForBuild([
            'plan_confirmed' => 1,
            'execution_blueprint_confirmed_signature' => 'stage-one-confirmed',
            'execution_blueprint' => [
                'pages' => [
                    ['page_type' => 'home_page', 'title' => 'Home'],
                ],
                'tasks' => [
                    ['task_key' => 'page:home_page:hero', 'page_type' => 'home_page'],
                ],
            ],
        ]));
    }

    public function testNormalizeConfirmedBuildPlanFlagRepairsStaleBuildPlanConfirmedFlag(): void
    {
        $buildPlanService = new AiSiteBuildPlanService();
        $buildPlan = $buildPlanService->confirm($buildPlanService->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
        ]));
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'build_plan_v2' => $buildPlan,
            'build_plan_confirmed' => 0,
        ], [], 'virtual_theme');

        $this->assertSame(1, (int)($scope['build_plan_confirmed'] ?? 0));
        $this->assertSame('build_plan_v2', $scope['build_blueprint']['source'] ?? null);
        $this->assertArrayHasKey('page:home_page:hero', $scope['build_tasks']);
    }

    public function testEnsureTaskScopeUsesFullBuildPlanV2PageGraph(): void
    {
        $buildPlanService = new AiSiteBuildPlanService();
        $buildPlan = $buildPlanService->confirm($buildPlanService->buildFromScope([
            'page_types' => ['home_page', 'about_page', 'contact_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'execution_blueprint_draft' => [
                'signature' => 'stage-one-signature',
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Convert qualified buyers.',
                        'blocks' => [
                            ['block_key' => 'hero'],
                            ['block_key' => 'trust'],
                            ['block_key' => 'cta'],
                        ],
                    ],
                    'about_page' => [
                        'title' => 'About',
                        'page_goal' => 'Explain the company story.',
                        'blocks' => [
                            ['block_key' => 'story'],
                            ['block_key' => 'team'],
                        ],
                    ],
                    'contact_page' => [
                        'title' => 'Contact',
                        'page_goal' => 'Make contact easy.',
                        'blocks' => [
                            ['block_key' => 'form'],
                            ['block_key' => 'faq'],
                        ],
                    ],
                ],
            ],
        ]));
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page', 'contact_page'],
            'build_plan_v2' => $buildPlan,
            'build_plan_confirmed' => 1,
        ], [], 'virtual_theme');

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

    public function testEnsureTaskScopeUsesStageOnePagePlansWhenExecutionPagesAreEmpty(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
            'plan_confirmed' => 1,
            'execution_blueprint_confirmed_signature' => 'stage-one-confirmed',
            'execution_blueprint' => [
                'page_plans' => [
                    'home_page' => [
                        'blocks' => [
                            ['block_key' => 'hero'],
                            ['block_key' => 'trust'],
                        ],
                    ],
                    'about_page' => [
                        'blocks' => [
                            ['block_key' => 'story'],
                        ],
                    ],
                ],
                'pages' => [],
            ],
        ], [], 'virtual_theme');

        self::assertSame('stage1_execution_blueprint', $scope['build_blueprint']['source'] ?? null);
        self::assertSame(
            [
                'shared:header',
                'shared:footer',
                'page:home_page:hero',
                'page:home_page:trust',
                'page:about_page:story',
            ],
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

    public function testEnsureTaskScopeReusesExistingConfirmedBuildPlanV2Blueprint(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $existingBlueprint = [
            'version' => 'v1',
            'source' => 'build_plan_v2',
            'signature' => 'existing-blueprint-signature',
            'build_plan_contract_id' => 'build_plan_v2_current',
            'build_plan_signature' => 'confirmed-build-plan-signature',
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
            'build_plan_confirmed' => 1,
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

        $this->assertTrue($service->hasConfirmedBuildPlanForBuild($scope));
        $this->assertSame(1, (int)($scope['build_plan_confirmed'] ?? 0));
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
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');
        foreach ($scope['build_blueprint']['tasks'] as &$task) {
            if (($task['task_key'] ?? '') === 'page:home_page:content/home-page-hero') {
                $task['can_parallel'] = false;
            }
        }
        unset($task);

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

    public function testBuildResumeResetPreservesDoneTasksAndRetriesOnlyFailedOrInterruptedTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $service->markTaskRunning($scope, 'shared:footer');
        $scope = $service->markTaskFailed($scope, $pageTaskKey, 'AI generation failed.');
        $scope = $service->resetFailedTasksForFreshRepair($scope, 'Resume build after previous task failure');
        $scope = $service->resetRunningTasksForInterruptedBuild($scope, 'Resume build after interrupted task execution');
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $scope['build_tasks']['shared:header']['status'] ?? null);
        $this->assertSame(['region' => 'header'], $scope['build_tasks']['shared:header']['result_ref'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['shared:footer']['status'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks'][$pageTaskKey]['status'] ?? null);
        $this->assertNotContains('shared:header', $pendingKeys);
        $this->assertContains('shared:footer', $pendingKeys);
        $this->assertContains($pageTaskKey, $pendingKeys);
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
        $scope['shared_components']['header'] = [
            'region' => 'header',
            'code' => 'header/ai-site-header',
            'html' => '<header>Ready</header>',
        ];
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
        $scope['shared_components']['header'] = [
            'region' => 'header',
            'code' => 'header/ai-site-header',
            'html' => '<header>Ready</header>',
        ];
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

    public function testForceRebuildResetCanIgnorePersistedArtifactsForFullRegeneration(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope['build_tasks']['shared:header']['status'] = AiSiteBuildTaskService::TASK_STATUS_DONE;
        $scope['build_tasks'][$pageTaskKey]['status'] = AiSiteBuildTaskService::TASK_STATUS_DONE;
        $scope['shared_components']['header'] = [
            'region' => 'header',
            'code' => 'header/ai-site-header',
            'html' => '<header>Ready</header>',
        ];
        $scope['page_type_layouts']['home_page']['content'][] = [
            'code' => 'content/home-page-hero',
            'component' => 'content/home-page-hero',
        ];

        $scope = $service->resetBuildTasksToPendingForRebuild($scope, false);
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['shared:header']['status'] ?? null);
        $this->assertSame([], $scope['build_tasks']['shared:header']['result_ref'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks'][$pageTaskKey]['status'] ?? null);
        $this->assertSame([], $scope['build_tasks'][$pageTaskKey]['result_ref'] ?? null);
        $this->assertContains('shared:header', $pendingKeys);
        $this->assertContains($pageTaskKey, $pendingKeys);
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

    public function testFinalizeBuildTaskStatesWritesRenderDataContractWhenBuildComplete(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = [
            'workspace_track' => 'virtual_theme',
            'build_blueprint' => [
                'source' => 'build_plan_v2',
                'signature' => 'build-blueprint-signature',
                'build_plan_contract_id' => 'build_plan_v2_current',
                'build_plan_signature' => 'build-plan-signature',
                'workspace_track' => 'virtual_theme',
                'page_types' => ['home_page'],
                'tasks' => [
                    [
                        'task_key' => 'shared:header',
                        'task_type' => 'shared_component',
                        'region' => 'header',
                        'group_key' => 'shared',
                    ],
                    [
                        'task_key' => 'page:home_page:hero',
                        'task_type' => 'page_section',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-hero',
                        'group_key' => 'home_page',
                    ],
                ],
            ],
            'build_tasks' => [
                'shared:header' => [
                    'task_key' => 'shared:header',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
                ],
                'page:home_page:hero' => [
                    'task_key' => 'page:home_page:hero',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
                ],
            ],
            'shared_components' => [
                'header' => ['html' => '<header>Ready</header>'],
            ],
            'page_type_layouts' => [
                'home_page' => [
                    'title' => 'Ready Home Page',
                    'description' => 'Ready home page with trustworthy conversion copy for visitors.',
                    'h1' => 'Ready Home Page',
                    'content' => [
                        [
                            'code' => 'content/home-page-hero',
                            'component' => 'content/home-page-hero',
                            'title' => 'Ready Home Page',
                            'description' => 'A concrete home section with visitor-facing proof and a direct action.',
                            'design_tags' => [
                                'visual' => ['trust band'],
                                'motion' => ['subtle reveal'],
                            ],
                        ],
                    ],
                ],
                'blog_list' => [
                    'title' => 'Unselected Blog',
                    'content' => [],
                ],
            ],
            'materialized_pages_by_type' => [
                'home_page' => [
                    'page_id' => 123,
                    'seo_title' => 'Ready Home Page Conversion Guide',
                    'seo_description' => 'Ready home page content with trust proof, action copy, and a clear visitor path.',
                    'h1' => 'Ready Home Page',
                ],
            ],
            'asset_manifest' => [
                'version' => 1,
                'slots' => [],
            ],
        ];

        $next = $service->finalizeBuildTaskStatesAfterRunLoop($scope);
        $contract = $next['render_data_contract'] ?? [];

        $this->assertSame(ContractType::TYPE_RENDER_DATA, $contract['contract_meta']['type'] ?? null);
        $this->assertSame(ContractType::STAGE_BUILD, $contract['contract_meta']['stage'] ?? null);
        $this->assertSame('build-blueprint-signature', $contract['payload']['build_blueprint_signature'] ?? null);
        $this->assertSame(['home_page'], $contract['payload']['page_types'] ?? null);
        $this->assertSame(
            ['home_page' => $scope['page_type_layouts']['home_page']],
            $contract['payload']['page_type_layouts'] ?? null
        );
        $this->assertArrayNotHasKey('blog_list', $contract['payload']['page_type_layouts'] ?? []);
        $this->assertSame(2, $contract['payload']['build_summary']['done'] ?? null);
        $this->assertSame('build_plan_v2', $contract['source_contracts'][0]['type'] ?? null);
        $this->assertSame($contract, $next['build_contracts'][ContractType::TYPE_RENDER_DATA] ?? null);
        $this->assertSame($contract, $next['build_workbench']['contracts'][ContractType::TYPE_RENDER_DATA] ?? null);

        $qaReport = $next['qa_report_contract'] ?? [];
        $this->assertSame(ContractType::TYPE_QA_REPORT, $qaReport['contract_meta']['type'] ?? null);
        $this->assertSame('pass', $qaReport['payload']['status'] ?? null);
        $this->assertSame(0, $qaReport['payload']['summary']['finding_count'] ?? null);
        $this->assertSame($qaReport, $next['build_contracts'][ContractType::TYPE_QA_REPORT] ?? null);
        $this->assertSame($qaReport, $next['build_workbench']['contracts'][ContractType::TYPE_QA_REPORT] ?? null);
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

    public function testFinalizeBuildTaskStatesThrowsWhenRenderDataQualityGateFails(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = [
            'workspace_track' => 'virtual_theme',
            'build_blueprint' => [
                'source' => 'build_plan_v2',
                'signature' => 'build-blueprint-signature',
                'build_plan_contract_id' => 'build_plan_v2_current',
                'build_plan_signature' => 'build-plan-signature',
                'workspace_track' => 'virtual_theme',
                'page_types' => ['home_page'],
                'tasks' => [
                    [
                        'task_key' => 'page:home_page:hero',
                        'task_type' => 'page_section',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-hero',
                        'group_key' => 'home_page',
                    ],
                ],
            ],
            'build_tasks' => [
                'page:home_page:hero' => [
                    'task_key' => 'page:home_page:hero',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
                ],
            ],
            'page_type_layouts' => [
                'home_page' => [
                    'title' => 'Home',
                    'description' => 'Home description.',
                    'h1' => 'Home',
                    'content' => [
                        [
                            'code' => 'content/home-page-hero',
                            'component' => 'content/home-page-hero',
                            'design_tags' => ['visual' => ['hero']],
                            'html' => '<section><div></section>',
                        ],
                    ],
                ],
            ],
            'materialized_pages_by_type' => [
                'home_page' => [
                    'page_id' => 123,
                    'seo_title' => 'Home',
                    'seo_description' => 'Home seo.',
                    'h1' => 'Home',
                ],
            ],
            'asset_manifest' => [
                'version' => 1,
                'slots' => [],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $service->finalizeBuildTaskStatesAfterRunLoop($scope);
    }

    public function testReconcileDoesNotTreatStageOneSharedPlanAsBuiltArtifact(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Teenipiya',
            'brief_description' => 'Gaming entertainment site',
        ], 'virtual_theme');
        $scope['shared_components'] = [
            'header' => [
                'task_key' => 'shared:header',
                'task_type' => 'shared_component',
                'component' => 'header',
                'goal' => 'Build the shared site header with brand recognition, navigation, and a primary CTA.',
            ],
            'footer' => [
                'task_key' => 'shared:footer',
                'task_type' => 'shared_component',
                'component' => 'footer',
                'goal' => 'Build the shared site footer with contact, policy, and support links.',
            ],
        ];

        $scope = $service->reconcileGeneratedArtifactsWithTaskState($scope);
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['shared:header']['status'] ?? null);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['shared:footer']['status'] ?? null);
        self::assertContains('shared:header', $pendingKeys);
        self::assertContains('shared:footer', $pendingKeys);
    }

    public function testFormatBuildCompletionGateFailureDetailIncludesFailedTaskMessage(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $gate = $service->inspectBuildCompletionGate([
            'build_blueprint' => [
                'tasks' => [[
                    'task_key' => 'page:blog_category:article_collection',
                    'label' => '文章集合',
                    'page_type' => 'blog_category',
                    'group_key' => 'blog_category',
                ]],
            ],
            'build_tasks' => [
                'page:blog_category:article_collection' => [
                    'task_key' => 'page:blog_category:article_collection',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
                    'message' => 'AI HTML generation timeout',
                ],
            ],
        ]);

        $detail = $service->formatBuildCompletionGateFailureDetail($gate);
        self::assertStringContainsString('page:blog_category:article_collection', $detail);
        self::assertStringContainsString('AI HTML generation timeout', $detail);
        self::assertStringContainsString('继续失败任务', $detail);
    }
}
