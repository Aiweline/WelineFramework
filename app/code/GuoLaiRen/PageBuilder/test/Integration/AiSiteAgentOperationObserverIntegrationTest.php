<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use ReflectionMethod;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\RequestContext;
use Weline\Queue\Model\Queue;

require_once __DIR__ . '/../Support/DuplicateObserverHeartbeatWriter.php';

final class AiSiteAgentOperationObserverIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testLongRunningQueuedOperationsKeepObserverStreamOpen(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldKeepQueuedObserverStreamOpen');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($controller, 'plan'));
        self::assertFalse((bool)$method->invoke($controller, 'task_plan'));
        self::assertTrue((bool)$method->invoke($controller, 'build'));
        self::assertFalse((bool)$method->invoke($controller, 'publish'));
    }

    public function testStageQueueSlotsAreIsolatedPerOperation(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildAiSiteQueueBizKey');
        $method->setAccessible(true);

        $planKey = (string)$method->invoke($controller, 123, 'plan');
        $buildKey = (string)$method->invoke($controller, 123, 'build');

        self::assertSame('glr_aisite:session:123:queue_slot:plan', $planKey);
        self::assertSame('glr_aisite:session:123:queue_slot:build', $buildKey);
        self::assertNotSame($planKey, $buildKey);
    }

    public function testDuplicateOperationObserverIdleTimeoutIsDisabled(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'getObserverMaxIdleLoops');
        $method->setAccessible(true);

        self::assertSame(0, (int)$method->invoke($controller));
    }

    public function testEnqueueOperationQueueTaskReusesOriginalErroredQueueAndStopsDuplicateRows(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $bizKey = 'glr_aisite:session:' . $session->getId() . ':queue_slot:plan';
        $baseContent = [
            'public_id' => $publicId,
            'admin_id' => 1,
            'operation' => 'plan',
            'stage' => AiSiteAgentSession::STAGE_PLAN,
        ];

        $first = w_query('queue', 'create', [
            'class' => \GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue::class,
            'name' => 'Original errored canonical queue',
            'module' => 'GuoLaiRen_PageBuilder',
            'content' => \array_replace($baseContent, ['execution_token' => 'first-canonical-token']),
            'status' => 'error',
            'auto' => true,
            'biz_key' => $bizKey,
            'wake_scheduler' => false,
        ]);
        $second = w_query('queue', 'create', [
            'class' => \GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue::class,
            'name' => 'Second duplicate pending queue',
            'module' => 'GuoLaiRen_PageBuilder',
            'content' => \array_replace($baseContent, ['execution_token' => 'second-duplicate-token']),
            'status' => 'pending',
            'auto' => true,
            'biz_key' => $bizKey,
            'wake_scheduler' => false,
        ]);
        self::assertIsArray($first);
        self::assertIsArray($second);
        $firstQueueId = (int)($first['queue_id'] ?? 0);
        $secondQueueId = (int)($second['queue_id'] ?? 0);
        self::assertGreaterThan(0, $firstQueueId);
        self::assertGreaterThan($firstQueueId, $secondQueueId);

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'enqueueOperationQueueTask');
        $method->setAccessible(true);

        $queueId = (int)$method->invoke(
            $controller,
            $session,
            1,
            'plan',
            'canonical-reuse-token',
            [],
            ['retry_of_queue_id' => $firstQueueId]
        );

        self::assertSame($firstQueueId, $queueId);

        $canonical = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($canonical);
        self::assertSame('pending', (string)($canonical['status'] ?? ''));
        self::assertSame('PageBuilder plan #canonical-re', (string)($canonical['name'] ?? ''));
        self::assertSame($bizKey, (string)($canonical['biz_key'] ?? ''));

        $content = \is_array($canonical['content'] ?? null)
            ? $canonical['content']
            : \json_decode((string)($canonical['content'] ?? ''), true);
        self::assertIsArray($content);
        self::assertSame('canonical-reuse-token', (string)($content['execution_token'] ?? ''));
        self::assertSame($firstQueueId, (int)($content['retry_of_queue_id'] ?? 0));
        self::assertSame($firstQueueId, (int)($content['details']['retry_of_queue_id'] ?? 0));

        $duplicate = w_query('queue', 'get', ['queue_id' => $secondQueueId]);
        self::assertIsArray($duplicate);
        self::assertSame('stop', (string)($duplicate['status'] ?? ''));
        self::assertStringContainsString('Superseded by PageBuilder queue #' . $firstQueueId, (string)($duplicate['process'] ?? ''));

        $rows = w_query('queue', 'list', [
            'module' => 'GuoLaiRen_PageBuilder',
            'biz_key' => $bizKey,
            'page_size' => 10,
        ]);
        self::assertIsArray($rows);
        self::assertCount(2, \is_array($rows['items'] ?? null) ? $rows['items'] : []);
    }

    public function testPostStartPatchBlockCreatesPendingQueueWithScopedPayload(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $scope = $this->sessionService->loadScope($session);
        $scope = \array_replace($scope, [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'workspace_status' => \GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH,
            'workspace_track' => \GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES,
            'page_types' => [Page::TYPE_HOME],
            'preview_page_type' => Page::TYPE_HOME,
            'website_profile' => ['business_name' => 'Patch Test'],
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'page_type' => Page::TYPE_HOME,
                    'title' => 'Home',
                    'block_nodes' => [
                        [
                            'block_id' => 'hero',
                            'type' => 'hero',
                            'component_code' => 'content/hero',
                            'config' => ['headline' => 'Original headline'],
                            'html' => '<section>Original headline</section>',
                            'field_schema' => [
                                'content' => [
                                    'fields' => [
                                        ['key' => 'headline', 'type' => 'text'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->sessionService->setStage($session->getId(), 1, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->replaceScope($session->getId(), 1, $scope);

        $payload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-patch-block',
            'POST',
            'postStartPatchBlock',
            [],
            [
                'public_id' => $publicId,
                'page_type' => Page::TYPE_HOME,
                'block_id' => 'hero',
                'instruction' => 'Make the headline more conversion-focused.',
            ]
        );

        self::assertTrue((bool)($payload['success'] ?? false), \json_encode($payload, \JSON_UNESCAPED_UNICODE));
        self::assertSame('block_partial_patch', (string)($payload['operation'] ?? ''));
        $queueId = (int)($payload['queue_id'] ?? 0);
        self::assertGreaterThan(0, $queueId);
        self::assertNotSame('', (string)($payload['execution_token'] ?? ''));
        self::assertStringContainsString('operation-sse', (string)($payload['stream_url'] ?? ''));

        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertSame('pending', (string)($queue['status'] ?? ''));
        self::assertSame(
            'glr_aisite:session:' . $session->getId() . ':queue_slot:block_partial_patch',
            (string)($queue['biz_key'] ?? '')
        );

        $content = \is_array($queue['content'] ?? null)
            ? $queue['content']
            : \json_decode((string)($queue['content'] ?? ''), true);
        self::assertIsArray($content);
        self::assertSame('block_partial_patch', (string)($content['operation'] ?? ''));
        self::assertSame(Page::TYPE_HOME, (string)($content['page_type'] ?? ''));
        self::assertSame('hero', (string)($content['block_id'] ?? ''));
        self::assertSame('content/hero', (string)($content['component_code'] ?? ''));
        self::assertSame('Make the headline more conversion-focused.', (string)($content['instruction'] ?? ''));
        self::assertSame('virtual_theme.block.partial_patch', (string)($content['job_type'] ?? ''));
        self::assertIsArray($content['scope_patch'] ?? null);
        self::assertSame([], $content['scope_patch']);
    }

    public function testBlockPartialPatchQueueExecutionAppliesOnlyTargetBlock(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $scope = $this->sessionService->loadScope($session);
        $PlanJson = (new AiSitePlanJsonService())->buildFromScope([
            'page_types' => [Page::TYPE_HOME],
            'site_title' => 'Patch Test',
            'default_locale' => 'en_US',
            'plan_json' => [
                'content_locale' => 'en_US',
                'pages' => [
                    Page::TYPE_HOME => [
                        'title' => 'Home',
                        'page_goal' => 'Explain the patch test site clearly.',
                        'hero' => [
                            'block_key' => 'hero',
                            'title' => 'Hero',
                            'goal' => 'Introduce the offer with a direct CTA.',
                            'page_flow_role' => 'hero_intro',
                            'visual_signature' => [
                                'composition_pattern' => 'single-column hero with compact proof row',
                                'spatial_rhythm' => 'generous top spacing with tight CTA grouping',
                                'media_strategy' => 'CSS-only accent surface',
                                'surface_treatment' => 'clean contrast with focused text hierarchy',
                            ],
                            'field_plan' => [
                                ['field' => 'headline', 'sample' => 'Patch Test'],
                                ['field' => 'description', 'sample' => 'Small focused updates keep the page reliable.'],
                                ['field' => 'cta', 'sample' => 'Get started'],
                            ],
                        ],
                        'features' => [
                            'block_key' => 'features',
                            'title' => 'Features',
                            'goal' => 'Summarize the core feature set.',
                            'page_flow_role' => 'feature_summary',
                            'visual_signature' => [
                                'composition_pattern' => 'two-column feature summary',
                                'spatial_rhythm' => 'even card spacing with short copy lines',
                                'media_strategy' => 'CSS-only icon accents',
                                'surface_treatment' => 'subtle bordered panels',
                            ],
                            'field_plan' => [
                                ['field' => 'description', 'sample' => 'Small focused updates keep the page reliable.'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $PlanJson['contract_meta']['status'] = 'confirmed';
        $PlanJson['contract_meta']['confirmed_at'] = '2026-05-11 00:00:00';
        $PlanJson['contract_meta']['signature'] = (string)($PlanJson['contract_meta']['source_signature'] ?? 'patch-test-plan-json');
        $scope = \array_replace($scope, [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH,
            'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES,
            'page_types' => [Page::TYPE_HOME],
            'preview_page_type' => Page::TYPE_HOME,
            'fake_mode' => 1,
            'website_profile' => ['business_name' => 'Patch Test'],
            'plan_json' => \array_replace($PlanJson, [
                'confirmed' => 1,
                'confirmed_at' => '2026-05-11 00:00:00',
            ]),
            'page_type_layouts' => [
                Page::TYPE_HOME => [
                    'content' => [
                        [
                            'block_id' => 'hero',
                            'code' => 'content/hero',
                            'component_code' => 'content/hero',
                            'type' => 'hero',
                            'config' => ['headline' => 'Original headline'],
                            'html' => '<section><h1>Original headline</h1></section>',
                            'field_schema' => [
                                'content' => [
                                    'fields' => [
                                        ['key' => 'headline', 'type' => 'text'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'block_id' => 'features',
                            'code' => 'content/features',
                            'component_code' => 'content/features',
                            'type' => 'features',
                            'config' => ['headline' => 'Stable features'],
                            'html' => '<section><h2>Stable features</h2></section>',
                            'field_schema' => [
                                'content' => [
                                    'fields' => [
                                        ['key' => 'headline', 'type' => 'text'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'page_type' => Page::TYPE_HOME,
                    'title' => 'Home',
                    'block_nodes' => [
                        [
                            'block_id' => 'hero',
                            'type' => 'hero',
                            'component_code' => 'content/hero',
                            'config' => ['headline' => 'Original headline'],
                            'html' => '<section><h1>Original headline</h1></section>',
                            'field_schema' => [
                                'content' => [
                                    'fields' => [
                                        ['key' => 'headline', 'type' => 'text'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'block_id' => 'features',
                            'type' => 'features',
                            'component_code' => 'content/features',
                            'config' => ['headline' => 'Stable features'],
                            'html' => '<section><h2>Stable features</h2></section>',
                            'field_schema' => [
                                'content' => [
                                    'fields' => [
                                        ['key' => 'headline', 'type' => 'text'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->sessionService->replaceScope($session->getId(), 1, $scope);
        $this->sessionService->setStage($session->getId(), 1, AiSiteAgentSession::STAGE_VISUAL_EDIT);

        $payload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-patch-block',
            'POST',
            'postStartPatchBlock',
            [],
            [
                'public_id' => $publicId,
                'page_type' => Page::TYPE_HOME,
                'block_id' => 'hero',
                'instruction' => 'Make the headline more conversion-focused.',
            ]
        );
        self::assertTrue((bool)($payload['success'] ?? false), \json_encode($payload, \JSON_UNESCAPED_UNICODE));

        $queueId = (int)($payload['queue_id'] ?? 0);
        self::assertGreaterThan(0, $queueId);

        /** @var Queue $queue */
        $queue = (clone ObjectManager::getInstance(Queue::class))->clearData()->load($queueId);
        self::assertGreaterThan(0, (int)$queue->getId());

        /** @var AiSiteBuildQueue $queueExecutor */
        $queueExecutor = ObjectManager::getInstance(AiSiteBuildQueue::class);
        self::assertTrue($queueExecutor->validate($queue));

        $bufferLevel = \ob_get_level();
        \ob_start();
        try {
            $result = $queueExecutor->execute($queue);
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
        }
        self::assertNotSame('', \trim($result));

        $queueRow = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queueRow);
        self::assertSame('done', (string)($queueRow['status'] ?? ''));
        self::assertStringContainsString('QUEUE_DONE', (string)($queueRow['result'] ?? ''));

        $fresh = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($fresh);
        $nextScope = $this->sessionService->loadScopeForBuildOperation($fresh);
        self::assertSame(AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH, (string)($nextScope['workspace_status'] ?? ''));
        self::assertSame('done', (string)($nextScope['active_operation']['status'] ?? ''));
        self::assertSame('block_partial_patch', (string)($nextScope['active_operation']['operation'] ?? ''));
        $layoutContent = $nextScope['page_type_layouts'][Page::TYPE_HOME]['content'] ?? [];
        self::assertIsArray($layoutContent);
        self::assertCount(2, $layoutContent);
        self::assertSame('Original headline - refined', (string)($layoutContent[0]['config']['headline'] ?? ''));
        self::assertSame('Stable features', (string)($layoutContent[1]['config']['headline'] ?? ''));

        $historyByBlock = $nextScope['block_patch_history'][Page::TYPE_HOME] ?? [];
        self::assertIsArray($historyByBlock);
        $history = $historyByBlock['hero'] ?? $historyByBlock['content/hero'] ?? [];
        self::assertIsArray($history);
        self::assertCount(1, $history);
        self::assertSame('Original headline', (string)($history[0]['before_block']['config']['headline'] ?? ''));
        self::assertSame((string)($payload['execution_token'] ?? ''), (string)($history[0]['execution_token'] ?? ''));
    }

    public function testStagePlanningOperationsSkipStaleReclaim(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldReclaimStaleActiveOperation');
        $method->setAccessible(true);

        self::assertFalse((bool)$method->invoke($controller, ['operation' => 'plan']));
        self::assertTrue((bool)$method->invoke($controller, ['operation' => 'task_plan']));
        self::assertTrue((bool)$method->invoke($controller, ['operation' => 'build']));
    }

    public function testOperationLookupUsesActiveOperationsWhenBuildOverwritesBlockOperation(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'resolveActiveOperationForExecutionToken');
        $method->setAccessible(true);

        $blockToken = 'block-patch-overwritten-token';
        $scope = [
            'active_operation' => [
                'operation' => 'build',
                'execution_token' => 'build-current-token',
                'status' => 'running',
                'queue_id' => 83,
            ],
            'active_operations' => [
                'block_partial_patch' => [
                    'operation' => 'block_partial_patch',
                    'execution_token' => $blockToken,
                    'status' => 'running',
                    'queue_id' => 79,
                ],
                'build' => [
                    'operation' => 'build',
                    'execution_token' => 'build-current-token',
                    'status' => 'running',
                    'queue_id' => 83,
                ],
            ],
        ];

        $operation = $method->invoke($controller, $scope, $blockToken);

        self::assertIsArray($operation);
        self::assertSame('block_partial_patch', (string)($operation['operation'] ?? ''));
        self::assertSame($blockToken, (string)($operation['execution_token'] ?? ''));
        self::assertSame(79, (int)($operation['queue_id'] ?? 0));
    }

    public function testUpdatingBlockOperationDoesNotOverwriteCurrentBuildOperation(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $blockToken = 'block-patch-update-token';
        $buildToken = 'build-current-token';
        $scope = $session->getScopeArray();
        $scope['active_operation'] = [
            'operation' => 'build',
            'execution_token' => $buildToken,
            'status' => 'running',
            'queue_id' => 83,
        ];
        $scope['active_operations'] = [
            'block_partial_patch' => [
                'operation' => 'block_partial_patch',
                'execution_token' => $blockToken,
                'status' => 'running',
                'queue_id' => 79,
            ],
            'build' => $scope['active_operation'],
        ];
        $this->sessionService->replaceScope($session->getId(), 1, $scope);

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'updateActiveOperation');
        $method->setAccessible(true);
        $method->invoke($controller, $session, 1, ['operation' => 'block_partial_patch', 'status' => 'done', 'message' => 'block patch done']);

        $fresh = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($fresh);
        $freshScope = $fresh->getScopeArray();
        self::assertSame('build', (string)($freshScope['active_operation']['operation'] ?? ''));
        self::assertSame($buildToken, (string)($freshScope['active_operation']['execution_token'] ?? ''));
        self::assertSame('done', (string)($freshScope['active_operations']['block_partial_patch']['status'] ?? ''));
        self::assertSame($blockToken, (string)($freshScope['active_operations']['block_partial_patch']['execution_token'] ?? ''));
    }

    public function testDoneActiveOperationClearsStaleFailureState(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $scope = $session->getScopeArray();
        $scope['active_operation'] = [
            'operation' => 'plan',
            'execution_token' => 'plan-token',
            'status' => 'error',
            'failure_mode' => 'plan_failed',
            'semantic_status' => 'cancelled',
            'retry_allowed' => 1,
            'retryable_ai_failure_count' => 12,
            'retryable_ai_failures' => ['prior'],
            'progress_percent' => 0,
        ];
        $scope['active_operations'] = ['plan' => $scope['active_operation']];
        $this->sessionService->replaceScope($session->getId(), 1, $scope);

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'updateActiveOperation');
        $method->setAccessible(true);
        $method->invoke($controller, $session, 1, ['operation' => 'plan', 'status' => 'done', 'message' => 'plan done']);

        $fresh = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($fresh);
        $freshScope = $fresh->getScopeArray();
        $operation = \is_array($freshScope['active_operation'] ?? null) ? $freshScope['active_operation'] : [];
        self::assertSame('done', (string)($operation['status'] ?? ''));
        self::assertSame('', (string)($operation['failure_mode'] ?? ''));
        self::assertSame('done', (string)($operation['semantic_status'] ?? ''));
        self::assertSame(0, (int)($operation['retry_allowed'] ?? 1));
        self::assertSame(0, (int)($operation['retryable_ai_failure_count'] ?? -1));
        self::assertSame([], $operation['retryable_ai_failures'] ?? null);
        self::assertSame(100, (int)($operation['progress_percent'] ?? 0));
        self::assertSame(0, (int)($freshScope['active_operations']['plan']['retryable_ai_failure_count'] ?? -1));
    }

    public function testAiSiteQueueOperationsAreSchedulerOwned(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'isAiSiteQueueBackedOperation');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($controller, 'plan'));
        self::assertFalse((bool)$method->invoke($controller, 'task_plan'));
        self::assertTrue((bool)$method->invoke($controller, 'build'));
        self::assertTrue((bool)$method->invoke($controller, 'block_regenerate'));
        self::assertTrue((bool)$method->invoke($controller, 'block_partial_patch'));
        self::assertTrue((bool)$method->invoke($controller, 'regenerate_page'));
        self::assertFalse((bool)$method->invoke($controller, 'publish'));
    }

    public function testSessionKeepsReusableQueuePerOperation(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $scopePatch = [
            'site_title' => 'Session queue reuse',
            'site_tagline' => 'Each operation queue should be isolated and reused only for the same operation',
            'target_domain' => 'session-queue-reuse.local.test',
            'brief_description' => 'Ensure plan and build each keep their own reusable queue row.',
            'user_description' => 'Ensure plan and build each keep their own reusable queue row.',
            'page_types' => [Page::TYPE_HOME],
        ];
        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $sessionId = (int)$session->getId();

        RequestContext::set('pagebuilder.ai.queue.dispatcher', static function (string $processName, array $meta): array {
            self::assertStringContainsString('queue:run --id=', $processName);
            self::assertContains((string)($meta['operation'] ?? ''), ['plan', 'build']);

            return ['started' => true, 'pid' => 13579];
        });

        try {
            $planPayload = $this->invokeJsonAction(
                '/pagebuilder/backend/ai-site-agent/post-start-plan',
                'POST',
                'postStartPlan',
                [],
                [
                    'public_id' => $publicId,
                    'scope_patch' => $scopePatch,
                ]
            );
            self::assertTrue((bool)($planPayload['success'] ?? false), \json_encode($planPayload, \JSON_UNESCAPED_UNICODE));
            $planQueueId = (int)($planPayload['queue_id'] ?? 0);
            self::assertGreaterThan(0, $planQueueId);

            w_query('queue', 'update', [
                'queue_id' => $planQueueId,
                'patch' => [
                    'status' => 'done',
                    'pid' => 0,
                    'finished' => 1,
                ],
            ]);
            $session = $this->sessionService->loadByPublicId($publicId, 1);
            self::assertNotNull($session);
            $scope = $session->getScopeArray();
            $scope['active_operation'] = [
                'operation' => 'plan',
                'execution_token' => (string)($planPayload['execution_token'] ?? ''),
                'status' => 'done',
                'queue_id' => $planQueueId,
                'message' => 'plan done',
            ];
            $scope['active_operations']['plan'] = $scope['active_operation'];
            $scope['plan_json'] = [
                'confirmed' => 1,
                'summary' => 'confirmed blueprint',
                'pages' => [
                    [
                        'page_type' => Page::TYPE_HOME,
                        'title' => 'Home',
                    ],
                ],
            ];
            $scope['website_profile'] = [
                'site_title' => 'Session queue reuse',
            ];
            $this->sessionService->replaceScope($session->getId(), 1, $scope);

            $session = $this->sessionService->loadByPublicId($publicId, 1);
            self::assertNotNull($session);
            $scope = $session->getScopeArray();
            $PlanJsonService = new AiSitePlanJsonService();
            $PlanJson = $PlanJsonService->confirm($PlanJsonService->buildFromScope([
                'page_types' => [Page::TYPE_HOME],
                'site_title' => 'Session queue reuse',
                'brief_description' => 'Verify reusable queue slots without duplicating build state.',
                'default_locale' => 'en_US',
                'plan_json' => [
                    'signature' => 'queue-reuse-stage-one-signature',
                    'pages' => [
                        Page::TYPE_HOME => [
                            'title' => 'Home',
                            'page_goal' => 'Confirm that build queue rows stay small.',
                            'hero' => [
                                'block_key' => 'hero',
                                'title' => 'Queue payload hygiene',
                                'goal' => 'Show the build queue stores references instead of full context.',
                                'page_flow_role' => 'opening_conversions',
                                'visual_signature' => [
                                    'composition_pattern' => 'hero section with compact proof rail and direct queue hygiene message',
                                    'spatial_rhythm' => 'headline, short support copy, proof detail, and focused action row',
                                    'media_strategy' => 'CSS-only/no generated image; use queue status chips and dividers as the visual motif',
                                    'surface_treatment' => 'clean panels with restrained contrast and readable proof surfaces',
                                    'interaction_pattern' => 'CTA hover lift with focus-visible outline and no ambient motion',
                                ],
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'Build queues stay small by reloading session artifacts.'],
                                    ['field' => 'cta', 'sample' => 'Review queue details'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]));
            $PlanJsonValidation = $PlanJsonService->validate($PlanJson);
            self::assertTrue(
                (bool)($PlanJsonValidation['valid'] ?? false),
                \json_encode($PlanJsonValidation, \JSON_UNESCAPED_UNICODE)
            );
            $scope['plan_json'] = \array_replace($PlanJson, ['confirmed' => 1]);
            $this->sessionService->replaceScope($session->getId(), 1, $scope);

            $buildPayload = $this->invokeJsonAction(
                '/pagebuilder/backend/ai-site-agent/post-start-build',
                'POST',
                'postStartBuild',
                [],
                [
                    'public_id' => $publicId,
                    'scope_patch' => $scopePatch,
                ]
            );
            self::assertTrue((bool)($buildPayload['success'] ?? false), \json_encode($buildPayload, \JSON_UNESCAPED_UNICODE));
            $buildQueueId = (int)($buildPayload['queue_id'] ?? 0);
            self::assertGreaterThan(0, $buildQueueId);
            self::assertNotSame($planQueueId, $buildQueueId, 'Build should keep its own queue row.');

            w_query('queue', 'update', [
                'queue_id' => $buildQueueId,
                'patch' => [
                    'status' => 'done',
                    'pid' => 0,
                    'finished' => 1,
                ],
            ]);
            $session = $this->sessionService->loadByPublicId($publicId, 1);
            self::assertNotNull($session);
            $scope = $session->getScopeArray();
            $scope['active_operation'] = [
                'operation' => 'build',
                'execution_token' => (string)($buildPayload['execution_token'] ?? ''),
                'status' => 'done',
                'queue_id' => $buildQueueId,
                'message' => 'build done',
            ];
            $scope['active_operations']['build'] = $scope['active_operation'];
            $this->sessionService->replaceScope($session->getId(), 1, $scope);

            $buildAgainPayload = $this->invokeJsonAction(
                '/pagebuilder/backend/ai-site-agent/post-start-build',
                'POST',
                'postStartBuild',
                [],
                [
                    'public_id' => $publicId,
                    'scope_patch' => $scopePatch,
                ]
            );
            self::assertTrue((bool)($buildAgainPayload['success'] ?? false), \json_encode($buildAgainPayload, \JSON_UNESCAPED_UNICODE));
            $buildAgainQueueId = (int)($buildAgainPayload['queue_id'] ?? 0);
            self::assertGreaterThan($buildQueueId, $buildAgainQueueId, 'Repeated build should enqueue a fresh scheduler-owned row when the prior row is terminal.');

            $planQueue = w_query('queue', 'get', ['queue_id' => $planQueueId]);
            self::assertIsArray($planQueue);
            self::assertSame('glr_aisite:session:' . $sessionId . ':queue_slot:plan', (string)($planQueue['biz_key'] ?? ''));
            $planQueueContent = \is_array($planQueue['content'] ?? null)
                ? $planQueue['content']
                : \json_decode((string)($planQueue['content'] ?? ''), true);
            self::assertIsArray($planQueueContent);
            self::assertSame('plan', (string)($planQueueContent['operation'] ?? ''));
            self::assertSame('stage1.requirement_expand', (string)($planQueueContent['job_type'] ?? ''));

            $buildQueue = w_query('queue', 'get', ['queue_id' => $buildQueueId]);
            self::assertIsArray($buildQueue);
            self::assertSame('glr_aisite:session:' . $sessionId . ':queue_slot:build', (string)($buildQueue['biz_key'] ?? ''));
            $buildQueueContent = \is_array($buildQueue['content'] ?? null)
                ? $buildQueue['content']
                : \json_decode((string)($buildQueue['content'] ?? ''), true);
            self::assertIsArray($buildQueueContent);
            self::assertSame('build', (string)($buildQueueContent['operation'] ?? ''));
            self::assertSame('virtual_theme.tree.build', (string)($buildQueueContent['job_type'] ?? ''));
            self::assertIsArray($buildQueueContent['scope_patch'] ?? null);
            self::assertArrayHasKey('scope_patch', $buildQueueContent);
        } finally {
            RequestContext::remove('pagebuilder.ai.queue.dispatcher');
        }
    }

    public function testStartPlanQueuesWorkForSystemScheduler(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $scopePatch = [
            'site_title' => 'Queued dispatch smoke test',
            'site_tagline' => 'Plan dispatch should not rely on cron',
            'target_domain' => 'queued-dispatch-smoke.local.test',
            'brief_description' => 'Verify PageBuilder starts its own queue worker when phase one is enqueued.',
            'user_description' => 'Verify PageBuilder starts its own queue worker when phase one is enqueued.',
            'page_types' => [Page::TYPE_HOME],
        ];

        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        RequestContext::set('pagebuilder.ai.queue.dispatcher', static function (string $processName, array $meta): array {
            self::assertStringContainsString('queue:run --id=', $processName);
            self::assertSame('plan', (string)($meta['operation'] ?? ''));

            return ['started' => true, 'pid' => 24680];
        });

        try {
            $startPlanPayload = $this->invokeJsonAction(
                '/pagebuilder/backend/ai-site-agent/post-start-plan',
                'POST',
                'postStartPlan',
                [],
                [
                    'public_id' => $publicId,
                    'scope_patch' => $scopePatch,
                ]
            );
        } finally {
            RequestContext::remove('pagebuilder.ai.queue.dispatcher');
        }

        self::assertTrue((bool)($startPlanPayload['success'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));
        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $activeOperation = \is_array($session->getScopeArray()['active_operation'] ?? null) ? $session->getScopeArray()['active_operation'] : [];
        $queueId = (int)($activeOperation['queue_id'] ?? 0);
        self::assertGreaterThan(0, $queueId);
        self::assertArrayNotHasKey('queue_dispatch', $startPlanPayload);
        self::assertTrue((bool)($startPlanPayload['queue_wait']['queue_waiting_for_scheduler'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool)($startPlanPayload['queue_wait']['can_close_stream'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));

        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        $queueStatus = (string)($queue['status'] ?? '');
        self::assertContains($queueStatus, ['pending', 'running'], \json_encode($queue, \JSON_UNESCAPED_UNICODE));
        if ($queueStatus === 'pending') {
            self::assertSame(0, (int)($queue['pid'] ?? 0));
        }

        $queueContent = \is_array($queue['content'] ?? null)
            ? $queue['content']
            : \json_decode((string)($queue['content'] ?? ''), true);
        self::assertIsArray($queueContent);
        self::assertSame('stage1.requirement_expand', (string)($queueContent['job_type'] ?? ''));
        self::assertStringStartsWith('glr_aisite:session:' . (int)$session->getId() . ':job:stage1.requirement_expand', (string)($queueContent['job_key'] ?? ''));
        self::assertContains((string)($queueContent['status'] ?? ''), ['queued', 'running']);
        self::assertSame((string)($activeOperation['execution_token'] ?? ''), (string)($queueContent['token'] ?? ''));
        self::assertSame((string)($queueContent['job_key'] ?? ''), (string)($activeOperation['job_key'] ?? ''));
        self::assertSame((string)($queueContent['job_type'] ?? ''), (string)($activeOperation['job_type'] ?? ''));
        self::assertSame((string)($queueContent['token'] ?? ''), (string)($activeOperation['token'] ?? ''));
    }

    public function testStartPlanRebuildsWhenCoreSiteInputsChangeWithoutLocaleOrPageTypeChanges(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $initialScopePatch = [
            'site_title' => 'Original planning title',
            'site_tagline' => 'Original planning tagline',
            'target_domain' => 'rebuild-on-input-change.local.test',
            'brief_description' => 'Original planning brief used to seed the first stage blueprint.',
            'user_description' => 'Original planning brief used to seed the first stage blueprint.',
            'default_locale' => 'zh_Hans_CN',
            'plan_locale' => 'zh_Hans_CN',
            'page_types' => [Page::TYPE_HOME],
        ];
        $this->generateAndConfirmPlan($publicId, $initialScopePatch);

        RequestContext::set('pagebuilder.ai.queue.dispatcher', static function (string $processName, array $meta): array {
            self::assertStringContainsString('queue:run --id=', $processName);
            self::assertSame('plan', (string)($meta['operation'] ?? ''));

            return ['started' => true, 'pid' => 97531];
        });

        try {
            $rebuildPayload = $this->invokeJsonAction(
                '/pagebuilder/backend/ai-site-agent/post-start-plan',
                'POST',
                'postStartPlan',
                [],
                [
                    'public_id' => $publicId,
                    'scope_patch' => [
                        'site_title' => 'Updated planning title',
                        'site_tagline' => 'Original planning tagline',
                        'target_domain' => 'rebuild-on-input-change.local.test',
                        'brief_description' => 'Updated planning brief should force stage-one regeneration immediately.',
                        'user_description' => 'Updated planning brief should force stage-one regeneration immediately.',
                        'default_locale' => 'zh_Hans_CN',
                        'plan_locale' => 'zh_Hans_CN',
                        'page_types' => [Page::TYPE_HOME],
                    ],
                    'confirm_regenerate' => '1',
                ]
            );
        } finally {
            RequestContext::remove('pagebuilder.ai.queue.dispatcher');
        }

        self::assertTrue((bool)($rebuildPayload['success'] ?? false), \json_encode($rebuildPayload, \JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool)($rebuildPayload['start_sse'] ?? false), \json_encode($rebuildPayload, \JSON_UNESCAPED_UNICODE));
        self::assertSame('rebuild', (string)($rebuildPayload['plan_action'] ?? ''));
        self::assertFalse((bool)($rebuildPayload['plan_page_types_changed'] ?? false));
        self::assertFalse((bool)($rebuildPayload['plan_locale_changed'] ?? false));
        self::assertTrue((bool)($rebuildPayload['plan_source_changed'] ?? false), \json_encode($rebuildPayload, \JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(0, (int)($rebuildPayload['queue_id'] ?? 0));
        self::assertArrayNotHasKey('queue_dispatch', $rebuildPayload);
        self::assertTrue((bool)($rebuildPayload['queue_wait']['queue_waiting_for_scheduler'] ?? false), \json_encode($rebuildPayload, \JSON_UNESCAPED_UNICODE));
    }

    public function testStartPlanPersistsActiveOperationBeforeQueueCreationCanBeObserved(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $scopePatch = [
            'site_title' => 'Queue ordering regression',
            'site_tagline' => 'Persist active operation before queue create',
            'target_domain' => 'queue-ordering-regression.local.test',
            'brief_description' => 'Ensure active_operation exists before queue workers can observe the new job.',
            'user_description' => 'Ensure active_operation exists before queue workers can observe the new job.',
            'page_types' => [Page::TYPE_HOME],
        ];

        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        RequestContext::set('pagebuilder.ai.queue.created', function (array $meta) use ($publicId): void {
            self::assertSame('plan', (string)($meta['operation'] ?? ''));
            $session = $this->sessionService->loadByPublicId($publicId, 1);
            self::assertNotNull($session);

            $scope = $session->getScopeArray();
            $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            self::assertSame('plan', (string)($activeOperation['operation'] ?? ''));
            self::assertSame((string)($meta['execution_token'] ?? ''), (string)($activeOperation['execution_token'] ?? ''));
            self::assertSame('queued', (string)($activeOperation['status'] ?? ''));
            self::assertSame('Operation completed.', (string)($activeOperation['message'] ?? ''));
        });
        RequestContext::set('pagebuilder.ai.queue.dispatcher', static function (string $processName, array $meta): array {
            self::assertStringContainsString('queue:run --id=', $processName);
            self::assertSame('plan', (string)($meta['operation'] ?? ''));

            return ['started' => true, 'pid' => 86420];
        });

        try {
            $startPlanPayload = $this->invokeJsonAction(
                '/pagebuilder/backend/ai-site-agent/post-start-plan',
                'POST',
                'postStartPlan',
                [],
                [
                    'public_id' => $publicId,
                    'scope_patch' => $scopePatch,
                ]
            );
        } finally {
            RequestContext::remove('pagebuilder.ai.queue.created');
            RequestContext::remove('pagebuilder.ai.queue.dispatcher');
        }

        self::assertTrue((bool)($startPlanPayload['success'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));
        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $activeOperation = \is_array($session->getScopeArray()['active_operation'] ?? null) ? $session->getScopeArray()['active_operation'] : [];
        self::assertGreaterThan(0, (int)($activeOperation['queue_id'] ?? 0));
    }

    public function testClaimedQueueBackedOperationSseBranchRejectsDirectExecution(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $scopePatch = [
            'fake_mode' => 1,
            'site_title' => 'Operation SSE plan branch',
            'site_tagline' => 'Plan branch regression',
            'target_domain' => 'operation-sse-plan.local.test',
            'brief_description' => 'Verify operation-sse can dispatch the plan branch instead of returning unknown operation.',
            'user_description' => 'Verify operation-sse can dispatch the plan branch instead of returning unknown operation.',
            'default_locale' => 'zh_Hans_CN',
            'plan_locale' => 'zh_Hans_CN',
            'page_types' => [Page::TYPE_HOME],
        ];

        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $executionToken = 'operation-sse-plan-branch-token';
        $startedAt = \date('Y-m-d H:i:s');
        $scope = $session->getScopeArray();
        $scope['active_operation'] = [
            'operation' => 'plan',
            'execution_token' => $executionToken,
            'status' => 'running',
            'message' => 'claimed by operation-sse',
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'progress_percent' => 0,
        ];
        $this->sessionService->replaceScope($session->getId(), 1, $scope);
        $session = $this->sessionService->loadById($session->getId(), 1);
        self::assertNotNull($session);

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'runClaimedOperationSseBranch');
        $method->setAccessible(true);

        $writer = new InMemorySseWriter();
        self::expectException(\RuntimeException::class);

        $method->invoke(
            $controller,
            $writer,
            $session,
            1,
            'plan',
            $scope['active_operation']
        );
    }

    public function testDuplicateOperationObserverWaitsForSchedulerOwnedPendingPlanQueue(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $executionToken = 'observer-pending-plan-queue-token';
        $queueCreated = w_query('queue', 'create', [
            'class' => \GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue::class,
            'name' => 'Pending observer plan queue',
            'module' => 'GuoLaiRen_PageBuilder',
            'content' => [
                'public_id' => $publicId,
                'admin_id' => 1,
                'execution_token' => $executionToken,
                'operation' => 'plan',
                'stage' => AiSiteAgentSession::STAGE_PLAN,
            ],
            'status' => 'pending',
            'auto' => true,
            'biz_key' => 'glr_aisite:test:observer:' . \substr(\sha1($publicId), 0, 12),
        ]);
        self::assertIsArray($queueCreated);
        $queueId = (int)($queueCreated['queue_id'] ?? 0);
        self::assertGreaterThan(0, $queueId);

        $startedAt = \date('Y-m-d H:i:s');
        $scope = $session->getScopeArray();
        $scope['active_operation'] = [
            'operation' => 'plan',
            'execution_token' => $executionToken,
            'status' => 'queued',
            'message' => 'waiting for queue worker',
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'queue_id' => $queueId,
        ];
        $this->sessionService->replaceScope($session->getId(), 1, $scope);

        RequestContext::set('pagebuilder.ai.queue.dispatcher', static function (string $processName, array $meta): array {
            self::assertStringContainsString('queue:run --id=', $processName);
            self::assertSame('plan', (string)($meta['operation'] ?? ''));

            return ['started' => true, 'pid' => 13579];
        });

        try {
            $writer = new DuplicateObserverHeartbeatWriter(function () use ($session, $executionToken, $queueId): void {
                $fresh = $this->sessionService->loadById($session->getId(), 1);
                self::assertNotNull($fresh);

                $scope = $fresh->getScopeArray();
                $scope['active_operation'] = \array_replace(
                    \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
                    [
                        'operation' => 'plan',
                        'execution_token' => $executionToken,
                        'status' => 'done',
                        'message' => 'plan done',
                        'updated_at' => \date('Y-m-d H:i:s'),
                    ]
                );
                $this->sessionService->replaceScope($fresh->getId(), 1, $scope);
                w_query('queue', 'update', [
                    'queue_id' => $queueId,
                    'patch' => [
                        'status' => 'done',
                        'pid' => 13579,
                        'finished' => 1,
                        'process' => 'plan done',
                        'result' => 'plan done',
                    ],
                ]);
            });

            /** @var AiSiteAgent $controller */
            $controller = ObjectManager::getInstance(AiSiteAgent::class);
            $method = new ReflectionMethod(AiSiteAgent::class, 'observeDuplicateOperationStream');
            $method->setAccessible(true);
            $result = $method->invoke($controller, $writer, $session, 1, 'plan', $executionToken);
        } finally {
            RequestContext::remove('pagebuilder.ai.queue.dispatcher');
        }

        self::assertIsArray($result);
        self::assertTrue((bool)($result['success'] ?? false), \json_encode($result, \JSON_UNESCAPED_UNICODE));
        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertSame('done', (string)($queue['status'] ?? ''));
        self::assertSame(13579, (int)($queue['pid'] ?? 0));
    }

    public function testDuplicateOperationObserverDefersErrorEventsUntilQueueSettlesDone(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $executionToken = 'observer-defer-error-until-done-token';
        $queueCreated = w_query('queue', 'create', [
            'class' => \GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue::class,
            'name' => 'Running observer plan queue with transient error event',
            'module' => 'GuoLaiRen_PageBuilder',
            'content' => [
                'public_id' => $publicId,
                'admin_id' => 1,
                'execution_token' => $executionToken,
                'operation' => 'plan',
                'stage' => AiSiteAgentSession::STAGE_PLAN,
            ],
            'status' => 'running',
            'pid' => 24681,
            'auto' => true,
            'biz_key' => 'glr_aisite:test:observer:defer-error:' . \substr(\sha1($publicId), 0, 12),
        ]);
        self::assertIsArray($queueCreated);
        $queueId = (int)($queueCreated['queue_id'] ?? 0);
        self::assertGreaterThan(0, $queueId);

        $startedAt = \date('Y-m-d H:i:s');
        $scope = $session->getScopeArray();
        $scope['active_operation'] = [
            'operation' => 'plan',
            'execution_token' => $executionToken,
            'status' => 'running',
            'message' => 'waiting for queue status settle',
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'queue_id' => $queueId,
        ];
        $this->sessionService->replaceScope($session->getId(), 1, $scope);
        $session = $this->sessionService->loadById($session->getId(), 1);
        self::assertNotNull($session);

        $this->sessionService->appendEvent(
            $session->getId(),
            1,
            'operation_failed',
            [
                'message' => 'transient failure should wait for queue truth',
                'operation' => 'plan',
                'execution_token' => $executionToken,
                'queue_id' => $queueId,
                'details' => [
                    'execution_token' => $executionToken,
                    'queue_id' => $queueId,
                ],
            ],
            AiSiteAgentSession::STAGE_PLAN
        );

        RequestContext::set('pagebuilder.ai.observer.queue_settle_delay_ms', 0);
        try {
            $writer = new DuplicateObserverHeartbeatWriter(function () use ($session, $executionToken, $queueId): void {
                $fresh = $this->sessionService->loadById($session->getId(), 1);
                self::assertNotNull($fresh);

                $scope = $fresh->getScopeArray();
                $scope['active_operation'] = \array_replace(
                    \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
                    [
                        'operation' => 'plan',
                        'execution_token' => $executionToken,
                        'status' => 'done',
                        'message' => 'plan done after queue settle',
                        'updated_at' => \date('Y-m-d H:i:s'),
                    ]
                );
                $this->sessionService->replaceScope($fresh->getId(), 1, $scope);
                w_query('queue', 'update', [
                    'queue_id' => $queueId,
                    'patch' => [
                        'status' => 'done',
                        'pid' => 24681,
                        'finished' => 1,
                        'process' => 'plan done after queue settle',
                        'result' => 'plan done after queue settle',
                    ],
                ]);
            });

            /** @var AiSiteAgent $controller */
            $controller = ObjectManager::getInstance(AiSiteAgent::class);
            $method = new ReflectionMethod(AiSiteAgent::class, 'observeDuplicateOperationStream');
            $method->setAccessible(true);
            $result = $method->invoke($controller, $writer, $session, 1, 'plan', $executionToken);
        } finally {
            RequestContext::remove('pagebuilder.ai.observer.queue_settle_delay_ms');
        }

        self::assertIsArray($result);
        self::assertTrue((bool)($result['success'] ?? false), \json_encode($result, \JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $writer->countEvents('error'), \json_encode($writer->eventsByName('error'), \JSON_UNESCAPED_UNICODE));
        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertSame('done', (string)($queue['status'] ?? ''));
        self::assertSame(24681, (int)($queue['pid'] ?? 0));
        return;

        self::assertTrue((bool)($result['success'] ?? false), \json_encode($result, \JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $writer->countEvents('error'), \json_encode($writer->eventsByName('error'), \JSON_UNESCAPED_UNICODE));

        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertSame('done', (string)($queue['status'] ?? ''));
    }

    public function testDuplicateOperationObserverDoesNotAutoRecoverErroredPlanQueue(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $executionToken = 'observer-errored-plan-queue-token';
        $queueCreated = w_query('queue', 'create', [
            'class' => \GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue::class,
            'name' => 'Errored observer plan queue',
            'module' => 'GuoLaiRen_PageBuilder',
            'content' => [
                'public_id' => $publicId,
                'admin_id' => 1,
                'execution_token' => $executionToken,
                'operation' => 'plan',
                'stage' => AiSiteAgentSession::STAGE_PLAN,
            ],
            'status' => 'error',
            'auto' => true,
            'biz_key' => 'glr_aisite:test:observer:error:' . \substr(\sha1($publicId), 0, 12),
        ]);
        self::assertIsArray($queueCreated);
        $queueId = (int)($queueCreated['queue_id'] ?? 0);
        self::assertGreaterThan(0, $queueId);

        $startedAt = \date('Y-m-d H:i:s');
        $scope = $session->getScopeArray();
        $scope['active_operation'] = [
            'operation' => 'plan',
            'execution_token' => $executionToken,
            'status' => 'queued',
            'message' => 'waiting for queue recovery',
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'queue_id' => $queueId,
        ];
        $this->sessionService->replaceScope($session->getId(), 1, $scope);

        RequestContext::set('pagebuilder.ai.queue.dispatcher', static function (string $processName, array $meta): array {
            self::assertStringContainsString('queue:run --id=', $processName);
            self::assertSame('plan', (string)($meta['operation'] ?? ''));

            return ['started' => true, 'pid' => 97531];
        });

        try {
            $writer = new DuplicateObserverHeartbeatWriter(function () use ($session, $executionToken, $queueId): void {
                $fresh = $this->sessionService->loadById($session->getId(), 1);
                self::assertNotNull($fresh);

                $scope = $fresh->getScopeArray();
                $scope['active_operation'] = \array_replace(
                    \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
                    [
                        'operation' => 'plan',
                        'execution_token' => $executionToken,
                        'status' => 'done',
                        'message' => 'plan done',
                        'updated_at' => \date('Y-m-d H:i:s'),
                    ]
                );
                $this->sessionService->replaceScope($fresh->getId(), 1, $scope);
                w_query('queue', 'update', [
                    'queue_id' => $queueId,
                    'patch' => [
                        'status' => 'done',
                        'pid' => 97531,
                        'finished' => 1,
                        'process' => 'plan done',
                        'result' => 'plan done',
                    ],
                ]);
            });

            /** @var AiSiteAgent $controller */
            $controller = ObjectManager::getInstance(AiSiteAgent::class);
            $method = new ReflectionMethod(AiSiteAgent::class, 'observeDuplicateOperationStream');
            $method->setAccessible(true);
            $result = $method->invoke($controller, $writer, $session, 1, 'plan', $executionToken);
        } finally {
            RequestContext::remove('pagebuilder.ai.queue.dispatcher');
        }

        self::assertIsArray($result);
        self::assertFalse((bool)($result['success'] ?? true), \json_encode($result, \JSON_UNESCAPED_UNICODE));
        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertSame('error', (string)($queue['status'] ?? ''));
        self::assertSame(0, (int)($queue['pid'] ?? 0));
    }

    public function testDuplicateOperationObserverContinuesForwardingProgressUntilBuildFinishes(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $scopePatch = [
            'site_title' => 'Duplicate observer regression',
            'site_tagline' => 'Keep following duplicate operation streams',
            'target_domain' => 'duplicate-observer-regression.local.test',
            'brief_description' => 'Regression test for duplicate operation stream observer mode.',
            'user_description' => 'Regression test for duplicate operation stream observer mode.',
            'page_types' => [Page::TYPE_HOME],
        ];

        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        $planFlow = $this->generateAndConfirmPlan($publicId, $scopePatch);
        self::assertSame(1, (int)($planFlow['confirm_plan']['data']['plan_json']['confirmed'] ?? 0));
        $startBuildPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-build',
            'POST',
            'postStartBuild',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($startBuildPayload['success'] ?? false), \json_encode($startBuildPayload, \JSON_UNESCAPED_UNICODE));

        $executionToken = (string)($startBuildPayload['execution_token'] ?? '');
        self::assertNotSame('', $executionToken);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $queueId = (int)($session->getScopeArray()['active_operation']['queue_id'] ?? 0);

        $startedAt = \date('Y-m-d H:i:s');
        $scope = $session->getScopeArray();
        $scope['workspace_status'] = 'building';
        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            [
                'operation' => 'build',
                'execution_token' => $executionToken,
                'status' => 'running',
                'message' => 'Build queue is running.',
                'page_type' => Page::TYPE_HOME,
                'progress_percent' => 20,
                'started_at' => $startedAt,
                'updated_at' => $startedAt,
            ]
        );
        $this->sessionService->replaceScope($session->getId(), 1, $scope);
        $this->sessionService->appendEvent(
            $session->getId(),
            1,
            'operation_progress',
            [
                'message' => 'Build queue is running.',
                'operation' => 'build',
                'page_type' => Page::TYPE_HOME,
                'progress_percent' => 20,
                'details' => [],
            ],
            AiSiteAgentSession::STAGE_VISUAL_EDIT
        );

        $writer = new DuplicateObserverHeartbeatWriter(function () use ($session, $executionToken, $queueId): void {
            $fresh = $this->sessionService->loadById($session->getId(), 1);
            self::assertNotNull($fresh);

            $scope = $fresh->getScopeArray();
            $scope['workspace_status'] = 'can_publish';
            $scope['active_operation'] = \array_replace(
                \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
                [
                    'operation' => 'build',
                    'execution_token' => $executionToken,
                    'status' => 'done',
                    'message' => 'Build queue completed.',
                    'page_type' => Page::TYPE_HOME,
                    'progress_percent' => 100,
                    'updated_at' => \date('Y-m-d H:i:s'),
                ]
            );
            $this->sessionService->replaceScope($fresh->getId(), 1, $scope);
            if ($queueId > 0) {
                w_query('queue', 'update', [
                    'queue_id' => $queueId,
                    'patch' => [
                        'status' => 'done',
                        'process' => 'Build queue completed.',
                        'result' => 'Build queue completed.',
                    ],
                ]);
            }
            $this->sessionService->appendEvent(
                $fresh->getId(),
                1,
                'operation_progress',
                [
                    'message' => 'Build queue progress update.',
                    'operation' => 'build',
                    'page_type' => Page::TYPE_HOME,
                    'progress_percent' => 80,
                    'details' => [],
                ],
                AiSiteAgentSession::STAGE_VISUAL_EDIT
            );
            $this->sessionService->appendEvent(
                $fresh->getId(),
                1,
                'task_completed',
                [
                    'message' => 'Build task completed: hero.',
                    'operation' => 'build',
                    'page_type' => Page::TYPE_HOME,
                    'task_key' => 'page:home_page:hero',
                    'task_type' => 'page_section',
                    'details' => [
                        'section_code' => 'hero',
                    ],
                ],
                AiSiteAgentSession::STAGE_VISUAL_EDIT
            );
        });

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'observeDuplicateOperationStream');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $writer, $session, 1, 'build', $executionToken);

        self::assertIsArray($result);
        self::assertTrue((bool)($result['success'] ?? false), \json_encode($result, \JSON_UNESCAPED_UNICODE));
        self::assertNotSame('', (string)($result['message'] ?? ''));
        self::assertGreaterThanOrEqual(2, $writer->countEvents('progress'));

        $progressMessages = [];
        foreach ($writer->eventsByName('progress') as $event) {
            $payload = \is_array($event['data'] ?? null) ? $event['data'] : [];
            $progressMessages[] = (string)($payload['message'] ?? '');
        }
        self::assertNotSame([], $progressMessages);
    }
    public function testDuplicateOperationObserverForwardsTaskCompletedEvents(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $executionToken = 'observer-task-complete-token';
        $startedAt = \date('Y-m-d H:i:s');
        $scope = $session->getScopeArray();
        $scope['active_operation'] = [
            'operation' => 'build',
            'execution_token' => $executionToken,
            'status' => 'done',
            'message' => 'build done',
            'updated_at' => $startedAt,
        ];
        $this->sessionService->replaceScope($session->getId(), 1, $scope);
        $this->sessionService->appendEvent(
            $session->getId(),
            1,
            'task_completed',
            [
                'message' => 'task completed',
                'operation' => 'build',
                'page_type' => Page::TYPE_HOME,
                'task_key' => 'page:home_page:hero',
                'task_type' => 'page_section',
                'details' => [
                    'section_code' => 'hero',
                ],
            ],
            AiSiteAgentSession::STAGE_VISUAL_EDIT
        );

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'observeDuplicateOperationStream');
        $method->setAccessible(true);

        $writer = new DummyTaskCompletedObserverWriter();
        $result = $method->invoke($controller, $writer, $session, 1, 'build', $executionToken);

        self::assertIsArray($result);
        self::assertTrue((bool)($result['success'] ?? false), \json_encode($result, \JSON_UNESCAPED_UNICODE));
        $taskCompletedEvents = $writer->eventsByName('task_completed');
        self::assertCount(1, $taskCompletedEvents);
        $payload = \is_array($taskCompletedEvents[0]['data'] ?? null) ? $taskCompletedEvents[0]['data'] : [];
        self::assertSame('page:home_page:hero', (string)($payload['task_key'] ?? ''));
        self::assertSame('page_section', (string)($payload['task_type'] ?? ''));
        self::assertSame(Page::TYPE_HOME, (string)($payload['page_type'] ?? ''));
        self::assertSame('hero', (string)($payload['section_code'] ?? ''));
    }

    public function testDuplicateOperationObserverForwardsPlanningInfoAndSuppressesChunkContent(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $executionToken = 'observer-plan-info-token';
        $startedAt = \date('Y-m-d H:i:s');
        $scope = $session->getScopeArray();
        $scope['active_operation'] = [
            'operation' => 'plan',
            'execution_token' => $executionToken,
            'status' => 'done',
            'message' => 'plan done',
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'progress_percent' => 100,
        ];
        $scope['plan_markdown'] = '# Queue observer plan';
        $scope['plan_json'] = [
            'title' => 'Queue observer plan',
            'pages' => [
                'home_page' => [
                    'title' => 'Home',
                ],
            ],
        ];
        $this->sessionService->replaceScope($session->getId(), 1, $scope);
        $this->sessionService->appendEvent(
            $session->getId(),
            1,
            'plan_generated',
            [
                'message' => 'queue info visible',
                'operation' => 'plan',
            ],
            AiSiteAgentSession::STAGE_PLAN
        );
        $this->sessionService->appendEvent(
            $session->getId(),
            1,
            'plan_chunk',
            [
                'message' => 'hero chunk visible',
                'operation' => 'plan',
            ],
            AiSiteAgentSession::STAGE_PLAN
        );

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'observeDuplicateOperationStream');
        $method->setAccessible(true);

        $writer = new DummyTaskCompletedObserverWriter();
        $result = $method->invoke($controller, $writer, $session, 1, 'plan', $executionToken);

        self::assertIsArray($result);
        self::assertTrue((bool)($result['success'] ?? false), \json_encode($result, \JSON_UNESCAPED_UNICODE));

        $infoEvents = $writer->eventsByName('info');
        self::assertCount(1, $infoEvents);
        $infoPayload = \is_array($infoEvents[0]['data'] ?? null) ? $infoEvents[0]['data'] : [];
        self::assertSame('queue info visible', (string)($infoPayload['message'] ?? ''));
        self::assertSame('plan', (string)($infoPayload['operation'] ?? ''));
        self::assertSame('plan_generated', (string)($infoPayload['event_type'] ?? ''));
        self::assertIsArray($infoPayload['state'] ?? null);
        self::assertSame('plan', (string)($infoPayload['state']['stage'] ?? ''));

        $progressEvents = $writer->eventsByName('progress');
        self::assertCount(0, $progressEvents);
    }

    public function testWorkspaceEventRejectsRemovedTaskPlanQueueOperation(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'workspaceEventMatchesStage');
        $method->setAccessible(true);

        self::assertFalse((bool)$method->invoke($controller, [
            'event_type' => 'info',
            'payload' => [
                'operation' => 'task_plan',
                'message' => 'removed task plan queue info',
            ],
            'stage_code' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
        ], 'task_plan'));
    }

    public function testDuplicateOperationObserverSuppressesAiRawChunkContent(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $executionToken = 'observer-ai-raw-token';
        $startedAt = \date('Y-m-d H:i:s');
        $scope = $session->getScopeArray();
        $scope['active_operation'] = [
            'operation' => 'plan',
            'execution_token' => $executionToken,
            'status' => 'done',
            'message' => 'plan done',
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
        ];
        $this->sessionService->replaceScope($session->getId(), 1, $scope);
        $this->sessionService->appendEvent(
            $session->getId(),
            1,
            'ai_raw_chunk',
            [
                'message' => '{"markdown":"raw ai"}',
                'chunk' => '{"markdown":"raw ai"}',
                'content' => '{"markdown":"raw ai"}',
                'operation' => 'plan',
            ],
            AiSiteAgentSession::STAGE_PLAN
        );

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'observeDuplicateOperationStream');
        $method->setAccessible(true);

        $writer = new DummyTaskCompletedObserverWriter();
        $result = $method->invoke($controller, $writer, $session, 1, 'plan', $executionToken);

        self::assertIsArray($result);
        self::assertTrue((bool)($result['success'] ?? false), \json_encode($result, \JSON_UNESCAPED_UNICODE));

        $progressEvents = $writer->eventsByName('progress');
        self::assertCount(0, $progressEvents);
    }

    public function testObservedQueueAiStreamLinesAreSkippedForPlanningOperations(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldSkipObservedQueueResultLine');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($controller, 'plan', '[19:45:15] AI_STREAM {"markdown":"raw"}'));
        self::assertFalse((bool)$method->invoke($controller, 'build', '[19:45:15] AI_STREAM {"markdown":"raw"}'));
    }
}

final class DummyTaskCompletedObserverWriter extends SseWriter
{
    /** @var list<array{event:string,data:mixed}> */
    private array $events = [];

    public function start(): static
    {
        return $this;
    }

    public function maybeHeartbeat(): self
    {
        return $this;
    }

    public function sendEvent(string $event, mixed $data = null, ?int $id = null): static
    {
        $this->events[] = ['event' => $event, 'data' => $data];
        return $this;
    }

    public function sendError(string $message, int $code = 500): static
    {
        $this->events[] = ['event' => 'error', 'data' => ['message' => $message, 'code' => $code]];
        return $this;
    }

    public function complete(mixed $data = null): void
    {
        $this->events[] = ['event' => 'done', 'data' => $data];
    }

    public function isAlive(): bool
    {
        return true;
    }

    /**
     * @return list<array{event:string,data:mixed}>
     */
    public function eventsByName(string $eventName): array
    {
        return \array_values(\array_filter(
            $this->events,
            static fn(array $event): bool => $event['event'] === $eventName
        ));
    }
}
