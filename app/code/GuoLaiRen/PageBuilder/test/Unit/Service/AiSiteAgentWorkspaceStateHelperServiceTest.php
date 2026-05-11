<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceStateHelperService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueSnapshotService;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentWorkspaceStateHelperServiceTest extends TestCase
{
    public function testBuildStateFingerprintIsStableAndUsesBuildPlanConfirmation(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();
        $state = [
            'public_id' => 'pub_demo_1',
            'stage' => 'plan',
            'workspace_status' => 'draft',
            'publish_status' => 'unpublished',
            'active_operation' => ['operation' => 'plan', 'status' => 'running'],
            'virtual_theme_id' => 0,
            'scope' => ['plan_confirmed' => 1, 'build_plan_confirmed' => 0],
            'plan_queue_info' => ['snapshot' => ['queue_id' => 10, 'status' => 'running']],
        ];

        $fingerprint = $service->buildStateFingerprint($state);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $fingerprint);
        self::assertSame($fingerprint, $service->buildStateFingerprint($state));

        $changed = $state;
        $changed['scope']['build_plan_confirmed'] = 1;
        self::assertNotSame($fingerprint, $service->buildStateFingerprint($changed));
    }

    public function testNormalizeSsePageTypesTrimsDedupAndFallsBack(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame(['home', 'about'], $service->normalizeSsePageTypes(['home', ' about ', 'home', '']));
        self::assertSame(['home'], $service->normalizeSsePageTypes([], 'home'));
        self::assertSame([], $service->normalizeSsePageTypes([], ''));
        self::assertSame(['contact'], $service->normalizeSsePageTypes(['', '  '], 'contact'));
    }

    public function testSelectVirtualPagesForSseKeepsRequestedTypes(): void
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
        self::assertSame([], $service->selectVirtualPagesForSse(['virtual_pages_by_type' => []], ['home']));
    }

    public function testEventMatchesPlanAndBuildStagesButNotLegacyTaskPlan(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertTrue($service->eventMatchesStage(['stage_code' => 'plan'], 'plan'));
        self::assertTrue($service->eventMatchesStage(['payload' => ['operation' => 'plan']], 'plan'));
        self::assertTrue($service->eventMatchesStage(['event_type' => 'plan_rebuilt'], 'plan'));
        self::assertTrue($service->eventMatchesStage(['payload' => ['operation' => 'block_partial_patch']], 'build'));
        self::assertTrue($service->eventMatchesStage(['event_type' => 'build_progress'], 'build'));
        self::assertFalse($service->eventMatchesStage(['payload_json' => ['operation' => 'task_plan']], 'task_plan'));
        self::assertFalse($service->eventMatchesStage(['event_type' => 'task_plan_refined'], 'task_plan'));
    }

    public function testFilterEventsAndSnapshotByStage(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();
        $rows = [
            ['stage_code' => 'plan'],
            'garbage',
            ['payload' => ['operation' => 'build']],
            ['event_type' => 'task_plan_refined'],
        ];

        self::assertSame($rows, $service->filterEventsByStage($rows, ''));
        self::assertCount(1, $service->filterEventsByStage($rows, 'plan'));
        self::assertCount(1, $service->filterEventsByStage($rows, 'build'));
        self::assertCount(0, $service->filterEventsByStage($rows, 'task_plan'));

        $snapshot = ['events' => $rows, 'top_logs' => $rows, 'other' => 'kept'];
        $filtered = $service->filterSnapshotByStage($snapshot, 'plan');
        self::assertSame('kept', $filtered['other']);
        self::assertCount(1, $filtered['events']);
        self::assertCount(1, $filtered['top_logs']);
    }

    public function testNormalizeStreamStageRejectsLegacySecondStageAliases(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame('plan', $service->normalizeStreamStage('PLAN'));
        self::assertSame('build', $service->normalizeStreamStage(' build '));
        self::assertSame('visual-edit', $service->normalizeStreamStage('Visual-Edit'));
        self::assertSame('', $service->normalizeStreamStage('Task_Plan'));
        self::assertSame('', $service->normalizeStreamStage('phase-2'));
        self::assertSame('', $service->normalizeStreamStage('bad stage!'));
    }

    public function testResolveLastEventIdTakesMaxAndFallbackColumn(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame(42, $service->resolveLastEventId([
            ['event_id' => 10],
            'garbage',
            ['event_id' => 7],
            ['ai_site_agent_event_id' => 42],
            ['event_id' => 3, 'ai_site_agent_event_id' => 99],
        ]));
        self::assertSame(0, $service->resolveLastEventId([]));
    }

    public function testPruneEventsForSseKeepsTailAndWhitelistedPayload(): void
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
                    'extra_should_drop' => 'secret',
                    'details' => [
                        'reason' => 'reason',
                        'region' => 'content',
                        'section_code' => 'hero',
                        'component_code' => 'hero',
                        'internal_flag' => 'drop',
                    ],
                ],
                'create_time' => '2026-04-23 10:00:00',
            ];
        }

        $pruned = $service->pruneEventsForSse($rows, 3);
        self::assertCount(3, $pruned);
        self::assertSame(8, $pruned[0]['event_id']);
        self::assertSame(['message', 'operation', 'page_type', 'progress_percent', 'details'], \array_keys($pruned[0]['payload']));
        self::assertSame(['reason', 'region', 'section_code', 'component_code'], \array_keys($pruned[0]['payload']['details']));
        self::assertArrayNotHasKey('extra_should_drop', $pruned[0]['payload']);
    }

    public function testPruneScopeForViewDropsHeavyAndLegacyArtifacts(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();
        $scope = [
            'public_id' => 'pub_keep',
            'plan_confirmed' => 1,
            'build_plan_confirmed' => 1,
            'plan_json' => ['raw' => 1],
            'plan_structured' => [1],
            'virtual_pages_by_type' => ['HOME' => ['blocks' => [1]]],
            'build_plan_v2' => ['id' => 'bp_1'],
            'task_plan_structured' => [1],
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [1],
            'build_blueprint' => [1],
            'build_tasks' => [1],
        ];

        self::assertSame(
            [
                'public_id' => 'pub_keep',
                'plan_confirmed' => 1,
                'build_plan_confirmed' => 1,
                'plan_json' => ['raw' => 1],
                'plan_structured' => [1],
            ],
            $service->pruneScopeForView($scope)
        );
    }

    public function testPruneStateForViewKeepsBuildPlanPreviewAndDropsLegacyTaskPlan(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();
        $state = [
            'public_id' => 'pub_2',
            'stage' => 'plan',
            'scope' => [
                'public_id' => 'pub_2',
                'plan_confirmed' => 1,
                'build_plan_confirmed' => 1,
                'virtual_pages_by_type' => ['HOME' => 1],
            ],
            'plan' => [
                'markdown' => 'plan-md',
                'build_plan_v2' => ['id' => 'bp_1'],
                'projection' => ['pages' => ['home_page']],
                'execution_blueprint' => ['legacy' => 1],
            ],
            'task_plan' => ['markdown' => 'old'],
            'events' => [1],
            'plan_json' => [1],
            'plan_structured' => [1],
            'task_plan_structured' => [1],
            'virtual_theme_plan' => [1],
        ];

        $pruned = $service->pruneStateForView($state);
        self::assertSame('plan-md', $pruned['plan']['markdown']);
        self::assertSame(['id' => 'bp_1'], $pruned['plan']['build_plan_v2']);
        self::assertSame(['pages' => ['home_page']], $pruned['plan']['projection']);
        self::assertTrue($pruned['plan']['build_plan_v2_available']);
        self::assertSame([1], $pruned['plan_json']);
        self::assertSame([1], $pruned['plan_structured']);
        self::assertArrayNotHasKey('task_plan', $pruned);
        self::assertArrayNotHasKey('task_plan_structured', $pruned);
        self::assertArrayNotHasKey('virtual_theme_plan', $pruned);
        self::assertArrayNotHasKey('events', $pruned);
    }

    public function testSelectStatusQueueInfoUsesPlanAndBuildBucketsOnly(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();
        $state = [
            'plan_queue_info' => ['queue_id' => 1],
            'build_queue_info' => ['queue_id' => 3],
        ];

        self::assertSame(['queue_id' => 1], $service->selectStatusQueueInfo($state, 'plan'));
        self::assertSame(['queue_id' => 3], $service->selectStatusQueueInfo($state, 'build'));
        self::assertSame(['queue_id' => 3], $service->selectStatusQueueInfo($state, 'block_partial_patch'));
        self::assertSame(['queue_id' => 1], $service->selectStatusQueueInfo($state, 'task_plan'));
        self::assertSame([], $service->selectStatusQueueInfo([], 'plan'));
    }

    public function testEnvelopeStatusProgressCursorAndJobType(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame('failed', $service->normalizeEnvelopeStatus('ERROR'));
        self::assertSame('cancelled', $service->normalizeEnvelopeStatus('Stopped'));
        self::assertSame('done', $service->normalizeEnvelopeStatus('Published'));
        self::assertSame('queued', $service->normalizeEnvelopeStatus('PENDING'));
        self::assertSame('running', $service->normalizeEnvelopeStatus('building'));

        self::assertSame(50, $service->resolveEnvelopeProgressPercent([], ['progress_percent' => 50], 'running'));
        self::assertSame(75, $service->resolveEnvelopeProgressPercent(['build_task_summary' => ['total' => 4, 'completed' => 3]], [], 'running'));
        self::assertSame(100, $service->resolveEnvelopeProgressPercent(['can_publish' => true], [], 'queued'));
        self::assertSame(0, $service->resolveEnvelopeProgressPercent(['can_publish' => true], [], 'failed'));

        self::assertSame('plan/plan/HOME', $service->resolveEnvelopeCursor(['stage' => 'plan', 'preview_page_type' => 'HOME'], ['operation' => 'plan']));
        self::assertSame('plan', $service->resolveEnvelopeCursor(['stage' => 'plan'], ['operation' => 'task_plan', 'page_type' => 'ABOUT']));

        self::assertSame('stage1.requirement_expand', $service->resolveQueueJobType('plan'));
        self::assertSame('virtual_theme.tree.build', $service->resolveQueueJobType('build'));
        self::assertSame('virtual_theme.block.partial_patch', $service->resolveQueueJobType('block_partial_patch'));
        self::assertSame('', $service->resolveQueueJobType('task_plan'));
    }

    public function testResolveProgressKindSwitchesAfterBuildPlanConfirmation(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame('queue_info', $service->resolveProgressKind([], []));
        self::assertSame('queue_info', $service->resolveProgressKind(['build_plan_confirmed' => 0], ['operation' => 'build']));
        self::assertSame('task_progress', $service->resolveProgressKind(['build_plan_confirmed' => 1], ['operation' => 'build']));
        self::assertSame('task_progress', $service->resolveProgressKind(['build_plan_confirmed' => 1], ['operation' => 'block_partial_patch']));
        self::assertSame('queue_info', $service->resolveProgressKind(['build_plan_confirmed' => 1], ['operation' => 'plan']));
    }

    public function testResolveEnvelopeUpdatedAtUsesFirstNonEmptyOrCurrentTimestamp(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame('2026-04-23 10:00:00', $service->resolveEnvelopeUpdatedAt([], ['updated_at' => '2026-04-23 10:00:00'], []));
        self::assertSame('2026-04-23 09:30:00', $service->resolveEnvelopeUpdatedAt([], [], ['end_at' => '2026-04-23 09:30:00']));
        self::assertSame('start-fallback', $service->resolveEnvelopeUpdatedAt([], [], ['start_at' => 'start-fallback']));
        self::assertSame('2026-04-22 00:00:00', $service->resolveEnvelopeUpdatedAt(['updated_at' => '2026-04-22 00:00:00'], [], []));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $service->resolveEnvelopeUpdatedAt([], [], []));
    }

    public function testResolveEnvelopeTokenUsageMergesQueueInfoAndActiveOperation(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueSnapshotService());
        $result = $service->resolveEnvelopeTokenUsage(
            ['input_tokens' => 111],
            ['output_tokens' => 222, 'token_cost_meta' => ['currency' => 'USD']],
            ['total_tokens' => 9999]
        );

        self::assertSame(111, $result['input_tokens']);
        self::assertSame(222, $result['output_tokens']);
        self::assertSame(9999, $result['total_tokens']);
        self::assertSame(['currency' => 'USD'], $result['token_cost_meta']);
    }

    public function testBuildStatusEnvelopeAssemblesBuildPlanV2Fields(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueSnapshotService());
        $state = [
            'public_id' => 'pub_envelope_1',
            'workspace_status' => 'running',
            'updated_at' => '2026-04-23 10:00:00',
            'events' => [['event_id' => 5], ['event_id' => 11]],
            'active_operation' => [
                'operation' => 'plan',
                'status' => 'running',
                'job_key' => 'jk-plan-abc',
                'page_type' => 'home',
            ],
            'scope' => [
                'build_plan_v2' => ['contract_id' => 'bp-contract-1'],
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
        self::assertSame('plan/home', $envelope['cursor']);
        self::assertSame('queue', $envelope['source']);
        self::assertSame('pub_envelope_1', $envelope['session_public_id']);
        self::assertSame('bp-contract-1', $envelope['context_hash']);
        self::assertSame(120, $envelope['token_usage']['input_tokens']);
        self::assertSame('2026-04-23 10:00:00', $envelope['updated_at']);

        self::assertSame('poller', $service->buildStatusEnvelope($state, 'poller')['source']);
    }

    public function testBuildStatusEnvelopeDoesNotMapLegacyTaskPlanJobType(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueSnapshotService());
        $envelope = $service->buildStatusEnvelope([
            'public_id' => 'pub_minimal',
            'active_operation' => ['operation' => 'task_plan', 'status' => 'queued'],
            'scope' => [],
            'events' => [],
        ], 'queue');

        self::assertSame('', $envelope['job_key']);
        self::assertSame('', $envelope['job_type']);
        self::assertSame(0, $envelope['event_id']);
        self::assertSame($envelope['state_fingerprint'], $envelope['context_hash']);
    }
}
