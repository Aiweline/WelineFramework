<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceStateHelperService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueSnapshotService;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentWorkspaceStateHelperServiceTest extends TestCase
{
    public function testBuildStateFingerprintProducesStableSha1AndChangesWithStateDiff(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $stateA = [
            'public_id' => 'pub_demo_1',
            'stage' => 'plan',
            'workspace_status' => 'draft',
            'publish_status' => 'unpublished',
            'active_operation' => ['operation' => 'plan', 'status' => 'running'],
            'virtual_theme_id' => 0,
            'scope' => ['plan_confirmed' => 0, 'task_plan_confirmed' => 0],
            'plan_queue_info' => ['snapshot' => ['queue_id' => 10, 'status' => 'running']],
        ];
        $fpA = $service->buildStateFingerprint($stateA);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $fpA);
        self::assertSame($fpA, $service->buildStateFingerprint($stateA));

        $stateB = $stateA;
        $stateB['workspace_status'] = 'confirmed';
        self::assertNotSame($fpA, $service->buildStateFingerprint($stateB));
    }

    public function testBuildStateFingerprintFallsBackToScopeFlagsWhenStateOmitsThem(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $withoutTopLevel = [
            'public_id' => 'pub_x',
            'stage' => 'visual_edit',
            'scope' => ['plan_confirmed' => 1, 'task_plan_confirmed' => 1],
        ];
        $withTopLevel = $withoutTopLevel;
        $withTopLevel['plan_confirmed'] = 1;
        $withTopLevel['task_plan_confirmed'] = 1;

        self::assertSame(
            $service->buildStateFingerprint($withTopLevel),
            $service->buildStateFingerprint($withoutTopLevel)
        );
    }

    public function testNormalizeSsePageTypesTrimsDedupAndFallsBack(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame(['home', 'about'], $service->normalizeSsePageTypes(['home', ' about ', 'home', '']));
        self::assertSame(['home'], $service->normalizeSsePageTypes([], 'home'));
        self::assertSame([], $service->normalizeSsePageTypes([], ''));
        self::assertSame(['contact'], $service->normalizeSsePageTypes(['', '  '], 'contact'));
    }

    public function testSelectVirtualPagesForSseKeepsRequestedTypesAndSkipsMissing(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $state = [
            'virtual_pages_by_type' => [
                'home' => ['title' => 'Home'],
                'about' => ['title' => 'About'],
            ],
        ];
        $selected = $service->selectVirtualPagesForSse($state, ['home', 'missing', 'about', '']);
        self::assertSame(['home', 'about'], \array_keys($selected));
        self::assertSame('Home', $selected['home']['title'] ?? null);

        self::assertSame([], $service->selectVirtualPagesForSse(['virtual_pages_by_type' => []], ['home']));
    }

    public function testEventMatchesStageByStageCodeOperationAndEventTypeWhitelists(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertTrue($service->eventMatchesStage(['stage_code' => 'plan'], 'plan'));
        self::assertTrue(
            $service->eventMatchesStage(
                ['payload' => ['operation' => 'plan']],
                'plan'
            )
        );
        self::assertTrue(
            $service->eventMatchesStage(
                ['event_type' => 'plan_rebuilt'],
                'plan'
            )
        );
        self::assertTrue(
            $service->eventMatchesStage(
                ['payload_json' => ['operation' => 'task_plan']],
                'task_plan'
            )
        );
        self::assertFalse(
            $service->eventMatchesStage(
                ['event_type' => 'plan_rebuilt'],
                'task_plan'
            )
        );
        self::assertFalse($service->eventMatchesStage(['stage_code' => 'other'], 'plan'));
    }

    public function testFilterEventsByStagePassesThroughEmptyStreamStage(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $rows = [['stage_code' => 'plan'], 'not_array', ['stage_code' => 'task_plan']];
        self::assertSame($rows, $service->filterEventsByStage($rows, ''));
    }

    public function testFilterEventsByStageKeepsOnlyMatchingRows(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $rows = [
            ['stage_code' => 'plan'],
            'garbage',
            ['payload' => ['operation' => 'task_plan']],
            ['event_type' => 'task_plan_refined'],
            ['stage_code' => 'visual_edit'],
        ];
        $filtered = $service->filterEventsByStage($rows, 'task_plan');
        self::assertCount(2, $filtered);
        self::assertSame('task_plan', $filtered[0]['payload']['operation'] ?? null);
        self::assertSame('task_plan_refined', $filtered[1]['event_type'] ?? null);
    }

    public function testFilterSnapshotByStageFiltersEventsAndTopLogs(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $snapshot = [
            'other' => 'kept',
            'events' => [['stage_code' => 'plan'], ['stage_code' => 'task_plan']],
            'top_logs' => [['event_type' => 'plan_rebuilt'], ['event_type' => 'task_plan_refined']],
        ];
        $filtered = $service->filterSnapshotByStage($snapshot, 'plan');
        self::assertSame('kept', $filtered['other'] ?? null);
        self::assertCount(1, $filtered['events']);
        self::assertSame('plan', $filtered['events'][0]['stage_code'] ?? null);
        self::assertCount(1, $filtered['top_logs']);
        self::assertSame('plan_rebuilt', $filtered['top_logs'][0]['event_type'] ?? null);

        $unchanged = $service->filterSnapshotByStage($snapshot, '');
        self::assertSame($snapshot, $unchanged);
    }

    public function testNormalizeStreamStageAcceptsSafeSlugsAndRejectsInvalidInput(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame('plan', $service->normalizeStreamStage('PLAN'));
        self::assertSame('task_plan', $service->normalizeStreamStage('  Task_Plan '));
        self::assertSame('visual-edit', $service->normalizeStreamStage('Visual-Edit'));
        self::assertSame('', $service->normalizeStreamStage(''));
        self::assertSame('', $service->normalizeStreamStage('bad stage!'));
        self::assertSame('', $service->normalizeStreamStage(\str_repeat('a', 64)));
    }

    public function testResolveLastEventIdTakesMaxAndFallsBackToLegacyColumn(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $events = [
            ['event_id' => 10],
            'garbage',
            ['event_id' => 7],
            ['ai_site_agent_event_id' => 42],
            ['event_id' => 3, 'ai_site_agent_event_id' => 99],
        ];
        self::assertSame(42, $service->resolveLastEventId($events));
        self::assertSame(0, $service->resolveLastEventId([]));
    }

    public function testPruneEventsForSseKeepsTailAndWhitelistsPayloadFields(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = [
                'event_id' => $i,
                'event_type' => 'progress',
                'stage_code' => 'plan',
                'level' => 'info',
                'payload' => [
                    'message' => 'step ' . $i,
                    'operation' => 'plan',
                    'page_type' => 'HOME',
                    'progress_percent' => $i * 10,
                    'extra_should_drop' => 'secret_' . $i,
                    'details' => [
                        'reason' => 'reason_' . $i,
                        'region' => 'region_' . $i,
                        'section_code' => 'sec_' . $i,
                        'component_code' => 'comp_' . $i,
                        'internal_flag' => 'drop_' . $i,
                    ],
                ],
                'create_time' => '2026-04-23 10:00:0' . ($i % 10),
            ];
        }

        $pruned = $service->pruneEventsForSse($rows, 3);
        self::assertCount(3, $pruned);
        self::assertSame(8, $pruned[0]['event_id']);
        self::assertSame(10, $pruned[2]['event_id']);

        $first = $pruned[0];
        self::assertArrayHasKey('payload', $first);
        self::assertSame(['message', 'operation', 'page_type', 'progress_percent', 'details'], \array_keys($first['payload']));
        self::assertSame(['reason', 'region', 'section_code', 'component_code'], \array_keys($first['payload']['details']));
        self::assertArrayNotHasKey('extra_should_drop', $first['payload']);
        self::assertArrayNotHasKey('internal_flag', $first['payload']['details']);
        self::assertSame('step 8', $first['message']);
    }

    public function testPruneEventsForSseHandlesLegacyPayloadJsonAndMissingPayload(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $rows = [
            ['event_id' => 1, 'event_type' => 'info', 'payload_json' => ['message' => 'legacy']],
            ['event_id' => 2, 'event_type' => 'info', 'message' => 'fallback_message'],
            ['event_id' => 3, 'event_type' => 'ping'],
            'garbage',
            ['ai_site_agent_event_id' => 77, 'event_type' => 'event_id_alias'],
        ];
        $pruned = $service->pruneEventsForSse($rows, 10);
        self::assertCount(4, $pruned);
        self::assertSame('legacy', $pruned[0]['message']);
        self::assertSame('fallback_message', $pruned[1]['message']);
        self::assertSame('ping', $pruned[2]['message']);
        self::assertSame(77, $pruned[3]['event_id']);
        self::assertArrayNotHasKey('payload', $pruned[2]);
        self::assertArrayNotHasKey('create_time', $pruned[2]);
    }

    public function testPruneScopeForViewUnsetsHeavyArtifactsAndKeepsLightMetadata(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $scope = [
            'public_id' => 'pub_keep',
            'plan_confirmed' => 1,
            'virtual_pages_by_type' => ['HOME' => ['blocks' => [1, 2, 3]]],
            'pagebuilder_pages_by_type' => ['HOME' => [...\range(1, 100)]],
            'preview_page_options' => [...\range(1, 20)],
            'page_type_layouts' => [1],
            'events' => [1],
            'top_logs' => [1],
            'build_task_summary' => ['total' => 10],
            'build_summary' => [1],
            'active_operation' => ['operation' => 'plan'],
            'pre_publish_visual_urls' => [1],
            'plan_json' => ['raw' => 1],
            'plan_structured' => [1],
            'execution_blueprint' => ['big' => 1],
            'execution_blueprint_draft' => [1],
            'execution_blueprint_page' => [1],
            'task_plan_structured' => [1],
            'virtual_theme_plan' => [1],
            'build_blueprint' => [1],
            'build_blueprint_page' => [1],
            'build_tasks' => [1],
            'virtual_theme_build_tree' => [1],
            'materialized_pages_by_type' => [1],
            'shared_components' => [1],
            '_ai_generated_shared_components' => [1],
        ];
        $pruned = $service->pruneScopeForView($scope);
        self::assertSame(['public_id' => 'pub_keep', 'plan_confirmed' => 1], $pruned);
    }

    public function testPruneStateForViewCollapsesTaskPlanAndUsesPrunedScope(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $state = [
            'public_id' => 'pub_2',
            'stage' => 'plan',
            'scope' => [
                'public_id' => 'pub_2',
                'plan_confirmed' => 1,
                'virtual_pages_by_type' => ['HOME' => 1],
            ],
            'plan' => [
                'markdown' => 'plan-md',
                'execution_blueprint' => ['heavy' => 1],
            ],
            'task_plan' => [
                'markdown' => 'tp-md',
                'structured' => ['huge' => 1],
                'virtual_theme_plan' => [
                    'draft' => ['heavy' => 1],
                    'confirmed' => ['heavy' => 1],
                    'confirmed_markdown' => 'confirmed-md',
                    'confirmed_at' => '2026-04-23 20:00:00',
                ],
                'extra_should_drop' => 1,
            ],
            'events' => [1],
            'plan_json' => [1],
            'plan_structured' => [1],
            'task_plan_structured' => [1],
            'task_plan_directory_tree' => [1],
            'task_plan_markdown' => 'md',
            'virtual_theme_plan' => [1],
            'execution_blueprint' => [1],
            'execution_blueprint_draft' => [1],
        ];
        $pruned = $service->pruneStateForView($state);
        self::assertSame(['public_id' => 'pub_2', 'plan_confirmed' => 1], $pruned['scope']);
        self::assertArrayNotHasKey('execution_blueprint', $pruned['plan']);
        self::assertSame(
            [
                'markdown' => 'tp-md',
                'structured' => ['huge' => 1],
                'virtual_theme_plan' => [
                    'confirmed_markdown' => 'confirmed-md',
                    'confirmed_at' => '2026-04-23 20:00:00',
                ],
            ],
            $pruned['task_plan']
        );
        foreach ([
            'events', 'plan_json', 'plan_structured', 'task_plan_structured',
            'task_plan_directory_tree', 'task_plan_markdown', 'virtual_theme_plan',
            'execution_blueprint', 'execution_blueprint_draft',
        ] as $dropped) {
            self::assertArrayNotHasKey($dropped, $pruned);
        }
        self::assertSame('pub_2', $pruned['public_id']);
        self::assertSame('plan', $pruned['stage']);
    }

    public function testCompactConfirmedTaskPlanScopeClearsConfirmedDraftDuplicatesAndPreservesOtherKeys(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $scope = [
            'task_plan_confirmed' => 1,
            'task_plan_structured' => ['keep' => 'yes'],
            'task_plan_markdown' => 'same-md',
            'virtual_theme_plan' => [
                'draft' => ['should_drop' => true],
                'draft_markdown' => 'same-md',
                'draft_generated_at' => '2026-04-23 20:00:00',
                'confirmed' => ['keep' => 'yes'],
                'confirmed_markdown' => 'same-md',
            ],
            'other' => 'kept',
        ];
        $compacted = $service->compactConfirmedTaskPlanScope($scope);
        self::assertSame([], $compacted['virtual_theme_plan']['draft']);
        self::assertSame('', $compacted['virtual_theme_plan']['draft_markdown']);
        self::assertArrayNotHasKey('draft_generated_at', $compacted['virtual_theme_plan']);
        self::assertSame(['keep' => 'yes'], $compacted['virtual_theme_plan']['confirmed']);
        self::assertSame([], $compacted['task_plan_structured']);
        self::assertSame('', $compacted['task_plan_markdown']);
        self::assertSame('kept', $compacted['other']);
        self::assertSame(1, $compacted['task_plan_confirmed']);

        $emptyScope = [];
        $compactedEmpty = $service->compactConfirmedTaskPlanScope($emptyScope);
        self::assertSame(['draft' => []], $compactedEmpty['virtual_theme_plan']);
    }

    public function testCompactConfirmedTaskPlanScopeDropsSignatureOnlyDuplicateStructuredCopy(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $scope = [
            'task_plan_confirmed' => 1,
            'task_plan_structured' => [
                'page_tasks' => ['home_page' => [['title' => 'Hero']]],
                'plan_signature' => 'sig-1',
            ],
            'virtual_theme_plan' => [
                'draft' => [],
                'confirmed' => [
                    'signature' => 'sig-1',
                    'page_tasks' => ['home_page' => [['title' => 'Hero']]],
                    'plan_signature' => 'sig-1',
                ],
                'confirmed_markdown' => '# Confirmed',
            ],
        ];

        $compacted = $service->compactConfirmedTaskPlanScope($scope);

        self::assertSame([], $compacted['task_plan_structured']);
        self::assertSame(['signature' => 'sig-1', 'page_tasks' => ['home_page' => [['title' => 'Hero']]], 'plan_signature' => 'sig-1'], $compacted['virtual_theme_plan']['confirmed']);
    }

    public function testSelectStatusQueueInfoPrefersExactKeyThenFallsBackOrDefaults(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $state = [
            'plan_queue_info' => ['queue_id' => 1],
            'task_plan_queue_info' => ['queue_id' => 2],
            'build_queue_info' => ['queue_id' => 3],
        ];
        self::assertSame(['queue_id' => 1], $service->selectStatusQueueInfo($state, 'plan'));
        self::assertSame(['queue_id' => 2], $service->selectStatusQueueInfo($state, 'task_plan'));
        self::assertSame(['queue_id' => 3], $service->selectStatusQueueInfo($state, 'build'));
        self::assertSame(['queue_id' => 3], $service->selectStatusQueueInfo($state, 'regenerate_page'));

        // unknown operation → first available fallback
        self::assertSame(['queue_id' => 1], $service->selectStatusQueueInfo($state, 'publish'));

        // exact key missing but fallback available
        self::assertSame(
            ['queue_id' => 2],
            $service->selectStatusQueueInfo(['task_plan_queue_info' => ['queue_id' => 2]], 'build')
        );

        self::assertSame([], $service->selectStatusQueueInfo([], 'plan'));
    }

    public function testNormalizeEnvelopeStatusCollapsesToCanonicalVocabulary(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame('failed', $service->normalizeEnvelopeStatus('ERROR'));
        self::assertSame('failed', $service->normalizeEnvelopeStatus(' Failed '));
        self::assertSame('cancelled', $service->normalizeEnvelopeStatus('canceled'));
        self::assertSame('cancelled', $service->normalizeEnvelopeStatus('Stopped'));
        self::assertSame('done', $service->normalizeEnvelopeStatus('Published'));
        self::assertSame('done', $service->normalizeEnvelopeStatus('ready'));
        self::assertSame('queued', $service->normalizeEnvelopeStatus('PENDING'));
        self::assertSame('running', $service->normalizeEnvelopeStatus('building'));
        self::assertSame('stale', $service->normalizeEnvelopeStatus('stale'));
        self::assertSame('mystery', $service->normalizeEnvelopeStatus('Mystery'));
        self::assertSame('', $service->normalizeEnvelopeStatus('   '));
    }

    public function testResolveEnvelopeProgressPercentPrefersActiveOperationAndClampsRange(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame(50, $service->resolveEnvelopeProgressPercent([], ['progress_percent' => 50], 'running'));
        self::assertSame(100, $service->resolveEnvelopeProgressPercent([], ['progress_percent' => 250], 'running'));
        self::assertSame(0, $service->resolveEnvelopeProgressPercent([], ['progress_percent' => -5], 'running'));

        $state = ['build_task_summary' => ['total' => 4, 'completed' => 3]];
        self::assertSame(75, $service->resolveEnvelopeProgressPercent($state, [], 'running'));

        $stateDone = ['can_publish' => true];
        self::assertSame(100, $service->resolveEnvelopeProgressPercent($stateDone, [], 'queued'));

        self::assertSame(100, $service->resolveEnvelopeProgressPercent([], [], 'done'));
        self::assertSame(0, $service->resolveEnvelopeProgressPercent([], [], 'queued'));
    }

    public function testResolveEnvelopeCursorJoinsStageOperationAndPageTypeSkippingEmpty(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        $state = ['stage' => 'plan', 'preview_page_type' => 'HOME'];
        $activeOperation = ['operation' => 'plan'];
        self::assertSame('plan/plan/HOME', $service->resolveEnvelopeCursor($state, $activeOperation));

        $activeWithPageType = ['operation' => 'task_plan', 'page_type' => 'ABOUT'];
        self::assertSame('plan/task_plan/ABOUT', $service->resolveEnvelopeCursor($state, $activeWithPageType));

        self::assertSame('', $service->resolveEnvelopeCursor([], []));
        self::assertSame('plan', $service->resolveEnvelopeCursor(['stage' => 'plan'], []));
        self::assertSame(
            'visual_edit/build',
            $service->resolveEnvelopeCursor(['stage' => 'visual_edit'], ['operation' => 'build'])
        );
    }

    public function testResolveProgressKindSwitchesBasedOnTaskPlanConfirmation(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame('queue_info', $service->resolveProgressKind([], []));
        self::assertSame('queue_info', $service->resolveProgressKind(['task_plan_confirmed' => 0], ['operation' => 'build']));
        self::assertSame(
            'task_progress',
            $service->resolveProgressKind(['task_plan_confirmed' => 1], ['operation' => 'build'])
        );
        // plan operation should stay on queue_info even when task_plan is confirmed
        self::assertSame(
            'queue_info',
            $service->resolveProgressKind(['task_plan_confirmed' => 1], ['operation' => 'plan'])
        );
    }

    public function testResolveEnvelopeUpdatedAtUsesFirstNonEmptyOrCurrentTimestamp(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame(
            '2026-04-23 10:00:00',
            $service->resolveEnvelopeUpdatedAt([], ['updated_at' => '2026-04-23 10:00:00'], [])
        );
        self::assertSame(
            '2026-04-23 09:30:00',
            $service->resolveEnvelopeUpdatedAt([], [], ['end_at' => '2026-04-23 09:30:00', 'start_at' => 'earlier'])
        );
        self::assertSame(
            'start-fallback',
            $service->resolveEnvelopeUpdatedAt([], [], ['start_at' => 'start-fallback'])
        );
        self::assertSame(
            '2026-04-22 00:00:00',
            $service->resolveEnvelopeUpdatedAt(['updated_at' => '2026-04-22 00:00:00'], [], [])
        );
        // all empty → falls back to date() — only assert format
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $service->resolveEnvelopeUpdatedAt([], [], [])
        );
    }

    public function testResolveQueueJobTypeMapsSupportedOperationsAndFallsBackToEmpty(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame('stage1.requirement_expand', $service->resolveQueueJobType('plan'));
        self::assertSame('stage2.shared.tasks', $service->resolveQueueJobType('task_plan'));
        self::assertSame('virtual_theme.tree.build', $service->resolveQueueJobType('build'));
        self::assertSame('', $service->resolveQueueJobType('publish'));
        self::assertSame('', $service->resolveQueueJobType(''));
        self::assertSame('', $service->resolveQueueJobType('unknown_op'));
    }

    public function testResolveEnvelopeTokenUsageFillsMissingFieldsFromQueueInfoThenActiveOperation(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueSnapshotService());

        $queueSnapshot = [
            'input_tokens' => 111,
            // output_tokens missing — should be filled by queueInfo
            // total_tokens missing — should be filled from queueInfo → activeOperation → derived
            // no token_cost_meta
        ];
        $queueInfo = [
            'output_tokens' => 222,
            'token_cost_meta' => ['currency' => 'USD', 'cost' => 0.01],
        ];
        $activeOperation = [
            'total_tokens' => 9999,
            'token_cost_meta' => ['should_be_ignored' => true],
        ];

        $result = $service->resolveEnvelopeTokenUsage($queueSnapshot, $queueInfo, $activeOperation);

        self::assertSame(111, $result['input_tokens']);
        self::assertSame(222, $result['output_tokens']);
        self::assertSame(9999, $result['total_tokens']);
        self::assertSame(['currency' => 'USD', 'cost' => 0.01], $result['token_cost_meta']);
    }

    public function testResolveEnvelopeTokenUsageSupportsOpenAiAliasesAndDerivesTotal(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueSnapshotService());

        // queueSnapshot 只给了 prompt_tokens/completion_tokens（OpenAI 别名），
        // 由底层 normalizeTokenUsage 归一到 input/output，再由外部派生 total
        $queueSnapshot = [
            'prompt_tokens' => 50,
            'completion_tokens' => 30,
        ];

        $result = $service->resolveEnvelopeTokenUsage($queueSnapshot, [], []);

        self::assertSame(50, $result['input_tokens']);
        self::assertSame(30, $result['output_tokens']);
        self::assertSame(80, $result['total_tokens']);
        self::assertNull($result['token_cost_meta']);
    }

    public function testBuildStatusEnvelopeAssemblesAllFieldsAndRespectsSourceSemantics(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueSnapshotService());

        $state = [
            'public_id' => 'pub_envelope_1',
            'workspace_status' => 'running',
            'updated_at' => '2026-04-23 10:00:00',
            'events' => [
                ['event_id' => 5, 'event' => 'queue.progress'],
                ['event_id' => 11, 'event' => 'queue.progress'],
                ['event_id' => 7, 'event' => 'queue.ack'], // last_event_id 应为最大值 11
            ],
            'active_operation' => [
                'operation' => 'plan',
                'status' => 'running',
                'job_key' => 'jk-plan-abc',
                'page_type' => 'home',
            ],
            'scope' => [
                'context_hash' => 'ctxhash-from-scope',
                'plan_confirmed' => 0,
                'task_plan_confirmed' => 0,
            ],
            'plan_queue_info' => [
                'snapshot' => [
                    'queue_id' => 77,
                    'status' => 'running',
                    'job_key' => 'jk-plan-abc',
                    'input_tokens' => 120,
                ],
            ],
        ];

        $envelope = $service->buildStatusEnvelope($state, 'queue');

        self::assertSame('jk-plan-abc', $envelope['job_key']);
        self::assertSame('stage1.requirement_expand', $envelope['job_type']);
        self::assertSame('running', $envelope['status']);
        self::assertSame(11, $envelope['event_id']);
        self::assertSame(11, $envelope['seq_no']);
        self::assertSame('plan/home', $envelope['cursor']);
        self::assertSame('queue', $envelope['source']);
        self::assertSame('pub_envelope_1', $envelope['session_public_id']);
        self::assertSame('ctxhash-from-scope', $envelope['context_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $envelope['state_fingerprint']);
        self::assertSame(120, $envelope['token_usage']['input_tokens']);
        self::assertSame('2026-04-23 10:00:00', $envelope['updated_at']);
        self::assertIsString($envelope['progress_kind']);

        // source 归一：非 'poller' 一律视为 'queue'
        $queueEnvelope = $service->buildStatusEnvelope($state, 'any_other');
        self::assertSame('queue', $queueEnvelope['source']);

        // 'poller' 路径
        $pollerEnvelope = $service->buildStatusEnvelope($state, 'poller');
        self::assertSame('poller', $pollerEnvelope['source']);
    }

    public function testBuildStatusEnvelopeFallsBackToFingerprintAsContextHashWhenScopeEmpty(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueSnapshotService());

        $state = [
            'public_id' => 'pub_minimal',
            'workspace_status' => '',
            'active_operation' => ['operation' => 'task_plan', 'status' => 'queued'],
            'scope' => [],
            'events' => [],
        ];

        $envelope = $service->buildStatusEnvelope($state, 'queue');

        self::assertSame('', $envelope['job_key']);
        self::assertSame('stage2.shared.tasks', $envelope['job_type']);
        self::assertSame(0, $envelope['event_id']);
        // context_hash 应回落到 state_fingerprint
        self::assertSame($envelope['state_fingerprint'], $envelope['context_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $envelope['context_hash']);
    }
}
