<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Controller\Backend\Page as PageController;
use GuoLaiRen\PageBuilder\Controller\Backend\Preview as PreviewController;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\UnitTest\TestCore;
use Weline\Websites\Model\Website;

/**
 * @group integration
 * @group pagebuilder_workbench
 */
class AiSiteWorkbenchSuccessIntegrationTest extends TestCore
{
    private AiSiteAgentSessionService $sessionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $this->loginAsBackendAdmin();
    }

    public function testWorkbenchCanCreateBuildAndPublishWebsiteSuccessfully(): void
    {
        $suffix = \date('YmdHis') . '-' . \substr(\bin2hex(\random_bytes(4)), 0, 8);
        $siteTitle = 'AI Workbench Success ' . $suffix;
        $targetDomain = 'ai-workbench-success-' . \strtolower(\substr(\md5($suffix), 0, 10)) . '.local.test';
        $pageTypes = [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT];

        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );

        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));
        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $scopePatch = [
            'site_title' => $siteTitle,
            'site_tagline' => 'Stable integration success flow',
            'target_domain' => $targetDomain,
            'brief_description' => 'Build a homepage, about page, and contact page through the AI site workbench integration flow.',
            'user_description' => 'Build a homepage, about page, and contact page through the AI site workbench integration flow.',
            'page_types' => $pageTypes,
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
        $buildExecutionToken = (string)($startBuildPayload['execution_token'] ?? '');
        self::assertNotSame('', $buildExecutionToken);

        $buildWriter = new InMemorySseWriter();
        $buildResult = $this->invokePrivateOperation('runBuildOperation', $buildWriter, $publicId);

        self::assertNotSame('', (string)($buildResult['message'] ?? ''));
        self::assertGreaterThan(0, (int)($buildResult['draft_website_id'] ?? 0));
        self::assertGreaterThan(0, (int)($buildResult['virtual_theme_id'] ?? 0));
        self::assertGreaterThanOrEqual(\count($pageTypes), $buildWriter->countEvents('progress'));
        self::assertCount(\count($pageTypes), $buildWriter->eventsByName('page_generated'));

        $buildStatePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/get-state-json',
            'GET',
            'getStateJson',
            ['public_id' => $publicId]
        );

        self::assertTrue((bool)($buildStatePayload['success'] ?? false), \json_encode($buildStatePayload, \JSON_UNESCAPED_UNICODE));
        $buildState = \is_array($buildStatePayload['data'] ?? null) ? $buildStatePayload['data'] : [];

        $draftWebsiteId = (int)($buildState['draft_website_id'] ?? 0);
        $virtualThemeId = (int)($buildState['virtual_theme_id'] ?? 0);
        $previewPageType = (string)($buildState['preview_page_type'] ?? '');
        $visualPreviewUrl = (string)($buildState['visual_preview_url'] ?? '');
        $visualEditUrl = (string)($buildState['visual_edit_url'] ?? '');

        self::assertGreaterThan(0, $draftWebsiteId);
        self::assertGreaterThan(0, $virtualThemeId);
        self::assertContains($previewPageType, $pageTypes);
        self::assertSame(AiSiteAgentSession::PUBLISH_STATUS_DRAFT, (string)($buildState['publish_status'] ?? ''));
        self::assertSame('can_publish', (string)($buildState['workspace_status'] ?? ''));
        self::assertCount(\count($pageTypes), (array)($buildState['virtual_pages_by_type'] ?? []));
        self::assertStringContainsString('/pagebuilder/backend/preview/full', $visualPreviewUrl);
        self::assertStringContainsString('virtual_theme_id=' . $virtualThemeId, $visualPreviewUrl);
        self::assertStringNotContainsString('weline_theme_id=', $visualPreviewUrl);
        self::assertStringContainsString('/pagebuilder/backend/page/virtual-edit', $visualEditUrl);
        self::assertStringContainsString('virtual_theme_id=' . $virtualThemeId, $visualEditUrl);
        self::assertStringNotContainsString('weline_theme_id=', $visualEditUrl);

        $checkPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-publish-checklist',
            'POST',
            'postPublishChecklist',
            [],
            ['public_id' => $publicId]
        );

        self::assertTrue((bool)($checkPayload['success'] ?? false), \json_encode($checkPayload, \JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool)($checkPayload['data']['passed'] ?? false), \json_encode($checkPayload['data'] ?? [], \JSON_UNESCAPED_UNICODE));

        $startPublishPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-publish',
            'POST',
            'postStartPublish',
            [],
            ['public_id' => $publicId]
        );

        self::assertTrue((bool)($startPublishPayload['success'] ?? false), \json_encode($startPublishPayload, \JSON_UNESCAPED_UNICODE));
        $publishExecutionToken = (string)($startPublishPayload['execution_token'] ?? '');
        self::assertNotSame('', $publishExecutionToken);

        $publishWriter = new InMemorySseWriter();
        $publishResult = $this->invokePrivateOperation('runPublishOperation', $publishWriter, $publicId);

        self::assertNotSame('', (string)($publishResult['message'] ?? ''));
        self::assertIsArray($publishResult['published'] ?? null);
        self::assertGreaterThanOrEqual(2, $publishWriter->countEvents('progress'));

        $publishStatePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/get-state-json',
            'GET',
            'getStateJson',
            ['public_id' => $publicId]
        );

        self::assertTrue((bool)($publishStatePayload['success'] ?? false), \json_encode($publishStatePayload, \JSON_UNESCAPED_UNICODE));
        $publishState = \is_array($publishStatePayload['data'] ?? null) ? $publishStatePayload['data'] : [];

        self::assertSame(AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED, (string)($publishState['publish_status'] ?? ''));
        self::assertSame('published', (string)($publishState['workspace_status'] ?? ''));
        self::assertGreaterThan(0, (int)($publishState['preview_page_id'] ?? 0));

        $publishedPages = (array)($publishState['pagebuilder_pages_by_type'] ?? []);
        self::assertCount(\count($pageTypes), $publishedPages);
        foreach ($pageTypes as $pageType) {
            self::assertArrayHasKey($pageType, $publishedPages);
            self::assertGreaterThan(0, (int)($publishedPages[$pageType]['page_id'] ?? 0));
        }

        $publishedPreviewPageId = (int)($publishState['preview_page_id'] ?? 0);
        self::assertGreaterThan(0, $publishedPreviewPageId);

        $this->prepareBackendRequest(
            '/pagebuilder/backend/preview/full',
            'GET',
            'full',
            [
                'page_id' => $publishedPreviewPageId,
                'visual_editor' => '1',
                'locale' => 'en_US',
            ],
            [],
            'Backend/Preview'
        );

        /** @var PreviewController $publishedPreviewController */
        $publishedPreviewController = ObjectManager::getInstance(PreviewController::class);
        try {
            $publishedPreviewController->full();
            self::fail('Published Preview::full should terminate with rendered HTML.');
        } catch (ResponseTerminateException $exception) {
            $html = $exception->getBody();
            self::assertSame(200, $exception->getStatusCode());
            self::assertStringContainsString('<!DOCTYPE html>', $html);
            self::assertStringNotContainsString('Component not found:', $html);
        }

        $publishedVisualEditUrl = (string)($publishState['visual_edit_url'] ?? '');
        self::assertStringContainsString('/pagebuilder/backend/page/edit', $publishedVisualEditUrl);
        self::assertStringContainsString('virtual_theme_id=' . $virtualThemeId, $publishedVisualEditUrl);
        self::assertStringNotContainsString('weline_theme_id=', $publishedVisualEditUrl);

        /** @var Website $website */
        $website = ObjectManager::getInstance(Website::class);
        $website->clearData()->clearQuery()->load($draftWebsiteId);
        self::assertGreaterThan(0, $website->getWebsiteId());
        self::assertSame($siteTitle, $website->getName());
        self::assertSame('page_builder', (string)$website->getScope());
        self::assertStringContainsString($targetDomain, $website->getUrl());
    }

    public function testWorkbenchBuildCanOpenVirtualPreviewAndEditorRoutesDirectly(): void
    {
        $buildFlow = $this->createAndBuildWorkbenchSession();
        $publicId = (string)$buildFlow['public_id'];
        $buildState = (array)$buildFlow['build_state'];
        $virtualThemeId = (int)($buildState['virtual_theme_id'] ?? 0);
        $previewPageType = (string)($buildState['preview_page_type'] ?? '');

        self::assertGreaterThan(0, $virtualThemeId);
        self::assertNotSame('', $previewPageType);

        $this->prepareBackendRequest(
            '/pagebuilder/backend/preview/full',
            'GET',
            'full',
            [
                'public_id' => $publicId,
                'page_type' => $previewPageType,
                'virtual_theme_id' => $virtualThemeId,
                'visual_editor' => '1',
            ],
            [],
            'Backend/Preview'
        );

        /** @var PreviewController $previewController */
        $previewController = ObjectManager::getInstance(PreviewController::class);
        try {
            $previewController->full();
            self::fail('Preview::full should terminate with rendered HTML.');
        } catch (ResponseTerminateException $exception) {
            $html = $exception->getBody();
            self::assertSame(200, $exception->getStatusCode());
            self::assertStringContainsString('<!DOCTYPE html>', $html);
            self::assertStringContainsString('pb-slot', $html);
            self::assertStringNotContainsString('weline_theme_id=', $html);
        }

        $this->prepareBackendRequest(
            '/pagebuilder/backend/page/virtual-edit',
            'GET',
            'getVirtualEdit',
            [
                'public_id' => $publicId,
                'page_type' => $previewPageType,
                'virtual_theme_id' => $virtualThemeId,
            ],
            [],
            'Backend/Page'
        );

        /** @var PageController $pageController */
        $pageController = ObjectManager::getInstance(PageController::class);
        $editorHtml = (string)$pageController->getVirtualEdit();

        self::assertNotSame('', $editorHtml);
        self::assertNotFalse(\strpos($editorHtml, '<!DOCTYPE html>'));
        self::assertGreaterThan(1000, \strlen($editorHtml));
        self::assertStringNotContainsString('weline_theme_id=', $editorHtml);
    }

    public function testStartPublishShowsFriendlyMessageWhenDomainNotReady(): void
    {
        $buildFlow = $this->createAndBuildWorkbenchSession();
        $publicId = (string)$buildFlow['public_id'];

        $markDomainPendingPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => ['site_ready' => 0],
            ]
        );
        self::assertTrue((bool)($markDomainPendingPayload['success'] ?? false), \json_encode($markDomainPendingPayload, \JSON_UNESCAPED_UNICODE));

        $checkPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-publish-checklist',
            'POST',
            'postPublishChecklist',
            [],
            ['public_id' => $publicId]
        );
        self::assertTrue((bool)($checkPayload['success'] ?? false), \json_encode($checkPayload, \JSON_UNESCAPED_UNICODE));
        self::assertFalse((bool)($checkPayload['data']['passed'] ?? true), \json_encode($checkPayload['data'] ?? [], \JSON_UNESCAPED_UNICODE));

        $siteReadyItems = \array_values(\array_filter(
            (array)($checkPayload['data']['items'] ?? []),
            static fn ($item): bool => \is_array($item) && (string)($item['key'] ?? '') === 'site_ready'
        ));
        self::assertNotEmpty($siteReadyItems);
        self::assertFalse((bool)($siteReadyItems[0]['ok'] ?? true));

        $startPublishPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-publish',
            'POST',
            'postStartPublish',
            [],
            ['public_id' => $publicId]
        );
        self::assertFalse((bool)($startPublishPayload['success'] ?? true), \json_encode($startPublishPayload, \JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('域名尚未就绪', (string)($startPublishPayload['message'] ?? ''));
    }

    /**
     * @param array<string, scalar|array> $query
     * @param array<string, scalar|array> $post
     * @return array<string, mixed>
     */
    private function invokeJsonAction(
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
            'postStartBuild' => $controller->postStartBuild(),
            'postPublishChecklist' => $controller->postPublishChecklist(),
            'postStartPublish' => $controller->postStartPublish(),
            'getStateJson' => $controller->getStateJson(),
            default => throw new \RuntimeException('Unsupported controller method: ' . $controllerMethod),
        };

        $decoded = \json_decode((string)$result, true);
        self::assertIsArray($decoded, 'Controller JSON response must decode to array: ' . $result);

        return $decoded;
    }

    private function invokePrivateOperation(string $method, InMemorySseWriter $writer, string $publicId): array
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
    private function prepareBackendRequest(
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
     * @return array{
     *   public_id:string,
     *   site_title:string,
     *   target_domain:string,
     *   page_types:list<string>,
     *   build_state:array<string, mixed>
     * }
     */
    private function createAndBuildWorkbenchSession(): array
    {
        $suffix = \date('YmdHis') . '-' . \substr(\bin2hex(\random_bytes(4)), 0, 8);
        $siteTitle = 'AI Workbench Success ' . $suffix;
        $targetDomain = 'ai-workbench-success-' . \strtolower(\substr(\md5($suffix), 0, 10)) . '.local.test';
        $pageTypes = [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT];

        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );

        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));
        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $scopePatch = [
            'site_title' => $siteTitle,
            'site_tagline' => 'Stable integration success flow',
            'target_domain' => $targetDomain,
            'brief_description' => 'Build a homepage, about page, and contact page through the AI site workbench integration flow.',
            'user_description' => 'Build a homepage, about page, and contact page through the AI site workbench integration flow.',
            'page_types' => $pageTypes,
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
        self::assertNotSame('', (string)($startBuildPayload['execution_token'] ?? ''));

        $buildWriter = new InMemorySseWriter();
        $buildResult = $this->invokePrivateOperation('runBuildOperation', $buildWriter, $publicId);

        self::assertNotSame('', (string)($buildResult['message'] ?? ''));
        self::assertGreaterThan(0, (int)($buildResult['draft_website_id'] ?? 0));
        self::assertGreaterThan(0, (int)($buildResult['virtual_theme_id'] ?? 0));
        self::assertGreaterThanOrEqual(\count($pageTypes), $buildWriter->countEvents('progress'));
        self::assertCount(\count($pageTypes), $buildWriter->eventsByName('page_generated'));

        $buildStatePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/get-state-json',
            'GET',
            'getStateJson',
            ['public_id' => $publicId]
        );

        self::assertTrue((bool)($buildStatePayload['success'] ?? false), \json_encode($buildStatePayload, \JSON_UNESCAPED_UNICODE));
        $buildState = \is_array($buildStatePayload['data'] ?? null) ? $buildStatePayload['data'] : [];

        return [
            'public_id' => $publicId,
            'site_title' => $siteTitle,
            'target_domain' => $targetDomain,
            'page_types' => $pageTypes,
            'build_state' => $buildState,
        ];
    }

    private function loginAsBackendAdmin(): void
    {
        /** @var BackendUser $admin */
        $admin = ObjectManager::getInstance(BackendUser::class);
        $admin->clearData()->clearQuery()->load(1);
        self::assertGreaterThan(0, (int)$admin->getId(), 'Backend admin user #1 is required for workbench integration tests.');

        $backendSession = SessionFactory::getInstance()->createBackendSession();
        $backendSession->login($admin);
    }
}

final class InMemorySseWriter extends \Weline\Framework\Http\Sse\SseWriter
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
