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

final class AiSiteAgentOperationObserverIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
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

        $writer = new DuplicateObserverHeartbeatWriter(function () use ($session, $executionToken): void {
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
