<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\UnitTest\TestCore;

/**
 * AI 建站工作台集成测共享：后台登录 + 模拟 HTTP 上下文 + JSON/SSE 调用。
 *
 * 具体用例类继承本类；勿在此类中声明 test* 方法，以免被子类继承重复执行。
 */
abstract class AbstractAiSiteWorkbenchIntegrationHarness extends TestCore
{
    protected AiSiteAgentSessionService $sessionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $this->loginAsBackendAdmin();
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
            'postStartTaskPlan' => $controller->postStartTaskPlan(),
            'postConfirmTaskPlan' => $controller->postConfirmTaskPlan(),
            'postStartBuild' => $controller->postStartBuild(),
            'postResumeBuild' => $controller->postResumeBuild(),
            'postStartRefineComponent' => $controller->postStartRefineComponent(),
            'postPublishChecklist' => $controller->postPublishChecklist(),
            'postStartPublish' => $controller->postStartPublish(),
            'postSwitchPreviewPage' => $controller->postSwitchPreviewPage(),
            'getStateJson' => $controller->getStateJson(),
            default => throw new \RuntimeException('Unsupported controller method: ' . $controllerMethod),
        };

        $decoded = \json_decode((string)$result, true);
        self::assertIsArray($decoded, 'Controller JSON response must decode to array: ' . $result);

        return $decoded;
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
        self::assertTrue((bool)($startPlanPayload['success'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool)($startPlanPayload['start_sse'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        /** @var AiSiteScopeCompatibilityService $scopeCompatibilityService */
        $scopeCompatibilityService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        /** @var AiSiteProfileGenerationService $profileService */
        $profileService = ObjectManager::getInstance(AiSiteProfileGenerationService::class);
        /** @var AiSiteExecutionBlueprintService $executionBlueprintService */
        $executionBlueprintService = ObjectManager::getInstance(AiSiteExecutionBlueprintService::class);
        $scope = $scopeCompatibilityService->normalizeScope($session->getScopeArray());
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
                'plan_confirmed' => 0,
                'workspace_status' => 'can_publish',
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

        return [
            'start_plan' => $startPlanPayload,
            'confirm_plan' => $confirmPlanPayload,
        ];
    }

    /**
     * @param array<string, scalar|array> $scopePatch
     * @return array{start_task_plan:array<string,mixed>, confirm_task_plan:array<string,mixed>}
     */
    protected function generateAndConfirmTaskPlan(string $publicId, array $scopePatch): array
    {
        $startTaskPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-task-plan',
            'POST',
            'postStartTaskPlan',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($startTaskPlanPayload['success'] ?? false), \json_encode($startTaskPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertIsArray($startTaskPlanPayload['task_plan'] ?? null);
        self::assertNotSame('', (string)($startTaskPlanPayload['task_plan']['markdown'] ?? ''));

        $confirmTaskPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-confirm-task-plan',
            'POST',
            'postConfirmTaskPlan',
            [],
            [
                'public_id' => $publicId,
            ]
        );
        self::assertTrue((bool)($confirmTaskPlanPayload['success'] ?? false), \json_encode($confirmTaskPlanPayload, \JSON_UNESCAPED_UNICODE));

        return [
            'start_task_plan' => $startTaskPlanPayload,
            'confirm_task_plan' => $confirmTaskPlanPayload,
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
        self::assertIsArray($result);

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
}

class InMemorySseWriter extends \Weline\Framework\Http\Sse\SseWriter
{
    /** @var list<array{event:string,data:mixed}> */
    private array $events = [];

    public function start(): static
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
