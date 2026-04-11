<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\Page as PageController;
use GuoLaiRen\PageBuilder\Controller\Backend\Preview as PreviewController;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteDraftWebsiteService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\UnitTest\TestCore;

class VirtualThemeRouteIntegrationTest extends TestCore
{
    private AiSiteAgentSessionService $sessionService;
    private AiSiteDraftWebsiteService $draftWebsiteService;
    private AiSiteVirtualThemeService $virtualThemeService;
    private AiSiteScopeCompatibilityService $scopeCompatibilityService;

    private ?AiSiteAgentSession $session = null;
    private int $virtualThemeId = 0;
    private int $websiteId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $this->draftWebsiteService = ObjectManager::getInstance(AiSiteDraftWebsiteService::class);
        $this->virtualThemeService = ObjectManager::getInstance(AiSiteVirtualThemeService::class);
        $this->scopeCompatibilityService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);

        $this->loginAsBackendAdmin();
        $this->bootstrapVirtualThemeSession();
    }

    /**
     * 与其它集成用例共享进程时，预览/响应单例可能污染后续 fetch；独立进程保证路由与视图状态干净。
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testVirtualEditRouteRendersCanonicalVirtualThemeState(): void
    {
        $this->prepareBackendRequest(
            '/pagebuilder/backend/page/virtual-edit',
            [
                'public_id' => $this->session?->getPublicId() ?? '',
                'page_type' => 'home_page',
                'virtual_theme_id' => $this->virtualThemeId,
            ],
            'Backend/Page',
            'getVirtualEdit'
        );

        /** @var PageController $controller */
        $controller = ObjectManager::getInstance(PageController::class);
        $html = (string)$controller->getVirtualEdit();

        self::assertNotSame('', $html);
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('visualConfigWrapper', $html);
        self::assertMatchesRegularExpression('/window\\.visualVirtualThemeId\\s*=\\s*(?:window\\.visualVirtualThemeId\\s*\\|\\|\\s*)?' . $this->virtualThemeId . '/', $html);
        self::assertStringContainsString('virtual_theme_id=' . $this->virtualThemeId, $html);
        self::assertStringContainsString("previewUrl += '&virtual_theme_id='", $html);
        self::assertStringNotContainsString('weline_theme_id=', $html);
    }

    public function testPreviewFullAcceptsCanonicalVirtualThemeIdOnly(): void
    {
        $this->prepareBackendRequest(
            '/pagebuilder/backend/preview/full',
            [
                'public_id' => $this->session?->getPublicId() ?? '',
                'page_type' => 'home_page',
                'virtual_theme_id' => $this->virtualThemeId,
                'visual_editor' => '1',
            ],
            'Backend/Preview',
            'full'
        );

        /** @var PreviewController $controller */
        $controller = ObjectManager::getInstance(PreviewController::class);

        try {
            $controller->full();
            self::fail('Preview::full should terminate the response with HTML output.');
        } catch (ResponseTerminateException $e) {
            $body = $e->getBody();
            self::assertSame(200, $e->getStatusCode());
            self::assertStringContainsString('<!DOCTYPE html>', $body);
            self::assertTrue(
                str_contains($body, 'pb-slot') || str_contains($body, 'pb-ai-block-wrapper'),
                'Preview HTML should expose PageBuilder slot markers (theme slots or ai_html blocks).'
            );
            self::assertStringNotContainsString('weline_theme_id=', $body);
        }
    }

    private function bootstrapVirtualThemeSession(): void
    {
        $scope = [
            'site_title' => 'Route Integration Test',
            'site_tagline' => 'Canonical virtual theme route check',
            'brief_description' => 'Integration route verification for PageBuilder virtual theme.',
            'target_domain' => 'pagebuilder-route-test.local.test',
            'page_types' => ['home_page'],
            'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_EDITING,
        ];

        $this->session = $this->sessionService->createSession(1, $scope);

        $websiteProfile = [
            'site_title' => $scope['site_title'],
            'site_tagline' => $scope['site_tagline'],
            'brief_description' => $scope['brief_description'],
            'target_domain' => $scope['target_domain'],
            'default_locale' => 'en_US',
            'locales' => ['en_US'],
        ];

        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $websiteProfile);
        $this->websiteId = (int)$draftWebsite['website_id'];

        $scope['website_profile'] = $websiteProfile;
        $scope['draft_website_id'] = $this->websiteId;
        $scope['website_id'] = $this->websiteId;
        $scope['selected_website_id'] = $this->websiteId;

        $theme = $this->virtualThemeService->ensureVirtualTheme(
            $scope,
            $websiteProfile,
            ['home_page'],
            ['home_page' => []],
            (int)$this->session->getId()
        );

        $this->virtualThemeId = (int)$theme['virtual_theme_id'];
        $scope['virtual_theme_id'] = $this->virtualThemeId;
        $scope['page_type_layouts'] = $theme['page_type_layouts'];
        $scope['virtual_pages_by_type'] = $this->scopeCompatibilityService->buildVirtualPagesByType(['home_page'], $scope);
        $scope['preview_page_type'] = 'home_page';

        $this->sessionService->replaceScope((int)$this->session->getId(), 1, $scope);
        $this->sessionService->bindWebsite((int)$this->session->getId(), 1, $this->websiteId);
        $this->sessionService->bindVirtualTheme((int)$this->session->getId(), 1, $this->virtualThemeId);

        $this->session = $this->sessionService->loadById((int)$this->session->getId(), 1);
        self::assertNotNull($this->session);
    }

    private function loginAsBackendAdmin(): void
    {
        /** @var BackendUser $admin */
        $admin = ObjectManager::getInstance(BackendUser::class);
        $admin->clearData()->clearQuery()->load(1);
        self::assertGreaterThan(0, (int)$admin->getId(), 'Backend admin user #1 is required for integration tests.');

        $backendSession = SessionFactory::getInstance()->createBackendSession();
        $backendSession->login($admin);
    }

    /**
     * @param array<string, scalar> $query
     */
    private function prepareBackendRequest(string $path, array $query, string $controllerName, string $method): void
    {
        self::initRequest($path);

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $request->setBackend();
        $request->setServer('WELINE_AREA', 'backend');
        $request->setServer('REQUEST_URI', $path);
        $request->setMethod('GET');
        $request->setData('router/module', 'GuoLaiRen_PageBuilder');
        $request->setData('router/module_path', BP . 'app/code/GuoLaiRen/PageBuilder/');
        $request->setData('router/class/controller_name', $controllerName);
        $request->setData('router/class/method', $method);
        $request->setData('router/backend_router', 'pagebuilder');

        foreach ($query as $key => $value) {
            $request->setGet($key, $value);
        }
    }
}
