<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionArtifact;
use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Backend\Model\BackendUser;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\UnitTest\TestCore;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use Weline\Queue\Model\Queue;

/**
 * AI 建站工作台集成测共享：后台登录 + 模拟 HTTP 上下文 + JSON/SSE 调用。
 *
 * 具体用例类继承本类；勿在此类中声明 test* 方法，以免被子类继承重复执行。
 */
abstract class AbstractAiSiteWorkbenchIntegrationHarness extends TestCore
{
    private const TEST_SCOPE_MARKER_KEY = '_phpunit_pagebuilder_integration';
    private const TEST_SCOPE_MARKER_VALUE = '1';

    protected AiSiteAgentSessionService $sessionService;
    private int $outputBufferBaseline = 0;
    /** @var array<int, array{session_id:int, admin_id:int, public_id:string}> */
    private array $trackedSessions = [];

    protected function setUp(): void
    {
        $this->outputBufferBaseline = \ob_get_level();
        parent::setUp();
        $this->sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $this->cleanupMarkedHarnessArtifacts();
        $this->loginAsBackendAdmin();
        RequestContext::set('pagebuilder.ai.queue.dispatcher', static fn(string $processName, array $meta = []): array => [
            'started' => false,
            'pid' => 0,
            'message' => 'Queue auto-dispatch disabled by PHPUnit; queue:run executes explicitly.',
        ]);
        RequestContext::set(AiSitePageComponentGenerationService::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST, true);
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupTrackedHarnessArtifacts();
            RequestContext::remove('pagebuilder.ai.queue.dispatcher');
            RequestContext::remove(AiSitePageComponentGenerationService::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST);
            parent::tearDown();
        } finally {
            while (\ob_get_level() > $this->outputBufferBaseline) {
                \ob_end_clean();
            }
            while (\ob_get_level() < $this->outputBufferBaseline) {
                \ob_start();
            }
        }
    }

    /**
     * @param array<string, scalar|array> $query
     * @param array<string, scalar|array> $post
     * @return array<string, mixed>
     */
    protected function invokeJsonAction(
        string $path,
        string $httpMethod,
        string $controllerMethod,
        array $query = [],
        array $post = []
    ): array {
        $this->prepareBackendRequest($path, $httpMethod, $controllerMethod, $query, $post);

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $result = match ($controllerMethod) {
            'postCreateSession' => $controller->postCreateSession(),
            'postMergeScope' => $controller->postMergeScope(),
            'postStartPlan' => $controller->postStartPlan(),
            'postConfirmPlan' => $controller->postConfirmPlan(),
            'postStartBuild' => $controller->postStartBuild(),
            'postResumeBuild' => $controller->postResumeBuild(),
            'postStartRefineComponent' => $controller->postStartRefineComponent(),
            'postStartPatchBlock' => $controller->postStartPatchBlock(),
            'postUpdateBlockConfig' => $controller->postUpdateBlockConfig(),
            'postPublishChecklist' => $controller->postPublishChecklist(),
            'postStartPublish' => $controller->postStartPublish(),
            'postSwitchPreviewPage' => $controller->postSwitchPreviewPage(),
            default => throw new \RuntimeException('Unsupported controller method: ' . $controllerMethod),
        };

        $decoded = \json_decode((string)$result, true);
        self::assertIsArray($decoded, 'Controller JSON response must decode to array: ' . $result);
        if (
            $controllerMethod === 'postCreateSession'
            && (bool)($decoded['success'] ?? false)
            && !empty($decoded['public_id'])
        ) {
            $this->trackHarnessSession((string)$decoded['public_id'], 1);
        }

        return $decoded;
    }

    private function trackHarnessSession(string $publicId, int $adminId): void
    {
        $publicId = \trim($publicId);
        if ($publicId === '' || $adminId <= 0) {
            return;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if (!$session instanceof AiSiteAgentSession) {
            return;
        }

        $scope = $this->sessionService->loadScope($session);
        if ((string)($scope[self::TEST_SCOPE_MARKER_KEY] ?? '') !== self::TEST_SCOPE_MARKER_VALUE) {
            $scope[self::TEST_SCOPE_MARKER_KEY] = self::TEST_SCOPE_MARKER_VALUE;
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        }

        $this->trackedSessions[(int)$session->getId()] = [
            'session_id' => (int)$session->getId(),
            'admin_id' => $adminId,
            'public_id' => $session->getPublicId(),
        ];
    }

    private function cleanupMarkedHarnessArtifacts(): void
    {
        $sessionModel = clone ObjectManager::getInstance(AiSiteAgentSession::class);
        $sessionModel->clearData()
            ->reset()
            ->where(
                AiSiteAgentSession::schema_fields_SCOPE_JSON,
                '%"' . self::TEST_SCOPE_MARKER_KEY . '":"' . self::TEST_SCOPE_MARKER_VALUE . '"%',
                'like'
            )
            ->select()
            ->fetch();
        $items = $sessionModel->getItems();
        if (!\is_array($items) || $items === []) {
            return;
        }

        foreach ($items as $item) {
            $row = \is_object($item) && \method_exists($item, 'getData')
                ? $item->getData()
                : (\is_array($item) ? $item : []);
            $sessionId = (int)($row[AiSiteAgentSession::schema_fields_ID] ?? 0);
            $adminId = (int)($row[AiSiteAgentSession::schema_fields_ADMIN_USER_ID] ?? 0);
            $publicId = (string)($row[AiSiteAgentSession::schema_fields_PUBLIC_ID] ?? '');
            $this->cleanupHarnessSessionArtifacts($sessionId, $adminId, $publicId);
        }
    }

    private function cleanupTrackedHarnessArtifacts(): void
    {
        foreach ($this->trackedSessions as $sessionMeta) {
            $this->cleanupHarnessSessionArtifacts(
                (int)($sessionMeta['session_id'] ?? 0),
                (int)($sessionMeta['admin_id'] ?? 0),
                (string)($sessionMeta['public_id'] ?? ''),
            );
        }
        $this->trackedSessions = [];
    }

    private function cleanupHarnessSessionArtifacts(int $sessionId, int $adminId, string $publicId): void
    {
        if ($sessionId <= 0 || $adminId <= 0) {
            return;
        }

        foreach ($this->collectHarnessQueueIds($sessionId, $publicId) as $queueId) {
            try {
                $queue = clone ObjectManager::getInstance(Queue::class);
                $queue->clearData()->load($queueId);
                if ((int)$queue->getId() > 0) {
                    $queue->delete()->fetch();
                }
            } catch (\Throwable) {
            }
        }

        try {
            $artifactModel = clone ObjectManager::getInstance(AiSiteAgentSessionArtifact::class);
            $artifactModel->clearData()
                ->reset()
                ->where(AiSiteAgentSessionArtifact::schema_fields_AGENT_SESSION_ID, $sessionId)
                ->select()
                ->fetch();
            $items = $artifactModel->getItems();
            if (\is_array($items)) {
                foreach ($items as $item) {
                    $row = \is_object($item) && \method_exists($item, 'getData')
                        ? $item->getData()
                        : (\is_array($item) ? $item : []);
                    $artifactId = (int)($row[AiSiteAgentSessionArtifact::schema_fields_ID] ?? 0);
                    if ($artifactId <= 0) {
                        continue;
                    }
                    $artifact = clone ObjectManager::getInstance(AiSiteAgentSessionArtifact::class);
                    $artifact->clearData()->clearQuery()->load($artifactId);
                    if ((int)$artifact->getId() > 0) {
                        $artifact->delete()->fetch();
                    }
                }
            }
        } catch (\Throwable) {
        }

        try {
            $this->sessionService->deleteSession($sessionId, $adminId);
        } catch (\Throwable) {
        }
    }

    /**
     * @return list<int>
     */
    private function collectHarnessQueueIds(int $sessionId, string $publicId): array
    {
        $queueIds = $this->collectHarnessQueueIdsByLike(
            Queue::schema_fields_BIZ_KEY,
            'glr_aisite:session:' . $sessionId . ':%'
        );
        if ($publicId !== '') {
            $queueIds = \array_merge(
                $queueIds,
                $this->collectHarnessQueueIdsByLike(
                    Queue::schema_fields_content,
                    '%"public_id":"' . $publicId . '"%'
                )
            );
        }

        return \array_values(\array_unique(\array_filter(\array_map('intval', $queueIds), static fn(int $id): bool => $id > 0)));
    }

    /**
     * @return list<int>
     */
    private function collectHarnessQueueIdsByLike(string $field, string $pattern): array
    {
        $queueModel = clone ObjectManager::getInstance(Queue::class);
        $queueModel->clearData()
            ->reset()
            ->where(Queue::schema_fields_module, 'GuoLaiRen_PageBuilder')
            ->where($field, $pattern, 'like')
            ->select()
            ->fetch();
        $items = $queueModel->getItems();
        if (!\is_array($items) || $items === []) {
            return [];
        }

        $queueIds = [];
        foreach ($items as $item) {
            $row = \is_object($item) && \method_exists($item, 'getData')
                ? $item->getData()
                : (\is_array($item) ? $item : []);
            $queueId = (int)($row[Queue::schema_fields_ID] ?? 0);
            if ($queueId > 0) {
                $queueIds[] = $queueId;
            }
        }

        return $queueIds;
    }

    /**
     * @param array<string, scalar|array> $scopePatch
     * @return array{start_plan:array<string,mixed>, confirm_plan:array<string,mixed>}
     */
    protected function generateAndConfirmPlan(string $publicId, array $scopePatch): array
    {
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
        $startPlanSucceeded = (bool)($startPlanPayload['success'] ?? false);
        if ($startPlanSucceeded) {
            self::assertTrue((bool)($startPlanPayload['start_sse'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));
        } else {
            $readinessFailureText = \strtolower((string)($startPlanPayload['code'] ?? '') . ' ' . (string)($startPlanPayload['message'] ?? ''));
            self::assertTrue(
                \str_contains($readinessFailureText, 'ai provider readiness')
                    || \str_contains($readinessFailureText, 'ai_provider')
                    || \str_contains($readinessFailureText, 'api.deepseek.com'),
                \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE)
            );
        }

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        /** @var AiSiteScopeCompatibilityService $scopeCompatibilityService */
        $scopeCompatibilityService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        /** @var AiSiteProfileGenerationService $profileService */
        $profileService = ObjectManager::getInstance(AiSiteProfileGenerationService::class);
        /** @var AiSiteExecutionBlueprintService $executionBlueprintService */
        $executionBlueprintService = ObjectManager::getInstance(AiSiteExecutionBlueprintService::class);
        $scope = $scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
        $websiteProfile = $profileService->generate($scope, false);
        $artifacts = $executionBlueprintService->buildPlanArtifacts($scope, \is_array($websiteProfile) ? $websiteProfile : []);
        $this->sessionService->mergeScope($session->getId(), 1, \array_replace(
            \is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [],
            [
                'website_profile' => \is_array($websiteProfile) ? $websiteProfile : [],
                'execution_blueprint_draft' => \is_array($artifacts['execution_blueprint'] ?? null) ? $artifacts['execution_blueprint'] : [],
                'plan_json' => \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [],
                'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
                'plan_structured' => \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [],
                'plan_locale' => (string)($scope['plan_locale'] ?? $scope['default_locale'] ?? $scope['default_language'] ?? ''),
                'plan_ai_generated' => 0,
                'plan_ai_fallback' => 1,
                'plan_generated_at' => \date('Y-m-d H:i:s'),
                'plan_generated_locale' => (string)($scope['plan_locale'] ?? $scope['default_locale'] ?? $scope['default_language'] ?? ''),
                'plan_generated_page_types' => \is_array($scope['page_types'] ?? null) ? \array_values(\array_map('strval', $scope['page_types'])) : [],
                'plan_generated_source_signature' => $executionBlueprintService->buildSourceSignature($scope),
                'plan_confirmed' => 0,
                'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING,
                'active_operation' => [
                    'operation' => 'plan',
                    'status' => 'done',
                    'message' => '阶段一方案已准备完成',
                    'updated_at' => \date('Y-m-d H:i:s'),
                ],
            ]
        ));

        $confirmPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-confirm-plan',
            'POST',
            'postConfirmPlan',
            [],
            [
                'public_id' => $publicId,
            ]
        );
        self::assertTrue((bool)($confirmPlanPayload['success'] ?? false), \json_encode($confirmPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString(
            'public_id=' . $publicId,
            (string)($confirmPlanPayload['data']['workspace_url'] ?? ''),
            'post-confirm-plan should return the workspace URL for the confirmed BuildPlan workspace'
        );

        return [
            'start_plan' => $startPlanPayload,
            'confirm_plan' => $confirmPlanPayload,
        ];
    }

    protected function invokePrivateOperation(string $method, InMemorySseWriter $writer, string $publicId): array
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $reflection = new \ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, $writer, $session, 1);
        if (!\is_array($result)) {
            $state = $this->fetchWorkspaceState($publicId);
            if ($method === 'runBuildOperation') {
                return [
                    'message' => (string)($state['active_operation']['message'] ?? $state['workspace_status'] ?? ''),
                    'draft_website_id' => (int)($state['website_id'] ?? 0),
                    'virtual_theme_id' => (int)($state['virtual_theme_id'] ?? 0),
                    'page_types' => \is_array($state['page_types'] ?? null) ? $state['page_types'] : [],
                ];
            }
            if ($method === 'runPublishOperation') {
                return [
                    'message' => (string)($state['active_operation']['message'] ?? $state['workspace_status'] ?? ''),
                    'published' => [
                        'pagebuilder_pages_by_type' => \is_array($state['pagebuilder_pages_by_type'] ?? null) ? $state['pagebuilder_pages_by_type'] : [],
                    ],
                ];
            }
            self::assertIsArray($result);
        }

        return $result;
    }

    /**
     * @param array<string, scalar|array> $query
     * @param array<string, scalar|array> $post
     */
    protected function prepareBackendRequest(
        string $path,
        string $httpMethod,
        string $controllerMethod,
        array $query = [],
        array $post = [],
        string $controllerName = 'Backend/AiSiteAgent'
    ): void {
        self::initRequest($path);

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        Request::clearStaticUrlPathCache();
        $request->resetParameterBag();
        $request->clearDataObject();
        $request->unsetData('body_params');
        $request->unsetData('array_body_params');
        $_GET = [];
        $_POST = [];
        if (\function_exists('w_env_set')) {
            \w_env_set('request.body', '');
            \w_env_set('server.content_type', '');
        }
        $request->setBackend();
        $request->setServer('WELINE_AREA', 'backend');
        $request->setServer('REQUEST_URI', $path);
        $request->setMethod($httpMethod);
        $request->setData('router/module', 'GuoLaiRen_PageBuilder');
        $request->setData('router/module_path', BP . 'app/code/GuoLaiRen/PageBuilder/');
        $request->setData('router/class/controller_name', $controllerName);
        $request->setData('router/class/method', $controllerMethod);
        $request->setData('router/backend_router', 'pagebuilder');

        foreach ($query as $key => $value) {
            $request->setGet((string)$key, $value);
        }
        foreach ($post as $key => $value) {
            $request->setPost((string)$key, $value);
        }
    }

    /**
     * @param array<string, scalar|array> $query
     * @param array<string, scalar|array> $post
     */
    protected function prepareFrontendRequest(
        string $path,
        string $httpMethod,
        string $controllerMethod,
        array $query = [],
        array $post = [],
        string $controllerName = 'Frontend/Page'
    ): void {
        self::initRequest($path);

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        Request::clearStaticUrlPathCache();
        $request->resetParameterBag();
        $request->clearDataObject();
        $request->unsetData('body_params');
        $request->unsetData('array_body_params');
        $_GET = [];
        $_POST = [];
        if (\function_exists('w_env_set')) {
            \w_env_set('request.body', '');
            \w_env_set('server.content_type', '');
        }
        $request->unsetData('backend');
        $request->unsetData('api_backend');
        $request->unsetData('api_frontend');
        $request->setServer('WELINE_AREA', 'frontend');
        $request->setServer('REQUEST_URI', $path);
        $request->setMethod($httpMethod);
        $request->setData('router/module', 'GuoLaiRen_PageBuilder');
        $request->setData('router/module_path', BP . 'app/code/GuoLaiRen/PageBuilder/');
        $request->setData('router/class/controller_name', $controllerName);
        $request->setData('router/class/method', $controllerMethod);

        foreach ($query as $key => $value) {
            $request->setGet((string)$key, $value);
        }
        foreach ($post as $key => $value) {
            $request->setPost((string)$key, $value);
        }
    }

    protected function loginAsBackendAdmin(): void
    {
        /** @var BackendUser $admin */
        $admin = ObjectManager::getInstance(BackendUser::class);
        $admin->clearData()->clearQuery()->load(1);
        self::assertGreaterThan(0, (int)$admin->getId(), 'Backend admin user #1 is required for workbench integration tests.');

        $backendSession = SessionFactory::getInstance()->createBackendSession();
        $backendSession->login($admin);
    }

    /**
     * Fetch the current workspace state from the session scope.
     * Replaces the deleted getStateJson controller endpoint.
     */
    protected function fetchWorkspaceState(string $publicId): array
    {
        $adminId = 1;
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return [];
        }
        $scope = $this->sessionService->loadScope($session);
        if (!\is_array($scope) || $scope === []) {
            $scope = $this->sessionService->loadScopeForStage($session, $session->getStage() ?: AiSiteAgentSession::STAGE_VISUAL_EDIT);
        }
        return [
            'publish_status' => (string)($scope['publish_status'] ?? $session->getPublishStatus() ?? ''),
            'workspace_status' => (string)($scope['workspace_status'] ?? $session->getStage() ?? ''),
            'preview_page_id' => (int)($scope['preview_page_id'] ?? 0),
            'pagebuilder_pages_by_type' => \is_array($scope['pagebuilder_pages_by_type'] ?? null) ? $scope['pagebuilder_pages_by_type'] : [],
            'page_type_layouts' => \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [],
            'virtual_pages_by_type' => \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [],
            'visual_preview_url' => (string)($scope['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($scope['visual_edit_url'] ?? ''),
            'preview_page_type' => (string)($scope['preview_page_type'] ?? ''),
            'website_id' => (int)($scope['website_id'] ?? $scope['draft_website_id'] ?? 0),
            'virtual_theme_id' => (int)($scope['virtual_theme_id'] ?? 0),
            'page_types' => \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [],
            'active_operation' => \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
        ];
    }
}

class InMemorySseWriter extends \Weline\Framework\Http\Sse\SseWriter
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

    public function countEvents(string $eventName): int
    {
        return \count($this->eventsByName($eventName));
    }
}
