<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWebsitesMirrorService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;

/**
 * Characterization tests for the freshly extracted
 * `AiSiteAgentWebsitesMirrorService` (R4.3)。
 *
 * 本轮锁定 `buildScopeFromSource` 的字段映射 —— 它是 3 个抽出方法中唯一的纯函数，
 * 也是 PageBuilder ↔ Websites handoff 契约里最脆弱的一环：
 * 前端工作台 + Websites 采购队列都按这份 shape 读取，字段错位会立刻造成
 * "域名推荐列表丢失 / 注册商选项无法预选 / handoff_source 丢失回归到既有 provider" 等线上回归。
 *
 * `ensureMirrorSession` / `syncScopeBack` 依赖 `AiSiteAgentSessionService` 与
 * `WebsitesSessionService` 的真实 DB 读写，需要集成测试覆盖；单测不在本轮作用域内
 *（Service 顶部 docblock 已留下 TODO）。
 */
final class AiSiteAgentWebsitesMirrorServiceTest extends TestCase
{
    public function testBuildScopeFromSourceMapsCoreHandoffFieldsAndFallsBackToTargetDomainRecommendation(): void
    {
        $scopeCompatibility = $this->createMock(AiSiteScopeCompatibilityService::class);
        $scopeCompatibility->expects(self::once())
            ->method('normalizeScope')
            ->willReturn([
                'site_title' => 'Demo Shop',
                'site_tagline' => 'Best Demo',
                'target_domain' => 'Example.COM',
                'user_description' => 'Fallback brief',
                'default_language' => 'zh_Hans_CN',
                'page_types' => ['home', 'about'],
                'preferred_registrar_account_id' => 42,
                'fake_mode' => 1,
            ]);

        $url = $this->buildUrlMock(
            'pagebuilder/backend/ai-site-agent/workspace',
            ['public_id' => 'pb-public-1'],
            '/backend/workspace?public_id=pb-public-1'
        );

        $service = $this->createService($scopeCompatibility, $url);
        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getScopeArray')->willReturn([]);
        $session->method('getPublicId')->willReturn('pb-public-1');

        $result = $service->buildScopeFromSource($session);

        self::assertSame('pagebuilder_native_workspace', $result['handoff_source']);
        self::assertSame('pagebuilder_native_workspace', $result['provider_handoff_mode']);
        self::assertSame('pb-public-1', $result['pagebuilder_workspace_public_id']);
        self::assertSame('/backend/workspace?public_id=pb-public-1', $result['pagebuilder_workspace_url']);

        self::assertSame('Demo Shop', $result['site_title']);
        self::assertSame('Best Demo', $result['site_tagline']);
        self::assertSame('example.com', $result['target_domain'], 'target_domain 必须归一化为小写');
        self::assertSame('example.com', $result['selected_domain'], 'selected_domain 与 target_domain 保持同源');
        self::assertSame(['example.com'], $result['recommended_domain_list'], '缺省 recommended_domain_list 时应回退到 target_domain');

        self::assertSame('Fallback brief', $result['brief_description'], 'brief_description 缺省时使用 user_description 回填');
        self::assertSame('Fallback brief', $result['user_description']);
        self::assertSame('zh_Hans_CN', $result['plan_locale'], 'plan_locale 缺省时应从 default_language 回填');
        self::assertSame('zh_Hans_CN', $result['default_locale']);

        self::assertSame(42, $result['preferred_registrar_account_id']);
        self::assertSame(42, $result['registrar_account_id'], 'registrar_account_id 应镜像 preferred_registrar_account_id');
        self::assertSame(['home', 'about'], $result['page_types']);
        self::assertSame(['home', 'about'], $result['recommended_pages'], 'recommended_pages 缺省时回退到 page_types');
        self::assertSame(1, $result['fake_mode']);
        self::assertSame(1, $result['site_ready'], 'site_ready 默认值为 1');
    }

    public function testBuildScopeFromSourceHonorsExplicitRecommendedListAndBriefDescription(): void
    {
        $scopeCompatibility = $this->createMock(AiSiteScopeCompatibilityService::class);
        $scopeCompatibility->expects(self::once())
            ->method('normalizeScope')
            ->willReturn([
                'target_domain' => 'shop.example.io',
                'brief_description' => '  High priority brief  ',
                'user_description' => 'should be ignored',
                'recommended_domain_list' => "shop.example.io\nalt.example.io\nshop.example.io",
                'recommended_registrar_label' => '  Namecheap - Main  ',
                'registrar_account_id' => 7,
                'page_types' => ['home'],
                'recommended_pages' => ['home', 'landing'],
                'fake_mode' => 0,
                'site_ready' => 0,
                'plan_locale' => 'en_US',
            ]);

        $url = $this->buildUrlMock(
            'pagebuilder/backend/ai-site-agent/workspace',
            ['public_id' => 'pb-public-2'],
            '/backend/workspace?public_id=pb-public-2'
        );

        $service = $this->createService($scopeCompatibility, $url);
        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getScopeArray')->willReturn([]);
        $session->method('getPublicId')->willReturn('pb-public-2');

        $result = $service->buildScopeFromSource($session);

        self::assertSame('High priority brief', $result['brief_description'], 'brief 优先于 user_description，且应 trim');
        self::assertSame('High priority brief', $result['user_description'], 'brief 非空时 user_description 同步');

        self::assertSame(
            ['shop.example.io', 'alt.example.io'],
            $result['recommended_domain_list'],
            'recommended_domain_list 按换行切分并去重；不再回退到 target_domain'
        );
        self::assertSame('Namecheap - Main', $result['recommended_registrar_label']);
        self::assertSame(7, $result['preferred_registrar_account_id'], 'preferred_registrar_account_id 缺省时读取 registrar_account_id');
        self::assertSame(7, $result['registrar_account_id']);

        self::assertSame(['home', 'landing'], $result['recommended_pages'], 'recommended_pages 显式提供时不回退到 page_types');
        self::assertSame(['home'], $result['page_types']);
        self::assertSame('en_US', $result['plan_locale']);
        self::assertSame(0, $result['fake_mode']);
        self::assertSame(0, $result['site_ready']);
    }

    public function testBuildScopeFromSourceReturnsEmptyShapeWhenScopeIsEmpty(): void
    {
        $scopeCompatibility = $this->createMock(AiSiteScopeCompatibilityService::class);
        $scopeCompatibility->expects(self::once())
            ->method('normalizeScope')
            ->willReturn([]);

        $url = $this->buildUrlMock(
            'pagebuilder/backend/ai-site-agent/workspace',
            ['public_id' => 'pb-public-3'],
            '/backend/workspace?public_id=pb-public-3'
        );

        $service = $this->createService($scopeCompatibility, $url);
        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getScopeArray')->willReturn([]);
        $session->method('getPublicId')->willReturn('pb-public-3');

        $result = $service->buildScopeFromSource($session);

        self::assertSame('', $result['target_domain'], 'target_domain 缺省时为空字符串，不是 null');
        self::assertSame('', $result['selected_domain']);
        self::assertSame([], $result['recommended_domain_list'], 'target_domain 为空时不应伪造推荐列表');
        self::assertSame([], $result['page_types']);
        self::assertSame([], $result['recommended_pages']);
        self::assertSame(0, $result['preferred_registrar_account_id']);
        self::assertSame(1, $result['site_ready'], 'site_ready 兜底为 1（站点就绪默认值）');
        self::assertSame(0, $result['fake_mode']);
        self::assertSame('pagebuilder_native_workspace', $result['handoff_source']);
    }

    private function createService(
        AiSiteScopeCompatibilityService&MockObject $scopeCompatibility,
        Url&MockObject $url
    ): AiSiteAgentWebsitesMirrorService {
        return new AiSiteAgentWebsitesMirrorService(
            $scopeCompatibility,
            $this->createStub(AiSiteAgentSessionService::class),
            $url
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return Url&MockObject
     */
    private function buildUrlMock(string $path, array $params, string $resolved): Url
    {
        $url = $this->createMock(Url::class);
        $url->expects(self::once())
            ->method('getBackendUrl')
            ->with($path, $params)
            ->willReturn($resolved);

        return $url;
    }
}
