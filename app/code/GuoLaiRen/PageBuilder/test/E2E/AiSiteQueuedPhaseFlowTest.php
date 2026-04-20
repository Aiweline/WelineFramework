<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\E2E;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Test\Integration\AbstractAiSiteWorkbenchIntegrationHarness;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Console\Queue\Run as QueueRunCommand;

class AiSiteQueuedPhaseFlowTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testQueuedPlanTaskPlanAndBuildFlow(): void
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
            'site_title' => 'Queued Phase Flow Test',
            'site_tagline' => 'Queue-driven AI generation flow',
            'target_domain' => 'queued-phase-flow.local.test',
            'brief_description' => 'Verify stage one, stage two and build all run through backend queues.',
            'user_description' => 'Use backend queues for plan, task plan and build.',
            'default_locale' => 'zh_Hans_CN',
            'plan_locale' => 'zh_Hans_CN',
            'page_types' => [
                Page::TYPE_HOME,
                Page::TYPE_ABOUT,
                Page::TYPE_CONTACT,
            ],
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

        $startPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-plan',
            'POST',
            'postStartPlan',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => [],
            ]
        );
        self::assertTrue((bool)($startPlanPayload['success'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool)($startPlanPayload['start_sse'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));

        $planQueue = $this->executeActiveQueue($publicId, 'plan');
        self::assertSame('done', (string)($planQueue['status'] ?? ''), (string)($planQueue['result'] ?? ''));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        self::assertIsArray($scope['execution_blueprint_draft'] ?? null);
        self::assertNotSame([], $scope['execution_blueprint_draft'] ?? []);

        $confirmPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-confirm-plan',
            'POST',
            'postConfirmPlan',
            [],
            ['public_id' => $publicId]
        );
        self::assertTrue((bool)($confirmPlanPayload['success'] ?? false), \json_encode($confirmPlanPayload, \JSON_UNESCAPED_UNICODE));

        $startTaskPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-task-plan',
            'POST',
            'postStartTaskPlan',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => [],
            ]
        );
        self::assertTrue((bool)($startTaskPlanPayload['success'] ?? false), \json_encode($startTaskPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool)($startTaskPlanPayload['start_sse'] ?? false), \json_encode($startTaskPlanPayload, \JSON_UNESCAPED_UNICODE));

        $taskPlanQueue = $this->executeActiveQueue($publicId, 'task_plan');
        self::assertSame('done', (string)($taskPlanQueue['status'] ?? ''), (string)($taskPlanQueue['result'] ?? ''));
        self::assertNotSame('', \trim((string)($taskPlanQueue['result'] ?? '')));
        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        self::assertIsArray(
            $scope['virtual_theme_plan']['draft'] ?? null,
            \json_encode($scope['virtual_theme_plan'] ?? [], \JSON_UNESCAPED_UNICODE)
        );
        self::assertNotSame(
            '',
            \trim((string)($scope['virtual_theme_plan']['draft_markdown'] ?? '')),
            \json_encode($scope['virtual_theme_plan'] ?? [], \JSON_UNESCAPED_UNICODE)
        );

        $confirmTaskPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-confirm-task-plan',
            'POST',
            'postConfirmTaskPlan',
            [],
            ['public_id' => $publicId]
        );
        self::assertTrue((bool)($confirmTaskPlanPayload['success'] ?? false), \json_encode($confirmTaskPlanPayload, \JSON_UNESCAPED_UNICODE));

        $startBuildPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-build',
            'POST',
            'postStartBuild',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => [],
            ]
        );
        self::assertTrue((bool)($startBuildPayload['success'] ?? false), \json_encode($startBuildPayload, \JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool)($startBuildPayload['start_sse'] ?? false), \json_encode($startBuildPayload, \JSON_UNESCAPED_UNICODE));

        $buildQueue = $this->executeActiveQueue($publicId, 'build');
        self::assertSame('done', (string)($buildQueue['status'] ?? ''), (string)($buildQueue['result'] ?? ''));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        self::assertSame(1, (int)($scope['plan_confirmed'] ?? 0));
        self::assertSame(1, (int)($scope['task_plan_confirmed'] ?? 0));
        self::assertNotSame([], $scope['virtual_pages_by_type'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeActiveQueue(string $publicId, string $operation): array
    {
        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        self::assertSame($operation, (string)($activeOperation['operation'] ?? ''), \json_encode($activeOperation, \JSON_UNESCAPED_UNICODE));

        $queueId = (int)($activeOperation['queue_id'] ?? 0);
        self::assertGreaterThan(0, $queueId, \json_encode($activeOperation, \JSON_UNESCAPED_UNICODE));

        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertGreaterThan(0, (int)($queue['queue_id'] ?? 0), 'Queue record must exist before execution.');

        /** @var QueueRunCommand $runner */
        $runner = ObjectManager::getInstance(QueueRunCommand::class);
        $runner->execute(['id' => $queueId], []);

        $reloadedQueue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($reloadedQueue);

        return $reloadedQueue;
    }
}
