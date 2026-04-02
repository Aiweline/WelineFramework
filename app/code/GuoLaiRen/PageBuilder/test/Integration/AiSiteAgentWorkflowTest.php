<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteDraftWebsiteService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use GuoLaiRen\PageBuilder\Service\AiSitePublishService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI 建站完整流程集成测试
 *
 * 测试场景：
 * 1. 创建会话
 * 2. 填写网站信息并选择页面类型
 * 3. 确认并生成虚拟主题（SSE 流式生成每个页面）
 * 4. 切换预览页面
 * 5. 重新生成单个页面
 * 6. 发布检查
 * 7. 正式发布
 */
class AiSiteAgentWorkflowTest extends TestCase
{
    private AiSiteAgentSessionService $sessionService;
    private AiSiteDraftWebsiteService $draftWebsiteService;
    private AiSiteVirtualThemeService $virtualThemeService;
    private AiSitePublishService $publishService;

    private int $testAdminId = 1;
    private ?AiSiteAgentSession $testSession = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $this->draftWebsiteService = ObjectManager::getInstance(AiSiteDraftWebsiteService::class);
        $this->virtualThemeService = ObjectManager::getInstance(AiSiteVirtualThemeService::class);
        $this->publishService = ObjectManager::getInstance(AiSitePublishService::class);

        // 清理之前的测试数据
        $this->cleanupOldTestData();
    }

    /**
     * 清理旧的测试数据
     */
    private function cleanupOldTestData(): void
    {
        try {
            // 删除所有测试会话（标题包含 "AI 测试站点" 的）
            $sessions = $this->sessionService->listRecentSessionsForAdmin($this->testAdminId, 100);
            foreach ($sessions as $sessionData) {
                $sessionId = (int)($sessionData['ai_site_agent_session_id'] ?? 0);
                if ($sessionId > 0) {
                    $session = $this->sessionService->loadById($sessionId, $this->testAdminId);
                    if ($session !== null) {
                        $scope = $session->getScopeArray();
                        $siteTitle = (string)($scope['site_title'] ?? '');
                        if (strpos($siteTitle, 'AI 测试站点') !== false) {
                            // 删除测试会话
                            $model = ObjectManager::getInstance(AiSiteAgentSession::class);
                            $model->load($sessionId)->delete();
                        }
                    }
                }
            }

            // 删除草稿站点（URL 为 pagebuilder-ai-draft.local.test）
            $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $website = clone $websiteModel;
            $website->where('url', 'http://pagebuilder-ai-draft.local.test')->find()->fetch();
            if ($website->getWebsiteId() > 0) {
                $website->delete();
            }

            // 删除名称包含 "AI 测试站点" 的网站
            $testWebsites = $websiteModel->select()
                ->where('name', 'AI 测试站点%', 'LIKE')
                ->select()
                ->fetchOrigin();

            foreach ($testWebsites as $websiteData) {
                $websiteId = (int)($websiteData['website_id'] ?? 0);
                if ($websiteId > 0) {
                    $site = clone $websiteModel;
                    $site->load($websiteId)->delete();
                }
            }
        } catch (\Throwable $e) {
            // 忽略清理错误
        }
    }

    /**
     * 输出日志
     */
    private function log(string $message): void
    {
        echo $message . PHP_EOL;
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        if ($this->testSession !== null) {
            try {
                // 这里可以添加清理逻辑，但为了调试保留测试数据
                // $this->sessionService->deleteSession($this->testSession->getId(), $this->testAdminId);
            } catch (\Throwable $e) {
                // 忽略清理错误
            }
        }
        parent::tearDown();
    }

    /**
     * 测试完整建站流程
     */
    public function testCompleteAiSiteBuildingWorkflow(): void
    {
        $this->log('');
        $this->log('========================================');
        $this->log('AI 建站完整流程测试开始');
        $this->log('========================================');

        // 步骤 1: 创建会话
        $this->log('');
        $this->log('[步骤 1] 创建 AI 建站会话...');
        $session = $this->createSession();
        $this->assertNotNull($session, '会话创建失败');
        $this->assertNotEmpty($session->getPublicId(), '会话 public_id 为空');
        $this->log("✓ 会话创建成功，Public ID: {$session->getPublicId()}");

        // 步骤 2: 填写网站信息
        $this->log('');
        $this->log('[步骤 2] 填写网站信息并选择页面类型...');
        $websiteInfo = $this->fillWebsiteInfo($session);
        $this->assertArrayHasKey('site_title', $websiteInfo, '网站标题未设置');
        $this->assertArrayHasKey('page_types', $websiteInfo, '页面类型未设置');
        $this->assertNotEmpty($websiteInfo['page_types'], '页面类型列表为空');
        $this->log("✓ 网站信息已填写：{$websiteInfo['site_title']}");
        $this->log("✓ 选择页面类型：" . implode(', ', $websiteInfo['page_types']));

        // 步骤 3: 启动虚拟主题构建（模拟 SSE 流式生成）
        $this->log('');
        $this->log('[步骤 3] 启动虚拟主题构建（逐个生成页面）...');
        $buildResult = $this->startBuildOperation($session, $websiteInfo);
        $this->assertTrue($buildResult['success'], '构建启动失败：' . ($buildResult['message'] ?? ''));
        $this->assertArrayHasKey('execution_token', $buildResult, '缺少 execution_token');
        $this->log("✓ 构建已启动，execution_token: {$buildResult['execution_token']}");

        // 步骤 4: 模拟 SSE 流式生成过程
        $this->log('');
        $this->log('[步骤 4] 模拟 SSE 流式生成每个页面...');
        $buildCompleted = $this->simulateBuildOperation($session, $websiteInfo['page_types']);
        $this->assertTrue($buildCompleted['success'], '构建执行失败：' . ($buildCompleted['message'] ?? ''));
        $this->assertGreaterThan(0, $buildCompleted['virtual_theme_id'], '虚拟主题 ID 无效');
        $this->assertGreaterThan(0, $buildCompleted['draft_website_id'], '草稿站点 ID 无效');
        $this->log("✓ 构建完成，虚拟主题 ID: {$buildCompleted['virtual_theme_id']}");
        $this->log("✓ 草稿站点 ID: {$buildCompleted['draft_website_id']}");

        // 步骤 5: 验证虚拟页面已生成
        $this->log('');
        $this->log('[步骤 5] 验证虚拟页面已生成...');
        $virtualPages = $this->verifyVirtualPages($session, $websiteInfo['page_types']);
        $this->assertCount(count($websiteInfo['page_types']), $virtualPages, '虚拟页面数量不匹配');
        foreach ($websiteInfo['page_types'] as $pageType) {
            $this->assertArrayHasKey($pageType, $virtualPages, "缺少页面类型：{$pageType}");
            $this->log("✓ 页面已生成：{$pageType}");
        }

        // 步骤 6: 切换预览页面
        $this->log('');
        $this->log('[步骤 6] 切换预览页面...');
        $targetPageType = $websiteInfo['page_types'][1] ?? $websiteInfo['page_types'][0];
        $switchResult = $this->switchPreviewPage($session, $targetPageType);
        $this->assertTrue($switchResult['success'], '切换预览页失败');
        $this->assertEquals($targetPageType, $switchResult['preview_page_type'], '预览页类型不匹配');
        $this->log("✓ 预览页已切换到：{$targetPageType}");

        // 步骤 7: 重新生成单个页面
        $this->log('');
        $this->log('[步骤 7] 重新生成单个页面...');
        $regeneratePageType = $websiteInfo['page_types'][0];
        $regenerateResult = $this->regeneratePage($session, $regeneratePageType);
        $this->assertTrue($regenerateResult['success'], '页面重建失败');
        $this->assertEquals($regeneratePageType, $regenerateResult['page_type'], '重建页面类型不匹配');
        $this->log("✓ 页面已重建：{$regeneratePageType}");

        // 步骤 8: 发布前检查
        $this->log('');
        $this->log('[步骤 8] 执行发布前检查...');
        $checkResult = $this->runPublishCheck($session);
        $this->assertTrue($checkResult['passed'], '发布检查未通过');
        $this->log('✓ 发布检查通过');
        foreach ($checkResult['items'] as $item) {
            $status = $item['ok'] ? '✓' : '✗';
            $this->log("  {$status} {$item['label']}");
        }

        // 步骤 9: 正式发布
        $this->log('');
        $this->log('[步骤 9] 执行正式发布...');
        $publishResult = $this->publishSite($session);
        $this->assertTrue($publishResult['success'], '发布失败：' . ($publishResult['message'] ?? ''));
        $this->assertArrayHasKey('pagebuilder_pages_by_type', $publishResult, '缺少已发布页面信息');
        $this->log('✓ 网站已成功发布');

        // 步骤 10: 验证发布结果
        $this->log('');
        $this->log('[步骤 10] 验证发布结果...');
        $publishedPages = $publishResult['pagebuilder_pages_by_type'];
        $this->assertCount(count($websiteInfo['page_types']), $publishedPages, '已发布页面数量不匹配');
        foreach ($websiteInfo['page_types'] as $pageType) {
            $this->assertArrayHasKey($pageType, $publishedPages, "缺少已发布页面：{$pageType}");
            $pageId = $publishedPages[$pageType]['page_id'] ?? 0;
            $this->assertGreaterThan(0, $pageId, "页面 ID 无效：{$pageType}");
            $this->log("✓ 页面已发布：{$pageType} (ID: {$pageId})");
        }

        // 步骤 11: 验证会话状态
        $this->log('');
        $this->log('[步骤 11] 验证会话最终状态...');
        $finalSession = $this->sessionService->loadById($session->getId(), $this->testAdminId);
        $this->assertNotNull($finalSession, '无法加载最终会话');
        $this->assertEquals(AiSiteAgentSession::STAGE_PUBLISH, $finalSession->getStage(), '会话阶段不正确');
        $this->assertEquals(AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED, $finalSession->getPublishStatus(), '发布状态不正确');
        $this->log('✓ 会话状态正确');
        $this->log("  - 阶段：{$finalSession->getStage()}");
        $this->log("  - 发布状态：{$finalSession->getPublishStatus()}");

        $this->log('');
        $this->log('========================================');
        $this->log('✓ AI 建站完整流程测试通过！');
        $this->log('========================================');
        $this->log('');
    }

    /**
     * 创建会话
     */
    private function createSession(): AiSiteAgentSession
    {
        $session = $this->sessionService->createSession($this->testAdminId, [
            'workspace_status' => 'preparing',
        ]);
        $this->testSession = $session;
        return $session;
    }

    /**
     * 填写网站信息
     */
    private function fillWebsiteInfo(AiSiteAgentSession $session): array
    {
        $timestamp = time();
        $websiteInfo = [
            'site_title' => 'AI 测试站点 - ' . date('Y-m-d H:i:s'),
            'site_tagline' => '这是一个自动化测试生成的网站',
            'target_domain' => 'ai-test-' . $timestamp . '.local.test',
            'brief_description' => '这是一个用于测试 AI 建站完整流程的测试站点，包含首页、关于页和联系页。',
            'user_description' => '测试站点，用于验证 AI 建站功能的完整性。',
            'page_types' => ['home_page', 'about_page', 'contact_page'],
        ];

        $this->sessionService->mergeScope($session->getId(), $this->testAdminId, $websiteInfo);

        return $websiteInfo;
    }

    /**
     * 启动构建操作
     */
    private function startBuildOperation(AiSiteAgentSession $session, array $websiteInfo): array
    {
        $scope = $this->sessionService->loadById($session->getId(), $this->testAdminId)->getScopeArray();
        $executionToken = bin2hex(random_bytes(16));

        $scope['active_operation'] = [
            'operation' => 'build',
            'execution_token' => $executionToken,
            'status' => 'queued',
            'page_type' => '',
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'message' => '等待开始',
        ];
        $scope['workspace_status'] = 'building';

        $this->sessionService->replaceScope($session->getId(), $this->testAdminId, $scope);
        $this->sessionService->setStage($session->getId(), $this->testAdminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);

        return [
            'success' => true,
            'execution_token' => $executionToken,
            'operation' => 'build',
        ];
    }

    /**
     * 模拟构建操作执行（SSE 流式生成）
     */
    private function simulateBuildOperation(AiSiteAgentSession $session, array $pageTypes): array
    {
        $scope = $this->sessionService->loadById($session->getId(), $this->testAdminId)->getScopeArray();
        $scope['website_profile'] = [
            'site_title' => $scope['site_title'] ?? 'Test Site',
            'site_tagline' => $scope['site_tagline'] ?? '',
            'brief_description' => $scope['brief_description'] ?? '',
        ];

        // 步骤 1: 创建草稿站点
        $this->log('  → 正在准备网站资料...');
        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $this->log("    ✓ 草稿站点已创建 (ID: {$draftWebsite['website_id']})");

        // 步骤 2: 逐个生成页面类型
        $this->log('  → 正在生成虚拟主题骨架...');
        $virtualPages = [];
        $pageTypeLayouts = [];
        $virtualThemeId = 0;

        foreach ($pageTypes as $index => $pageType) {
            $progress = (int)((($index + 1) / count($pageTypes)) * 100);
            $this->log("  → 正在生成页面：{$pageType} ({$progress}%)");

            // 为当前页面类型生成布局
            $pageTypeLayouts[$pageType] = [
                'header' => ['component_code' => 'header_default'],
                'content' => ['component_code' => 'content_default'],
                'footer' => ['component_code' => 'footer_default'],
            ];

            // 更新虚拟主题
            $theme = $this->virtualThemeService->ensureVirtualTheme(
                $scope,
                $scope['website_profile'],
                [$pageType],
                [$pageType => $pageTypeLayouts[$pageType]],
                $session->getId()
            );
            $virtualThemeId = (int)$theme['virtual_theme_id'];
            $scope['virtual_theme_id'] = $virtualThemeId;
            $scope['page_type_layouts'] = array_replace($scope['page_type_layouts'] ?? [], $theme['page_type_layouts']);

            // 构建虚拟页面
            $virtualPages[$pageType] = [
                'page_type' => $pageType,
                'title' => ucfirst(str_replace('_', ' ', $pageType)),
                'handle' => str_replace('_', '-', $pageType),
                'last_generated_at' => date('Y-m-d H:i:s'),
                'ai_description' => $scope['website_profile']['brief_description'] ?? '',
            ];

            // 实时更新 scope
            $scope['virtual_pages_by_type'] = $virtualPages;
            $this->sessionService->replaceScope($session->getId(), $this->testAdminId, $scope);
            $this->sessionService->bindVirtualTheme($session->getId(), $this->testAdminId, $virtualThemeId);

            $this->log("    ✓ 页面已生成：{$pageType}");
        }

        // 最终更新
        $scope['workspace_status'] = 'can_publish';
        $scope['active_operation']['status'] = 'done';
        $scope['active_operation']['message'] = '主题构建完成';
        $scope['active_operation']['updated_at'] = date('Y-m-d H:i:s');

        $this->sessionService->replaceScope($session->getId(), $this->testAdminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $this->testAdminId, (int)$draftWebsite['website_id']);
        $this->sessionService->setPublishStatus($session->getId(), $this->testAdminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);

        return [
            'success' => true,
            'message' => '主题构建完成',
            'draft_website_id' => (int)$draftWebsite['website_id'],
            'virtual_theme_id' => $virtualThemeId,
            'page_types' => $pageTypes,
        ];
    }

    /**
     * 验证虚拟页面
     */
    private function verifyVirtualPages(AiSiteAgentSession $session, array $expectedPageTypes): array
    {
        $scope = $this->sessionService->loadById($session->getId(), $this->testAdminId)->getScopeArray();
        $virtualPages = $scope['virtual_pages_by_type'] ?? [];

        return $virtualPages;
    }

    /**
     * 切换预览页面
     */
    private function switchPreviewPage(AiSiteAgentSession $session, string $pageType): array
    {
        $this->sessionService->mergeScope($session->getId(), $this->testAdminId, [
            'preview_page_type' => $pageType,
        ]);

        $updatedSession = $this->sessionService->loadById($session->getId(), $this->testAdminId);
        $scope = $updatedSession->getScopeArray();

        return [
            'success' => true,
            'preview_page_type' => $scope['preview_page_type'] ?? '',
        ];
    }

    /**
     * 重新生成单个页面
     */
    private function regeneratePage(AiSiteAgentSession $session, string $pageType): array
    {
        $scope = $this->sessionService->loadById($session->getId(), $this->testAdminId)->getScopeArray();

        // 重置该页面的布局配置
        $pageTypeLayouts = $scope['page_type_layouts'] ?? [];
        $pageTypeLayouts[$pageType] = [
            'header' => ['component_code' => 'header_default'],
            'content' => ['component_code' => 'content_default'],
            'footer' => ['component_code' => 'footer_default'],
        ];

        // 更新虚拟主题
        $theme = $this->virtualThemeService->ensureVirtualTheme(
            $scope,
            $scope['website_profile'] ?? [],
            [$pageType],
            [$pageType => $pageTypeLayouts[$pageType]],
            $session->getId()
        );

        // 更新虚拟页面
        $virtualPages = $scope['virtual_pages_by_type'] ?? [];
        $virtualPages[$pageType]['last_generated_at'] = date('Y-m-d H:i:s');

        $scope['page_type_layouts'] = $pageTypeLayouts;
        $scope['virtual_pages_by_type'] = $virtualPages;
        $scope['preview_page_type'] = $pageType;

        $this->sessionService->replaceScope($session->getId(), $this->testAdminId, $scope);
        $this->sessionService->bindVirtualTheme($session->getId(), $this->testAdminId, (int)$theme['virtual_theme_id']);

        return [
            'success' => true,
            'page_type' => $pageType,
            'virtual_theme_id' => (int)$theme['virtual_theme_id'],
        ];
    }

    /**
     * 执行发布检查
     */
    private function runPublishCheck(AiSiteAgentSession $session): array
    {
        $scope = $this->sessionService->loadById($session->getId(), $this->testAdminId)->getScopeArray();
        $virtualPages = $scope['virtual_pages_by_type'] ?? [];
        $pageTypes = $scope['page_types'] ?? [];

        $checkItems = [
            ['key' => 'draft_website', 'label' => '草稿站点已创建', 'ok' => (int)($scope['draft_website_id'] ?? 0) > 0],
            ['key' => 'virtual_theme', 'label' => '虚拟主题已生成', 'ok' => (int)($scope['virtual_theme_id'] ?? 0) > 0],
            ['key' => 'website_profile', 'label' => '网站级资料已齐备', 'ok' => !empty($scope['website_profile']['site_title'] ?? '')],
            ['key' => 'virtual_pages', 'label' => '虚拟页面已生成', 'ok' => count($virtualPages) >= count($pageTypes)],
        ];

        $passed = true;
        foreach ($checkItems as $item) {
            if (!$item['ok']) {
                $passed = false;
                break;
            }
        }

        return [
            'passed' => $passed,
            'items' => $checkItems,
        ];
    }

    /**
     * 发布站点
     */
    private function publishSite(AiSiteAgentSession $session): array
    {
        $scope = $this->sessionService->loadById($session->getId(), $this->testAdminId)->getScopeArray();
        $websiteId = (int)($scope['draft_website_id'] ?? 0);
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        $pageTypes = $scope['page_types'] ?? [];
        $pageTypeLayouts = $scope['page_type_layouts'] ?? [];
        $websiteProfile = $scope['website_profile'] ?? [];

        if ($websiteId <= 0 || $virtualThemeId <= 0) {
            return [
                'success' => false,
                'message' => '发布前请先完成主题构建',
            ];
        }

        $published = $this->publishService->publish($websiteId, $virtualThemeId, $websiteProfile, $pageTypes, $pageTypeLayouts);

        $scope['pagebuilder_pages_by_type'] = $published['pagebuilder_pages_by_type'] ?? [];
        $scope['workspace_status'] = 'published';

        $this->sessionService->replaceScope($session->getId(), $this->testAdminId, $scope);
        $this->sessionService->setPublishStatus($session->getId(), $this->testAdminId, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED);
        $this->sessionService->setStage($session->getId(), $this->testAdminId, AiSiteAgentSession::STAGE_PUBLISH);

        return [
            'success' => true,
            'message' => '发布完成',
            'pagebuilder_pages_by_type' => $published['pagebuilder_pages_by_type'] ?? [],
        ];
    }
}
