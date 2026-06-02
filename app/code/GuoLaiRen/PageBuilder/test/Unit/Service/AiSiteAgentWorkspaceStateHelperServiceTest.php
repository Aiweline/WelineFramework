<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceStateHelperService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueStateService;
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
            'plan_queue_info' => ['queue_id' => 10, 'status' => 'running'],
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
                'home' => [
                    'title' => 'Home',
                    'blocks' => [
                        ['block_id' => 'hero', 'html' => '<section>' . \str_repeat('x', 20000) . '</section>'],
                    ],
                ],
                'about' => ['title' => 'About'],
            ],
        ];

        $selected = $service->selectVirtualPagesForSse($state, ['home', 'missing', 'about', '']);
        self::assertSame(['home', 'about'], \array_keys($selected));
        self::assertSame(1, $selected['home']['block_count'] ?? null);
        self::assertTrue($selected['home']['blocks'][0]['html_available'] ?? false);
        self::assertArrayNotHasKey('html', $selected['home']['blocks'][0] ?? []);
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

    public function testFilterEventsAndStateByStage(): void
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

        $state = ['events' => $rows, 'top_logs' => $rows, 'other' => 'kept'];
        $filtered = $service->filterStateByStage($state, 'plan');
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
            'virtual_pages_by_type' => ['HOME' => ['blocks' => [1]]],
            'build_plan_v2' => ['id' => 'bp_1'],
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
            'virtual_theme_plan' => [1],
        ];

        $pruned = $service->pruneStateForView($state);
        self::assertSame('plan-md', $pruned['plan']['markdown']);
        self::assertSame('bp_1', (string)($pruned['plan']['build_plan_v2']['id'] ?? ''));
        self::assertTrue((bool)($pruned['plan']['build_plan_v2']['slimmed_for_view'] ?? false));
        self::assertSame(['pages' => ['home_page']], $pruned['plan']['projection']);
        self::assertTrue($pruned['plan']['build_plan_v2_available']);
        self::assertArrayNotHasKey('execution_blueprint', $pruned['plan']);
        self::assertArrayNotHasKey('plan_json', $pruned);
        self::assertArrayNotHasKey('task_plan', $pruned);
        self::assertArrayNotHasKey('virtual_theme_plan', $pruned);
        self::assertArrayNotHasKey('events', $pruned);
    }

    public function testPruneStateForViewSanitizesRuntimeProviderDiagnostics(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();
        $state = [
            'build_task_summary' => [
                'total' => 1,
                'failed' => 1,
                'groups' => [
                    'home_page' => [
                        'page_type' => 'home_page',
                        'total' => 1,
                        'failed' => 1,
                        'tasks' => [
                            [
                                'task_key' => 'page:home_page:content/home-page-hero',
                                'status' => 'failed',
                                'message' => 'REQUIRED_IMAGE_ASSET_UNRESOLVED: VectorEngine API returned error (URL: https://api.vectorengine.cn, HTTP: 403): request id xyz',
                            ],
                        ],
                    ],
                ],
            ],
            'retryable_ai_failures' => [
                [
                    'operation' => 'build',
                    'items' => [
                        [
                            'message' => 'OpenSSL SSL_read unexpected eof while reading',
                            'error_message' => 'VectorEngine API returned error (URL: https://api.vectorengine.cn, HTTP: 403): request id xyz',
                        ],
                    ],
                ],
            ],
        ];

        $pruned = $service->pruneStateForView($state);
        $taskMessage = (string)($pruned['build_task_summary']['groups']['home_page']['tasks'][0]['message'] ?? '');
        $retryMessage = (string)($pruned['retryable_ai_failures'][0]['items'][0]['message'] ?? '');
        $retryErrorMessage = (string)($pruned['retryable_ai_failures'][0]['items'][0]['error_message'] ?? '');

        self::assertSame(
            'Image generation is temporarily unavailable. The section will need another generation attempt.',
            $taskMessage
        );
        self::assertSame(
            'AI generation timed out. The section will need another generation attempt.',
            $retryMessage
        );
        self::assertSame(
            'Image generation is temporarily unavailable. The section will need another generation attempt.',
            $retryErrorMessage
        );
        foreach (['REQUIRED_IMAGE_ASSET_UNRESOLVED', 'VectorEngine', 'https://', 'HTTP: 403', 'request id', 'OpenSSL'] as $needle) {
            self::assertStringNotContainsString($needle, $taskMessage . $retryMessage . $retryErrorMessage);
        }
    }

    public function testPruneStateForViewKeepsEditableVirtualThemeLayoutMetadata(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();
        $state = [
            'public_id' => 'pub_layout',
            'page_type_layouts' => [
                'home_page' => [
                    'page_type' => 'home_page',
                    'label' => 'Home',
                    'version' => '1.0',
                    'page_id' => 0,
                    'use_original_template' => false,
                    'header' => [
                        'component' => 'header/ai-site-header',
                        'config' => [
                            'site_title' => 'Editable Site',
                            'nav_hint' => 'Home | Contact',
                        ],
                        'html' => '<header>drop</header>',
                    ],
                    'content' => [
                        [
                            'code' => 'content/home-page-hero',
                            'enabled' => true,
                            'config' => [
                                'headline' => 'Editable Headline',
                                'cta_text' => 'Start',
                                'nested' => ['subtitle' => 'Nested Editable'],
                            ],
                            'sort_order' => 10,
                            'phtml' => '<section>drop</section>',
                        ],
                    ],
                    'footer' => [
                        'component' => 'footer/ai-site-footer',
                        'config' => ['site_title' => 'Editable Site'],
                    ],
                    'blocks' => [['html' => 'legacy']],
                    'sections' => [['html' => 'legacy']],
                ],
            ],
        ];

        $pruned = $service->pruneStateForView($state);
        $layout = $pruned['page_type_layouts']['home_page'];

        self::assertSame('1.0', $layout['version']);
        self::assertSame(1, $layout['block_count']);
        self::assertSame(1, $layout['section_count']);
        self::assertSame('header/ai-site-header', $layout['header']['component']);
        self::assertSame('Editable Site', $layout['header']['config']['site_title']);
        self::assertSame('content/home-page-hero', $layout['content'][0]['code']);
        self::assertSame('Editable Headline', $layout['content'][0]['config']['headline']);
        self::assertSame('Nested Editable', $layout['content'][0]['config']['nested']['subtitle']);
        self::assertSame('footer/ai-site-footer', $layout['footer']['component']);
        self::assertArrayNotHasKey('html', $layout['header']);
        self::assertArrayNotHasKey('phtml', $layout['content'][0]);
        self::assertArrayNotHasKey('blocks', $layout);
        self::assertArrayNotHasKey('sections', $layout);
    }

    public function testPruneStateForEventPayloadDropsViewOnlyHeavySummaries(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();
        $state = [
            'public_id' => 'pub_event',
            'active_operation' => ['operation' => 'build', 'status' => 'running'],
            'build_task_summary' => ['total' => 2, 'done' => 1],
            'virtual_pages_by_type' => [
                'home_page' => [
                    'title' => 'Home',
                    'blocks' => [
                        ['block_id' => 'hero', 'html' => '<section>' . \str_repeat('x', 50000) . '</section>'],
                    ],
                ],
            ],
            'asset_manifest' => ['slots' => ['hero' => ['final_url' => 'https://example.test/hero.png']]],
            'content_manifest' => ['items' => [['copy' => \str_repeat('m', 20000)]]],
            'page_type_layouts' => ['home_page' => ['content' => [['component' => 'hero']]]],
            'plan' => ['markdown' => \str_repeat('p', 50000)],
            'scope' => ['asset_manifest' => ['slots' => ['hero' => ['final_url' => 'https://example.test/hero.png']]]],
        ];

        $pruned = $service->pruneStateForEventPayload($state);

        self::assertSame('pub_event', $pruned['public_id'] ?? '');
        self::assertSame('build', $pruned['active_operation']['operation'] ?? '');
        self::assertSame(1, $pruned['virtual_pages_by_type']['home_page']['block_count'] ?? null);
        self::assertArrayNotHasKey('html', $pruned['virtual_pages_by_type']['home_page']['blocks'][0] ?? []);
        self::assertArrayNotHasKey('asset_manifest', $pruned);
        self::assertArrayNotHasKey('content_manifest', $pruned);
        self::assertArrayNotHasKey('page_type_layouts', $pruned);
        self::assertArrayNotHasKey('plan', $pruned);
        self::assertArrayNotHasKey('scope', $pruned);
    }

    public function testSelectStatusQueueInfoUsesOperationMarkersBeforeSharedBuckets(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();
        $state = [
            'plan_queue_info' => ['queue_id' => 1, 'job_type' => 'stage1.requirement_expand'],
            'build_queue_info' => ['queue_id' => 3, 'job_type' => 'virtual_theme.publish'],
        ];

        self::assertSame($state['plan_queue_info'], $service->selectStatusQueueInfo($state, 'plan'));
        self::assertSame([], $service->selectStatusQueueInfo($state, 'build'));
        self::assertSame($state['build_queue_info'], $service->selectStatusQueueInfo($state, 'publish'));
        self::assertSame($state['plan_queue_info'], $service->selectStatusQueueInfo($state, 'task_plan'));
        self::assertSame([], $service->selectStatusQueueInfo([], 'plan'));

        $imageState = [
            'build_queue_info' => ['queue_id' => 4, 'job_type' => 'image.asset.generate'],
        ];
        self::assertSame($imageState['build_queue_info'], $service->selectStatusQueueInfo($imageState, 'image_asset'));
        self::assertSame([], $service->selectStatusQueueInfo($imageState, 'publish'));
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
        self::assertSame('image.asset.generate', $service->resolveQueueJobType('image_asset'));
        self::assertSame('virtual_theme.publish', $service->resolveQueueJobType('publish'));
        self::assertSame('', $service->resolveQueueJobType('task_plan'));
    }

    public function testResolveProgressKindSwitchesAfterBuildPlanConfirmation(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService();

        self::assertSame('queue_info', $service->resolveProgressKind([], []));
        self::assertSame('queue_info', $service->resolveProgressKind(['build_plan_confirmed' => 0], ['operation' => 'build']));
        self::assertSame('build_plan_progress', $service->resolveProgressKind(['build_plan_confirmed' => 1], ['operation' => 'build']));
        self::assertSame('build_plan_progress', $service->resolveProgressKind(['build_plan_confirmed' => 1], ['operation' => 'block_partial_patch']));
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
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueStateService());
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
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueStateService());
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
                'queue_id' => 77,
                'status' => 'running',
                'job_key' => 'jk-plan-abc',
                'input_tokens' => 120,
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

    public function testBuildStatusEnvelopeReadsPublishQueueFromSharedBuildBucket(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueStateService());
        $state = [
            'public_id' => 'pub_publish_1',
            'workspace_status' => 'publishing',
            'events' => [],
            'active_operation' => [
                'operation' => 'publish',
                'status' => 'queued',
            ],
            'build_queue_info' => [
                'queue_id' => 88,
                'status' => 'running',
                'job_key' => 'glr_aisite:session:1:job:virtual_theme.publish',
                'job_type' => 'virtual_theme.publish',
                'total_tokens' => 321,
            ],
        ];

        $envelope = $service->buildStatusEnvelope($state, 'queue');
        self::assertSame('glr_aisite:session:1:job:virtual_theme.publish', $envelope['job_key']);
        self::assertSame('virtual_theme.publish', $envelope['job_type']);
        self::assertSame('running', $envelope['status']);
        self::assertSame('publish', $envelope['cursor']);
        self::assertSame(321, $envelope['token_usage']['total_tokens']);
    }

    public function testBuildStatusEnvelopeDoesNotMapLegacyTaskPlanJobType(): void
    {
        $service = new AiSiteAgentWorkspaceStateHelperService(new AiSiteQueueStateService());
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
