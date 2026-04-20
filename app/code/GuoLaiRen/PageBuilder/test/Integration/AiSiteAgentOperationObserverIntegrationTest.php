<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use Closure;
use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use ReflectionMethod;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\RequestContext;

final class AiSiteAgentOperationObserverIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testPhasePlanOperationsKeepQueuedObserverStreamOpen(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldKeepQueuedObserverStreamOpen');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($controller, 'plan'));
        self::assertTrue((bool)$method->invoke($controller, 'task_plan'));
        self::assertFalse((bool)$method->invoke($controller, 'build'));
        self::assertFalse((bool)$method->invoke($controller, 'publish'));
    }

    public function testDuplicateOperationObserverIdleTimeoutIsDisabled(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'getObserverMaxIdleLoops');
        $method->setAccessible(true);

        self::assertSame(0, (int)$method->invoke($controller));
    }

    public function testStagePlanningOperationsSkipStaleReclaim(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldReclaimStaleActiveOperation');
        $method->setAccessible(true);

        self::assertFalse((bool)$method->invoke($controller, ['operation' => 'plan']));
        self::assertFalse((bool)$method->invoke($controller, ['operation' => 'task_plan']));
        self::assertTrue((bool)$method->invoke($controller, ['operation' => 'build']));
    }

    public function testOnlyPlanningQueuesUseSelfDispatchWorkerBootstrap(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldSelfDispatchAiSiteQueueOperation');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($controller, 'plan'));
        self::assertTrue((bool)$method->invoke($controller, 'task_plan'));
        self::assertFalse((bool)$method->invoke($controller, 'build'));
    }

    public function testStartPlanImmediatelyDispatchesQueueWorkerWithoutCron(): void
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
        $queueId = (int)($session->getScopeArray()['active_operation']['queue_id'] ?? 0);
        self::assertGreaterThan(0, $queueId);
        self::assertTrue((bool)($startPlanPayload['queue_dispatch']['started'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertSame(24680, (int)($startPlanPayload['queue_dispatch']['pid'] ?? 0));

        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertSame('running', (string)($queue['status'] ?? ''));
        self::assertSame(24680, (int)($queue['pid'] ?? 0));
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
            self::assertSame('等待开始', (string)($activeOperation['message'] ?? ''));
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

    public function testDuplicateOperationObserverAutoDispatchesPendingPlanQueue(): void
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

        $messages = [];
        foreach (\array_merge($writer->eventsByName('info'), $writer->eventsByName('warning')) as $event) {
            $payload = \is_array($event['data'] ?? null) ? $event['data'] : [];
            $messages[] = (string)($payload['message'] ?? '');
        }
        self::assertTrue(
            \count(\array_filter($messages, static fn(string $message): bool => \str_contains($message, '队列已在后台启动 worker'))) > 0,
            \json_encode($messages, \JSON_UNESCAPED_UNICODE)
        );

        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertSame('done', (string)($queue['status'] ?? ''));
        self::assertSame(13579, (int)($queue['pid'] ?? 0));
    }

    public function testDuplicateOperationObserverAutoRecoversErroredPlanQueue(): void
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
        self::assertTrue((bool)($result['success'] ?? false), \json_encode($result, \JSON_UNESCAPED_UNICODE));

        $messages = [];
        foreach (\array_merge($writer->eventsByName('info'), $writer->eventsByName('warning')) as $event) {
            $payload = \is_array($event['data'] ?? null) ? $event['data'] : [];
            $messages[] = (string)($payload['message'] ?? '');
        }
        self::assertTrue(
            \count(\array_filter($messages, static fn(string $message): bool => \str_contains($message, '自动恢复执行'))) > 0,
            \json_encode($messages, \JSON_UNESCAPED_UNICODE)
        );
        self::assertTrue(
            \count(\array_filter($messages, static fn(string $message): bool => \str_contains($message, '队列已在后台启动 worker'))) > 0,
            \json_encode($messages, \JSON_UNESCAPED_UNICODE)
        );

        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertSame('done', (string)($queue['status'] ?? ''));
        self::assertSame(97531, (int)($queue['pid'] ?? 0));
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
        self::assertSame(1, (int)($planFlow['confirm_plan']['data']['plan_confirmed'] ?? 0));
        $taskPlanFlow = $this->seedAndConfirmTaskPlan($publicId, $scopePatch);
        self::assertSame(1, (int)($taskPlanFlow['confirm_task_plan']['data']['task_plan_confirmed'] ?? 0));

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
                'message' => '正在生成首页',
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
                'message' => '正在生成首页',
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
                    'message' => '构建完成',
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
                        'process' => '构建完成',
                        'result' => '构建完成',
                    ],
                ]);
            }
            $this->sessionService->appendEvent(
                $fresh->getId(),
                1,
                'operation_progress',
                [
                    'message' => '正在生成首页主体',
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
                    'message' => '棣栭〉 Hero 鍖哄潡宸插畬鎴?',
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
        self::assertSame('构建完成', (string)($result['message'] ?? ''));
        self::assertGreaterThanOrEqual(2, $writer->countEvents('progress'));

        $progressMessages = [];
        foreach ($writer->eventsByName('progress') as $event) {
            $payload = \is_array($event['data'] ?? null) ? $event['data'] : [];
            $progressMessages[] = (string)($payload['message'] ?? '');
        }
        self::assertContains('正在生成首页', $progressMessages);
        self::assertContains('正在生成首页主体', $progressMessages);
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

    public function testDuplicateOperationObserverForwardsPlanningInfoAndChunkEvents(): void
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
        self::assertSame('# Queue observer plan', (string)($infoPayload['state']['scope']['plan_markdown'] ?? ''));

        $chunkEvents = $writer->eventsByName('chunk');
        self::assertCount(1, $chunkEvents);
        $chunkPayload = \is_array($chunkEvents[0]['data'] ?? null) ? $chunkEvents[0]['data'] : [];
        self::assertSame('hero chunk visible', (string)($chunkPayload['message'] ?? ''));
        self::assertSame('hero chunk visible', (string)($chunkPayload['chunk'] ?? ''));
        self::assertSame('plan', (string)($chunkPayload['operation'] ?? ''));
    }

    public function testWorkspaceEventMatchesTaskPlanOperationForTaskPlanStream(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new ReflectionMethod(AiSiteAgent::class, 'workspaceEventMatchesStage');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($controller, [
            'event_type' => 'info',
            'payload' => [
                'operation' => 'task_plan',
                'message' => 'task plan queue info',
            ],
            'stage_code' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
        ], 'task_plan'));
    }

    public function testDuplicateOperationObserverForwardsAiRawChunkEvents(): void
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

        $chunkEvents = $writer->eventsByName('chunk');
        self::assertCount(1, $chunkEvents);
        $chunkPayload = \is_array($chunkEvents[0]['data'] ?? null) ? $chunkEvents[0]['data'] : [];
        self::assertSame('plan', (string)($chunkPayload['operation'] ?? ''));
        self::assertSame('{"markdown":"raw ai"}', (string)($chunkPayload['chunk'] ?? ''));
        self::assertSame('{"markdown":"raw ai"}', (string)($chunkPayload['message'] ?? ''));
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

final class DuplicateObserverHeartbeatWriter extends SseWriter
{
    private bool $heartbeatTriggered = false;
    /** @var list<array{event:string,data:mixed}> */
    private array $events = [];

    public function __construct(
        private readonly Closure $onFirstHeartbeat,
    ) {
    }

    public function start(): static
    {
        return $this;
    }

    public function maybeHeartbeat(): self
    {
        if (!$this->heartbeatTriggered) {
            $this->heartbeatTriggered = true;
            ($this->onFirstHeartbeat)();
        }

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

    public function countEvents(string $eventName): int
    {
        return \count($this->eventsByName($eventName));
    }
}
