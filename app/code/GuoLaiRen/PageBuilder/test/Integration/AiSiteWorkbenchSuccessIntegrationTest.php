<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\Page as PageController;
use GuoLaiRen\PageBuilder\Controller\Backend\Preview as PreviewController;
use GuoLaiRen\PageBuilder\Controller\Frontend\Page as FrontendPageController;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

/**
 * AI 建站工作台端到端验收（集成测试，需 DB + 后台登录上下文）。
 *
 * 阶段对应 guided UI：
 * - 阶段 1「说说你的网站」：post-create-session + post-merge-scope（scope_patch 模拟用户填写的站点信息）
 * - 阶段 2「看看效果」：runBuildOperation（内存 SSE）模拟生成虚拟主题与页面；buildResult 校验预览/编辑 URL
 * - 阶段 3「准备上线」：post-publish-checklist + runPublishOperation；校验正式页面与后台预览 HTML
 *
 * 前端 workspace.phtml 应使用本控制器 assign 的 URL（与 buildResult 返回的 visual_* 同源）：
 * merge_scope_url、operation_sse_url、start_publish_url 等，勿写死路径。
 *
 * @group integration
 * @group pagebuilder_workbench
 */
class AiSiteWorkbenchSuccessIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
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

        $planFlow = $this->generateAndConfirmPlan($publicId, $scopePatch);
        self::assertSame(
            1,
            (int)($planFlow['confirm_plan']['data']['plan_confirmed'] ?? 0),
            'Confirmed plan state should be visible before build starts.'
        );
        $taskPlanFlow = $this->generateAndConfirmTaskPlan($publicId, $scopePatch);
        self::assertSame(
            1,
            (int)($taskPlanFlow['confirm_task_plan']['data']['task_plan_confirmed'] ?? 0),
            'Confirmed task-plan state should be visible before build starts.'
        );

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
        self::assertSame(1, $buildWriter->countEvents('environment_ready'));
        $generatedPageTypes = [];
        foreach ($buildWriter->eventsByName('page_generated') as $generatedEvent) {
            $payload = \is_array($generatedEvent['data'] ?? null) ? $generatedEvent['data'] : [];
            self::assertGreaterThan(0, (int)($payload['page_id'] ?? 0));
            $generatedPageTypes[] = (string)($payload['page_type'] ?? '');
        }
        $generatedPageTypes = \array_values(\array_unique(\array_filter($generatedPageTypes)));
        foreach ($pageTypes as $pageType) {
            self::assertContains($pageType, $generatedPageTypes, 'Each requested page type should emit at least one page_generated event.');
        }

        self::assertCount(1, $buildWriter->eventsByName('environment_ready'), 'Should emit exactly one environment_ready event.');
        $envReadyEvents = $buildWriter->eventsByName('environment_ready');
        $envReadyData = \is_array($envReadyEvents[0]['data'] ?? null) ? $envReadyEvents[0]['data'] : [];
        $buildState = \is_array($envReadyData['state'] ?? null) ? $envReadyData['state'] : [];

        $draftWebsiteId = (int)($buildResult['draft_website_id'] ?? 0);
        $virtualThemeId = (int)($buildResult['virtual_theme_id'] ?? 0);
        $previewPageType = (string)($envReadyData['page_type'] ?? '');
        $visualPreviewUrl = (string)($buildState['visual_preview_url'] ?? '');
        $visualEditUrl = (string)($buildState['visual_edit_url'] ?? '');

        self::assertGreaterThan(0, $draftWebsiteId);
        self::assertGreaterThan(0, $virtualThemeId);
        self::assertContains($previewPageType, $pageTypes);
        self::assertSame(AiSiteAgentSession::PUBLISH_STATUS_DRAFT, (string)($buildState['publish_status'] ?? ''));
        self::assertSame('can_publish', (string)($buildState['workspace_status'] ?? ''));
        self::assertCount(\count($pageTypes), (array)($buildState['pagebuilder_pages_by_type'] ?? []));
        self::assertGreaterThan(0, (int)($envReadyData['page_id'] ?? 0));
        self::assertCount(\count($pageTypes), (array)($buildState['virtual_pages_by_type'] ?? []));
        $virtualPages = (array)($buildState['virtual_pages_by_type'] ?? []);
        self::assertNotSame(
            (string)($virtualPages[Page::TYPE_HOME]['ai_description'] ?? ''),
            (string)($virtualPages[Page::TYPE_ABOUT]['ai_description'] ?? ''),
            'Different page types should no longer share the same AI description.'
        );
        self::assertGreaterThan(
            1,
            \count((array)($buildState['page_type_layouts'][Page::TYPE_HOME]['content'] ?? [])),
            'Prompt-driven build should create multiple content sections per page.'
        );
        self::assertStringContainsString(
            '/pagebuilder/backend/ai-site-agent/workspace-preview',
            $visualPreviewUrl,
            'Draft workbench in virtual-theme visual stage should stay on workspace-preview and must not drift to preview/full.'
        );
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

        $publishState = $this->fetchWorkspaceState($publicId);

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

        // 模拟访客站「本地已建站」：前端 Live 模式按 page_id 渲染首页（与线上根域名访问同一套 PageRenderService）
        $homePageId = (int)($publishedPages[Page::TYPE_HOME]['page_id'] ?? 0);
        self::assertGreaterThan(0, $homePageId);
        /** @var WebsiteDomain $websiteDomain */
        $websiteDomain = ObjectManager::getInstance(WebsiteDomain::class);
        $websiteDomain->clearData()->clearQuery()->loadByDomain($targetDomain);
        self::assertGreaterThan(0, $websiteDomain->getDomainId(), 'Published website should persist a website_domain binding.');
        self::assertSame($draftWebsiteId, $websiteDomain->getWebsiteId());
        WebsiteData::setWebsite($website);
        $this->prepareFrontendRequest(
            '/pagebuilder/frontend/page/view',
            'GET',
            'view',
            ['page_id' => $homePageId],
            [],
            'Frontend/Page'
        );
        /** @var FrontendPageController $frontendPage */
        $frontendPage = ObjectManager::getInstance(FrontendPageController::class);
        \ob_start();
        try {
            $frontendPage->view();
        } finally {
            $liveHtml = (string)\ob_get_clean();
        }
        self::assertNotSame('', \trim($liveHtml), 'Published home should render non-empty HTML on frontend live view.');
        self::assertStringContainsString('<!DOCTYPE', $liveHtml);
        self::assertStringNotContainsString('Component not found:', $liveHtml);
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
            self::assertStringContainsString('component-actions', $html);
            self::assertStringContainsString('data-pb-action="refine"', $html);
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

    public function testStartRefineComponentPersistsSectionRefinementAndQueuesRegenerate(): void
    {
        $buildFlow = $this->createAndBuildWorkbenchSession();
        $publicId = (string)$buildFlow['public_id'];
        $buildState = (array)$buildFlow['build_state'];
        $pageType = (string)($buildState['preview_page_type'] ?? Page::TYPE_HOME);
        $contentLayout = (array)($buildState['page_type_layouts'][$pageType]['content'] ?? []);
        $componentCode = (string)($contentLayout[0]['code'] ?? '');

        self::assertNotSame('', $componentCode);

        $payload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-refine-component',
            'POST',
            'postStartRefineComponent',
            [],
            [
                'public_id' => $publicId,
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'instruction' => '把这个区块改成更强调印度 APK 拉新和下载转化的表达',
            ]
        );

        self::assertTrue((bool)($payload['success'] ?? false), \json_encode($payload, \JSON_UNESCAPED_UNICODE));
        self::assertSame('regenerate_page', (string)($payload['operation'] ?? ''));
        self::assertNotSame('', (string)($payload['execution_token'] ?? ''));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        $virtualPages = (array)($scope['virtual_pages_by_type'] ?? []);
        $sectionRefinements = (array)($virtualPages[$pageType]['section_refinements'] ?? []);

        self::assertSame(
            '把这个区块改成更强调印度 APK 拉新和下载转化的表达',
            (string)($sectionRefinements[$componentCode] ?? '')
        );
    }

    public function testUpdateBlockConfigOnlyMutatesCurrentPageBlockStructuredData(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));
        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $homeHeader = [
            'block_id' => 'home-site-header',
            'type' => 'site_header',
            'html' => '<header>Home header before</header>',
            'config' => [
                'site_title' => 'Home header before',
                'site_tagline' => 'Home tagline before',
                'current_page_label' => 'Home',
                'nav_items' => [],
            ],
            'field_schema' => [],
        ];
        $homeHero = [
            'block_id' => 'home-hero',
            'type' => 'hero',
            'html' => '<section>Home hero before</section>',
            'config' => [
                'eyebrow' => 'Before',
                'headline' => 'Home hero before',
                'description' => 'Do not change this block.',
                'chips' => ['stable'],
                'primary_cta' => 'Start',
                'secondary_note' => '',
            ],
            'field_schema' => [],
        ];
        $aboutHeader = [
            'block_id' => 'about-site-header',
            'type' => 'site_header',
            'html' => '<header>About header before</header>',
            'config' => [
                'site_title' => 'About header before',
                'site_tagline' => 'About tagline before',
                'current_page_label' => 'About',
                'nav_items' => [],
            ],
            'field_schema' => [],
        ];

        $scopePatch = [
            'site_title' => 'Block API scope test',
            'site_tagline' => 'Current block only',
            'target_domain' => 'block-api-scope.local.test',
            'brief_description' => 'Verify block config updates are scoped to the selected page block.',
            'user_description' => 'Verify block config updates are scoped to the selected page block.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
            'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
            'website_profile' => [
                'site_title' => 'Block API scope test',
                'site_tagline' => 'Current block only',
                'target_domain' => 'block-api-scope.local.test',
                'default_locale' => 'en_US',
            ],
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'title' => 'Home',
                    'handle' => Page::getDefaultHandleForType(Page::TYPE_HOME),
                    'locale' => 'en_US',
                    'blocks' => [$homeHeader, $homeHero],
                ],
                Page::TYPE_ABOUT => [
                    'title' => 'About',
                    'handle' => Page::getDefaultHandleForType(Page::TYPE_ABOUT),
                    'locale' => 'en_US',
                    'blocks' => [$aboutHeader],
                ],
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

        $payload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-update-block-config',
            'POST',
            'postUpdateBlockConfig',
            [],
            [
                'public_id' => $publicId,
                'page_type' => Page::TYPE_HOME,
                'block_id' => 'home-site-header',
                'block_config' => \json_encode([
                    'site_title' => 'Home header tuned',
                    'site_tagline' => 'Home tagline tuned',
                    'current_page_label' => 'Home',
                    'nav_items' => [],
                ], \JSON_UNESCAPED_UNICODE),
            ]
        );

        self::assertTrue((bool)($payload['success'] ?? false), \json_encode($payload, \JSON_UNESCAPED_UNICODE));
        self::assertSame('home-site-header', (string)($payload['block']['block_id'] ?? ''));
        self::assertSame('Home header tuned', (string)($payload['block']['config']['site_title'] ?? ''));
        self::assertStringContainsString('Home header tuned', (string)($payload['block']['html'] ?? ''));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        $virtualPages = (array)($scope['virtual_pages_by_type'] ?? []);
        $homeBlocks = (array)($virtualPages[Page::TYPE_HOME]['blocks'] ?? []);
        $aboutBlocks = (array)($virtualPages[Page::TYPE_ABOUT]['blocks'] ?? []);

        $homeHeaderAfter = $this->findBlockById($homeBlocks, 'home-site-header');
        $homeHeroAfter = $this->findBlockById($homeBlocks, 'home-hero');
        $aboutHeaderAfter = $this->findBlockById($aboutBlocks, 'about-site-header');

        self::assertSame('Home header tuned', (string)($homeHeaderAfter['config']['site_title'] ?? ''));
        self::assertStringContainsString('Home header tuned', (string)($homeHeaderAfter['html'] ?? ''));
        self::assertSame($homeHero['config'], (array)($homeHeroAfter['config'] ?? []));
        self::assertSame('About header before', (string)($aboutHeaderAfter['config']['site_title'] ?? ''));
        self::assertSame('<header>About header before</header>', (string)($aboutHeaderAfter['html'] ?? ''));
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

    public function testSwitchPreviewPageAppendsMissingPageTypeToScope(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));
        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => [
                    'site_title' => 'Switch preview scope sync test',
                    'page_types' => [Page::TYPE_HOME],
                ],
            ]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        $switchPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-switch-preview-page',
            'POST',
            'postSwitchPreviewPage',
            [],
            [
                'public_id' => $publicId,
                'preview_page_id' => '0',
                'preview_page_type' => Page::TYPE_ABOUT,
            ]
        );
        self::assertTrue((bool)($switchPayload['success'] ?? false), \json_encode($switchPayload, \JSON_UNESCAPED_UNICODE));
        $data = (array)($switchPayload['data'] ?? []);
        self::assertSame(Page::TYPE_ABOUT, (string)($data['preview_page_type'] ?? ''));
        self::assertStringContainsString('page_type=' . Page::TYPE_ABOUT, (string)($data['visual_preview_url'] ?? ''));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        $pts = (array)($scope['page_types'] ?? []);
        self::assertContains(Page::TYPE_ABOUT, $pts, 'about_page should be merged into scope when user switches preview to it');
    }

    public function testStartBuildRequiresStagePlansBeforeBuild(): void
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
            'site_title' => 'Plan gate test',
            'site_tagline' => 'Build must wait for confirm',
            'target_domain' => 'plan-gate.local.test',
            'brief_description' => 'Verify build is blocked before plan confirmation.',
            'user_description' => 'Verify build is blocked before plan confirmation.',
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

        self::assertFalse((bool)($startBuildPayload['success'] ?? true), \json_encode($startBuildPayload, \JSON_UNESCAPED_UNICODE));
        self::assertSame('PLAN_REQUIRED_BEFORE_BUILD', (string)($startBuildPayload['code'] ?? ''));

        $planFlow = $this->generateAndConfirmPlan($publicId, $scopePatch);
        self::assertSame(1, (int)($planFlow['confirm_plan']['data']['plan_confirmed'] ?? 0));

        $startBuildWithoutTaskPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-build',
            'POST',
            'postStartBuild',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertFalse((bool)($startBuildWithoutTaskPlanPayload['success'] ?? true), \json_encode($startBuildWithoutTaskPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertSame('TASK_PLAN_REQUIRED_BEFORE_BUILD', (string)($startBuildWithoutTaskPlanPayload['code'] ?? ''));
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return array<string,mixed>
     */
    private function findBlockById(array $blocks, string $blockId): array
    {
        foreach ($blocks as $block) {
            if (\is_array($block) && \trim((string)($block['block_id'] ?? '')) === $blockId) {
                return $block;
            }
        }

        self::fail('Expected block not found: ' . $blockId);
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

        $planFlow = $this->generateAndConfirmPlan($publicId, $scopePatch);
        self::assertSame(1, (int)($planFlow['confirm_plan']['data']['plan_confirmed'] ?? 0));
        $taskPlanFlow = $this->generateAndConfirmTaskPlan($publicId, $scopePatch);
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
        self::assertNotSame('', (string)($startBuildPayload['execution_token'] ?? ''));

        $buildWriter = new InMemorySseWriter();
        $buildResult = $this->invokePrivateOperation('runBuildOperation', $buildWriter, $publicId);

        self::assertNotSame('', (string)($buildResult['message'] ?? ''));
        self::assertGreaterThan(0, (int)($buildResult['draft_website_id'] ?? 0));
        self::assertGreaterThan(0, (int)($buildResult['virtual_theme_id'] ?? 0));
        self::assertGreaterThanOrEqual(\count($pageTypes), $buildWriter->countEvents('progress'));
        self::assertCount(\count($pageTypes), $buildWriter->eventsByName('page_generated'));
        $parallelBatchEvents = \array_values(\array_filter(
            $buildWriter->eventsByName('info'),
            static function (array $event): bool {
                $payload = \is_array($event['data'] ?? null) ? $event['data'] : [];
                return (string)($payload['event_type'] ?? '') === 'build_parallel_batch';
            }
        ));
        self::assertNotEmpty($parallelBatchEvents);
        self::assertContains('started', \array_map(
            static fn(array $event): string => (string)((\is_array($event['data'] ?? null) ? $event['data'] : [])['batch_state'] ?? ''),
            $parallelBatchEvents
        ));
        self::assertContains('completed', \array_map(
            static fn(array $event): string => (string)((\is_array($event['data'] ?? null) ? $event['data'] : [])['batch_state'] ?? ''),
            $parallelBatchEvents
        ));
        self::assertSame(1, $buildWriter->countEvents('environment_ready'));
        foreach ($buildWriter->eventsByName('page_generated') as $generatedEvent) {
            $payload = \is_array($generatedEvent['data'] ?? null) ? $generatedEvent['data'] : [];
            self::assertGreaterThan(0, (int)($payload['page_id'] ?? 0));
        }

        $buildState = $this->fetchWorkspaceState($publicId);

        return [
            'public_id' => $publicId,
            'site_title' => $siteTitle,
            'target_domain' => $targetDomain,
            'page_types' => $pageTypes,
            'build_state' => $buildState,
        ];
    }
}
