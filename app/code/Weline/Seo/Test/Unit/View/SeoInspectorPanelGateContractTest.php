<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class SeoInspectorPanelGateContractTest extends TestCase
{
    public function testInspectorLoadsOnlyAfterPanelSeoTab(): void
    {
        $renderer = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/Head/HeadRenderer.php');
        $inspector = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/seo-inspector/inspector.js');
        $provider = (string)file_get_contents(
            dirname(__DIR__, 4) . '/DeveloperWorkspace/extends/module/Weline_Framework/Query/DeveloperWorkspaceAdminQueryProvider.php'
        );

        self::assertStringContainsString('ensureInspectorScript', $renderer);
        self::assertStringContainsString('data-weline-panel-seo-bootstrap', $renderer);
        self::assertStringContainsString('startLiveCwvObservers', $inspector);
        self::assertStringContainsString('data-weline-seo-copy-html', $inspector);
        self::assertStringContainsString('seo/gsc/inspect', $inspector);
        self::assertStringContainsString("requiredTypes: [\"BreadcrumbList\", \"Organization\", \"WebSite\"]", $inspector);
        self::assertStringContainsString("requiredTypes: [\"AboutPage\", \"BreadcrumbList\"]", $inspector);
        self::assertStringContainsString('if (/\\/about(?:\\/|$)/.test(path)) return "about";', $inspector);
        self::assertStringContainsString('about: { required: ["AboutPage", "Organization", "WebSite", "BreadcrumbList"]', $inspector);
        self::assertStringContainsString('if (/\/blog$/.test(path)) return "collection";', $inspector);
        self::assertStringContainsString('explicitNorm === "blog_list" || explicitNorm === "blog_category"', $inspector);
        self::assertStringContainsString('never invent "… +N" placeholders', $inspector);
        self::assertStringContainsString('urlTemplate 必须含字面量 {search_term_string}', $inspector);
        self::assertStringContainsString('博客列表 CollectionPage + Article/BlogPosting ItemList', $inspector);
        self::assertStringNotContainsString('.concat(["… +"', $inspector);
        self::assertStringNotContainsString('if (/\/blog(?:\/|$)/.test(path) || /\/post(?:\/|$)/.test(path)) return "blog";', $inspector);
        self::assertStringContainsString('pushRichFact(facts, "路径"', $inspector);
        self::assertStringContainsString('/#breadcrumb\\b/i', $inspector);
        self::assertStringContainsString('ListItem.item 须为绝对 URL', $inspector);
        self::assertStringContainsString('不要给 BreadcrumbList 加 @id', $inspector);
        self::assertStringContainsString('Google Carousel/ItemList 不支持电商 Product', $inspector);
        self::assertStringContainsString('Google defaults to index,follow', $inspector);
        self::assertStringContainsString('large third-party JavaScript', $inspector);
        self::assertStringContainsString('SEARCH_ENGINE_RULE_CATALOG', $inspector);
        self::assertStringContainsString('function buildEngineRows', $inspector);
        self::assertStringContainsString('SITEMAP_DISCOVERY_ROBOTS_TXT', $inspector);
        self::assertStringContainsString('ensureSitemapProbe', $inspector);
        self::assertStringContainsString('关注点（非失败项）', $inspector);
        self::assertStringContainsString('large CSS resources (DEV)', $inspector);
        self::assertStringContainsString('说明·不扣分', $inspector);
        self::assertStringContainsString('scoringExempt', $inspector);
        self::assertStringContainsString("data-w-component='drawer'", $inspector);
        self::assertStringContainsString('product-native-detail__description-body', $inspector);
        self::assertStringContainsString('项 · 通过 ', $inspector);
        self::assertStringContainsString('EEAT_STRICT_RULES', $inspector);
        self::assertStringContainsString('function auditEeatStrict', $inspector);
        self::assertStringContainsString('function buildEeatStrictReport', $inspector);
        self::assertStringContainsString('function renderEeatStrictSection', $inspector);
        self::assertStringContainsString('Google Helpful Content 自测', $inspector);
        self::assertStringContainsString('who_person_url_or_sameas', $inspector);
        self::assertStringContainsString('who_visible_byline', $inspector);
        self::assertStringContainsString('why_primary_audience', $inspector);
        self::assertStringContainsString('"Why: audience & intent (manual)"', $inspector);
        self::assertStringContainsString('与上方「对照 Google 官方示例」', $inspector);
        self::assertStringContainsString('Why（意图）', $inspector);
        self::assertStringContainsString('eeat-pass-summary', $inspector);
        self::assertStringContainsString(
            'eeatFindRule("why_primary_audience")',
            $inspector
        );
        self::assertStringContainsString('if (!whyMainOk)', $inspector);
        self::assertStringNotContainsString('机检代理已通过（主内容服务读者）', $inspector);
        self::assertStringContainsString('eeat_org_sameas', $inspector);
        self::assertStringContainsString('eeat_article_author_shallow', $inspector);
        self::assertStringContainsString('Content substance (not Experience)', $inspector);
        self::assertStringContainsString('{ id: "eeat", title:', $inspector);
        self::assertStringNotContainsString("'</strong><span>Passed</span></div>'", $inspector);
        self::assertStringNotContainsString('favicon svg', $inspector);
        self::assertStringNotContainsString('sizes="32x32"', $inspector);
        self::assertStringNotContainsString('YAHOO_001_TITLE_NOT_ACCURATE', $inspector);
        self::assertStringNotContainsString('DDG_003_ENTITY_SOURCE_WEAK', $inspector);
        self::assertStringNotContainsString('EQ_004_PRIVACY_PAGE_MISSING', $inspector);
        self::assertStringNotContainsString('YANDEX_004_BREADCRUMB_JSONLD_INVALID', $inspector);
        self::assertStringNotContainsString("'@id' => \$url . '#breadcrumb'", $renderer);
        self::assertStringContainsString("'seo/gsc/inspect'", $provider);
        self::assertFileExists(dirname(__DIR__, 4) . '/DeveloperWorkspace/Api/Rest/V1/Seo/Gsc.php');
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/PanelGoogleUrlInspectionService.php');
        self::assertStringContainsString('function inspectUrl', (string)file_get_contents(
            dirname(__DIR__, 3) . '/Adapter/GoogleSitemapAdapter.php'
        ));
    }
}
