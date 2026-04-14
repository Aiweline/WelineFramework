<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
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
        self::assertIsArray($startPlanPayload['plan'] ?? null);
        self::assertNotSame('', (string)($startPlanPayload['plan']['markdown'] ?? ''));

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
