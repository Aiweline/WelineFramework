<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Frontend\Page as FrontendPageController;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription as PageLocalDescription;
use GuoLaiRen\PageBuilder\Model\PageLayout;
use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Websites\Controller\Backend\SiteBuilderAgent as WebsitesSiteBuilderAgent;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

/**
 * PHPUnit ??????????????????????????????????STDERR ?????????
 *
 * ??{@see AiSiteWorkbenchSuccessIntegrationTest} ??????? {@see AbstractAiSiteWorkbenchIntegrationHarness}??
 * ??????{@see self::SIM_TARGET_DOMAIN}??
 * ?????? unique ???????????? / ??????????????
 *
 * ????????`dev/phpunit/config.xml` bootstrap??
 *
 * ```bash
 * php vendor/phpunit/phpunit/phpunit --configuration dev/phpunit/config.xml app/code/GuoLaiRen/PageBuilder/test/Integration/AiSiteWorkbenchPhpunitSimulationIntegrationTest.php
 * ```
 *
 * ????????{@see self::SIM_TARGET_DOMAIN} ???? hosts ?? WLS/?? Worker?????????????URL??
 * ???????URL????page_id?????? 80 ??????????????????????????
 *
 * @group integration
 * @group pagebuilder_workbench
 * @group pagebuilder_phpsim
 */
class AiSiteWorkbenchPhpunitSimulationIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    private const SIM_SITE_TITLE_PREFIX = '[PT-SIM] PHPUnit AI Site ';
    private const LOCAL_FIXED_TEST_DOMAIN = 'demo.weline.local';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgePreviousSimulationSites();
    }

    public function testFullAiSiteBuildWritesDbCleanupPreviousRunAndPrintsFinalUrls(): void
    {
        if (\getenv('PAGE_BUILDER_RUN_PHPSIM') !== '1') {
            self::markTestSkipped(
                'Real-AI 全链路仿真：默认跳过。设置环境变量 PAGE_BUILDER_RUN_PHPSIM=1 后再运行（耗时长且依赖上游 AI 输出质量）。'
            );
        }
        $suffix = \date('YmdHis') . '-' . \substr(\bin2hex(\random_bytes(4)), 0, 8);
        $siteTitle = self::SIM_SITE_TITLE_PREFIX . $suffix;
        $targetDomain = $this->recommendLocalWelineDomain($siteTitle);
        $pageTypes = [Page::TYPE_HOME];

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
            'site_tagline' => 'PHPUnit simulation full publish',
            'target_domain' => $targetDomain,
            'brief_description' => 'Generate a complete single-page homepage for local verification with real AI content.',
            'user_description' => 'Generate a complete single-page homepage for local verification with real AI content.',
            'page_types' => $pageTypes,
        ];

        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            ['public_id' => $publicId, 'scope_patch' => $scopePatch]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        $planFlow = $this->generateAndConfirmPlan($publicId, $scopePatch);
        self::assertSame(1, (int)($planFlow['confirm_plan']['data']['plan_confirmed'] ?? 0));

        $startBuildPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-build',
            'POST',
            'postStartBuild',
            [],
            ['public_id' => $publicId, 'scope_patch' => $scopePatch]
        );
        self::assertTrue((bool)($startBuildPayload['success'] ?? false), \json_encode($startBuildPayload, \JSON_UNESCAPED_UNICODE));

        RequestContext::set(AiSiteScopeCompatibilityService::REQUEST_KEY_PLACEHOLDER_REQUIRE_AI, true);
        RequestContext::set(AiSitePageComponentGenerationService::REQUEST_KEY_FORCE_REAL_AI_IN_TEST, true);
        try {
            $buildResult = null;
            $lastBuildError = null;
            $buildAttempts = 3;
            for ($attempt = 1; $attempt <= $buildAttempts; $attempt++) {
                try {
                    $buildWriter = new InMemorySseWriter();
                    $buildResult = $this->invokePrivateOperation('runBuildOperation', $buildWriter, $publicId);
                    $lastBuildError = null;
                    break;
                } catch (\RuntimeException $e) {
                    $msg = \strtolower(\trim($e->getMessage()));
                    $isAiSyntaxFailure = \str_contains($msg, 'syntax error')
                        || \str_contains($msg, 'ai ')
                        || \str_contains($msg, 'php ');
                    if (!$isAiSyntaxFailure || $attempt >= $buildAttempts) {
                        throw $e;
                    }
                    $lastBuildError = $e->getMessage();
                }
            }
            self::assertNotNull($buildResult, 'Build should succeed within retry budget. Last error: ' . (string)$lastBuildError);
        } finally {
            RequestContext::remove(AiSiteScopeCompatibilityService::REQUEST_KEY_PLACEHOLDER_REQUIRE_AI);
            RequestContext::remove(AiSitePageComponentGenerationService::REQUEST_KEY_FORCE_REAL_AI_IN_TEST);
        }
        /** @var array<string,mixed> $buildResult */
        self::assertGreaterThan(0, (int)($buildResult['draft_website_id'] ?? 0));
        $draftWebsiteId = (int)($buildResult['draft_website_id'] ?? 0);
        $virtualThemeId = (int)($buildResult['virtual_theme_id'] ?? 0);

        $buildStatePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/get-state-json',
            'GET',
            'getStateJson',
            ['public_id' => $publicId]
        );
        self::assertTrue((bool)($buildStatePayload['success'] ?? false), \json_encode($buildStatePayload, \JSON_UNESCAPED_UNICODE));
        $buildState = \is_array($buildStatePayload['data'] ?? null) ? $buildStatePayload['data'] : [];
        self::assertGreaterThan(0, (int)($buildState['virtual_theme_id'] ?? 0), 'Virtual theme build should be produced in full workflow.');
        self::assertNotSame('', (string)($buildState['visual_preview_url'] ?? ''));
        self::assertNotSame('', (string)($buildState['visual_edit_url'] ?? ''));

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

        $publishWriter = new InMemorySseWriter();
        $publishResult = $this->invokePrivateOperation('runPublishOperation', $publishWriter, $publicId);
        self::assertIsArray($publishResult['published'] ?? null);

        $publishStatePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/get-state-json',
            'GET',
            'getStateJson',
            ['public_id' => $publicId]
        );
        self::assertTrue((bool)($publishStatePayload['success'] ?? false), \json_encode($publishStatePayload, \JSON_UNESCAPED_UNICODE));
        $publishState = \is_array($publishStatePayload['data'] ?? null) ? $publishStatePayload['data'] : [];
        self::assertSame(AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED, (string)($publishState['publish_status'] ?? ''));

        $publishedPages = (array)($publishState['pagebuilder_pages_by_type'] ?? []);
        $homePageId = (int)($publishedPages[Page::TYPE_HOME]['page_id'] ?? 0);
        self::assertGreaterThan(0, $homePageId);

        /** @var Website $website */
        $website = ObjectManager::getInstance(Website::class);
        $website->clearData()->clearQuery()->load($draftWebsiteId);
        self::assertGreaterThan(0, $website->getWebsiteId());
        $websiteBaseUrl = \rtrim((string)$website->getUrl(), '/');

        WebsiteData::setWebsite($website);
        $this->prepareFrontendRequest(
            '/pagebuilder/frontend/page/view',
            'GET',
            'view',
            ['page_id' => $homePageId],
            [],
            'Frontend/Page'
        );
        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        $frontendHomeRelative = $url->getFrontendUrl('pagebuilder/frontend/page/view', ['page_id' => $homePageId]);

        $parsed = \parse_url($websiteBaseUrl);
        $siteHost = (string)($parsed['host'] ?? '');
        $siteScheme = (string)($parsed['scheme'] ?? 'http');
        if ($siteHost !== '') {
            $parsedFe = \parse_url($frontendHomeRelative);
            $pathQuery = (string)($parsedFe['path'] ?? '') . (isset($parsedFe['query']) ? '?' . $parsedFe['query'] : '');
            $frontendHomeOnSiteHost = $siteScheme . '://' . $siteHost . $pathQuery;
        } else {
            $frontendHomeOnSiteHost = $frontendHomeRelative;
        }

        $lines = [
            '',
            '======== PAGEBUILDER PHPUNIT SIMULATION ??FINAL URLS ========',
            'website_id=' . $draftWebsiteId,
            'virtual_theme_id=' . $virtualThemeId,
            'public_id=' . $publicId,
            'website_record_url=' . $websiteBaseUrl,
            'frontend_home_url (match website host)=' . $frontendHomeOnSiteHost,
            'frontend_home_url (phpunit request base)=' . $frontendHomeRelative,
            'hosts: map ' . $targetDomain . ' to your WLS/frontend IP if needed',
            '==============================================================',
            '',
        ];
        \fwrite(\STDERR, \implode(\PHP_EOL, $lines));

        /** @var FrontendPageController $frontendPage */
        $frontendPage = ObjectManager::getInstance(FrontendPageController::class);
        \ob_start();
        try {
            $frontendPage->view();
        } finally {
            $liveHtml = (string)\ob_get_clean();
        }
        self::assertStringContainsString('<!DOCTYPE', $liveHtml);
        self::assertStringNotContainsString(
            'Test environment uses deterministic section markup so build and publish flows stay stable.',
            $liveHtml,
            'Real AI mode should not render deterministic test stub marker.'
        );
        while (\ob_get_level() > 0) {
            \ob_end_clean();
        }
    }

    private function purgePreviousSimulationSites(): void
    {
        $websiteModel = ObjectManager::getInstance(Website::class);
        $rows = (clone $websiteModel)->clearQuery()
            ->where(Website::schema_fields_SCOPE, 'page_builder')
            ->where(Website::schema_fields_NAME, '%' . self::SIM_SITE_TITLE_PREFIX . '%', 'like')
            ->select()
            ->fetchArray();

        $websiteIds = [];
        foreach ($rows as $row) {
            $id = (int)($row[Website::schema_fields_ID] ?? $row['website_id'] ?? 0);
            if ($id > 0) {
                $websiteIds[] = $id;
            }
        }

        foreach ($websiteIds as $websiteId) {
            $this->deleteWebsiteCascadeForSimulation($websiteId);
        }

        $this->deleteOrphanSessionsForSimulationDomain();
    }

    private function deleteWebsiteCascadeForSimulation(int $websiteId): void
    {
        if ($websiteId <= 0) {
            return;
        }

        $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $sessionModel = ObjectManager::getInstance(AiSiteAgentSession::class);
        $sessions = (clone $sessionModel)->clearQuery()
            ->where(AiSiteAgentSession::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();
        foreach ($sessions as $srow) {
            $sid = (int)($srow[AiSiteAgentSession::schema_fields_ID] ?? $srow['ai_site_agent_session_id'] ?? 0);
            $adminId = (int)($srow[AiSiteAgentSession::schema_fields_ADMIN_USER_ID] ?? 0);
            if ($sid > 0 && $adminId > 0) {
                $sessionService->deleteSession($sid, $adminId);
            }
        }

        $vtModel = ObjectManager::getInstance(VirtualTheme::class);
        (clone $vtModel)->clearQuery()
            ->where(VirtualTheme::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();

        $pageModel = ObjectManager::getInstance(Page::class);
        $pageRows = (clone $pageModel)->clearQuery()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->select(Page::schema_fields_ID)
            ->fetchArray();
        $pageIds = [];
        foreach ($pageRows as $prow) {
            $pid = (int)($prow[Page::schema_fields_ID] ?? $prow['page_id'] ?? 0);
            if ($pid > 0) {
                $pageIds[] = $pid;
            }
        }

        if ($pageIds !== []) {
            $layoutModel = ObjectManager::getInstance(PageLayout::class);
            foreach ($pageIds as $pid) {
                (clone $layoutModel)->clearQuery()
                    ->where(PageLayout::schema_fields_PAGE_ID, $pid)
                    ->delete()
                    ->fetch();
            }
            $locModel = ObjectManager::getInstance(PageLocalDescription::class);
            foreach ($pageIds as $pid) {
                (clone $locModel)->clearQuery()
                    ->where(PageLocalDescription::schema_fields_ID, $pid)
                    ->delete()
                    ->fetch();
            }
        }

        (clone $pageModel)->clearQuery()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();

        $website = ObjectManager::getInstance(Website::class);
        $website->clearData()->clearQuery()->load($websiteId);
        if ($website->getWebsiteId() > 0) {
            $website->delete()->fetch();
        }
    }

    private function deleteOrphanSessionsForSimulationDomain(): void
    {
        $sessionModel = ObjectManager::getInstance(AiSiteAgentSession::class);
        $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $rows = (clone $sessionModel)->clearQuery()
            ->where(AiSiteAgentSession::schema_fields_SCOPE_JSON, '%[PT-SIM] PHPUnit AI Site%', 'like')
            ->select()
            ->fetchArray();
        foreach ($rows as $srow) {
            $sid = (int)($srow[AiSiteAgentSession::schema_fields_ID] ?? $srow['ai_site_agent_session_id'] ?? 0);
            $adminId = (int)($srow[AiSiteAgentSession::schema_fields_ADMIN_USER_ID] ?? 0);
            if ($sid > 0 && $adminId > 0) {
                $sessionService->deleteSession($sid, $adminId);
            }
        }
    }

    private function recommendLocalWelineDomain(string $description): string
    {
        self::initRequest('/websites/backend/site-builder-agent/recommend-domain');
        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        Request::clearStaticUrlPathCache();
        $request->setBackend();
        $request->setServer('WELINE_AREA', 'backend');
        $request->setServer('REQUEST_URI', '/websites/backend/site-builder-agent/recommend-domain');
        $request->setMethod('POST');
        $request->setData('router/module', 'Weline_Websites');
        $request->setData('router/module_path', BP . 'app/code/Weline/Websites/');
        $request->setData('router/class/controller_name', 'Backend/SiteBuilderAgent');
        $request->setData('router/class/method', 'postRecommendDomain');
        $request->setData('router/backend_router', 'websites');
        $request->setGet('fake_mode', '1');
        $request->setPost('fake_mode', 1);
        $request->setPost('description', $description);
        $request->setPost('domain', '');
        $request->setPost('account_id', 0);
        $request->setPost('defer_availability_check', 1);

        /** @var WebsitesSiteBuilderAgent $controller */
        $controller = ObjectManager::getInstance(WebsitesSiteBuilderAgent::class);
        $response = (string)$controller->postRecommendDomain();
        $decoded = \json_decode($response, true);
        self::assertIsArray($decoded, 'Recommend domain response must be JSON object: ' . $response);
        self::assertTrue((bool)($decoded['success'] ?? false), \json_encode($decoded, \JSON_UNESCAPED_UNICODE));
        $recommended = \strtolower(\trim((string)($decoded['domain'] ?? '')));
        self::assertNotSame('', $recommended, 'Recommend domain should return non-empty domain.');
        self::assertStringEndsWith('.weline.local', $recommended, 'Fake mode should return *.weline.local domain.');

        // ?????????????????????????? hosts?
        return self::LOCAL_FIXED_TEST_DOMAIN;
    }
}
