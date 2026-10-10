<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\PolicyDocumentDefaults;

/**
 * policy-document widget + ParamSchema + Theme policy layouts embed (XOR).
 */
final class PolicyDocumentWidgetContractTest extends TestCase
{
    public function testParamSchemasDeclareSortableI18nSectionsAndLinks(): void
    {
        $root = dirname(__DIR__, 3) . '/Ui/ParamSchema';
        $sections = require $root . '/policy_document_sections.php';
        self::assertSame('array', $sections['base_type'] ?? null);
        self::assertTrue((bool)($sections['sortable'] ?? false));
        self::assertTrue((bool)($sections['item_schema']['title']['i18n'] ?? false));
        self::assertTrue((bool)($sections['item_schema']['body']['i18n'] ?? false));
        self::assertFalse((bool)($sections['item_schema']['id']['i18n'] ?? true));

        $links = require $root . '/policy_document_links.php';
        self::assertSame('array', $links['base_type'] ?? null);
        self::assertTrue((bool)($links['sortable'] ?? false));
        self::assertTrue((bool)($links['item_schema']['label']['i18n'] ?? false));
        self::assertFalse((bool)($links['item_schema']['url']['i18n'] ?? true));
    }

    public function testWidgetDeclaresSchemasAndAmazonShell(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/content/policy-document/default.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('@widget.code {policy-document}', $src);
        self::assertStringContainsString('type="policy_document_sections"', $src);
        self::assertStringContainsString('type="policy_document_links"', $src);
        self::assertStringContainsString('PolicyDocumentDefaults', $src);
        self::assertStringContainsString('amazon-policy__hero', $src);
        self::assertStringContainsString('amazon-policy__toc', $src);
        self::assertStringContainsString('amazon-policy__panel', $src);
        self::assertStringContainsString('StorefrontHref::fragmentHref', $src);
        self::assertStringContainsString('SiteBrand', $src);
        self::assertStringContainsString('resolveFrontendSiteName', $src);
    }

    public function testWidgetCssOwnsAmazonShellTokens(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/css/widgets/widget-content-policy-document-default.css';
        self::assertFileExists($path);
        $css = (string)file_get_contents($path);
        self::assertStringContainsString('--weline-layout-content-max-width', $css);
        self::assertStringContainsString('amazon-policy__toc', $css);
        self::assertStringContainsString('position: sticky', $css);
        self::assertStringNotContainsString('1440px', $css);
        self::assertStringNotContainsString('top: 12rem', $css);
    }

    public function testPolicyLayoutsEmbedWidgetWithoutHardcodedClauses(): void
    {
        $dir = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/policy';
        foreach (['privacy', 'refund', 'cookie', 'shipping', 'disclaimer', 'term-condition', 'accessibility', 'default'] as $page) {
            $src = (string)file_get_contents($dir . '/' . $page . '.phtml');
            self::assertStringContainsString('name="policy-document"', $src, $page);
            self::assertStringContainsString('"page":"' . $page . '"', $src, $page);
            self::assertStringNotContainsString('1 / 我们收集哪些信息', $src, $page);
            self::assertStringNotContainsString('<style>', $src, $page);
        }

        $terms = (string)file_get_contents(dirname(__DIR__, 3) . '/view/theme/frontend/layouts/terms/default.phtml');
        self::assertStringContainsString('name="policy-document"', $terms);
        self::assertStringContainsString('"page":"terms"', $terms);
        self::assertStringNotContainsString('1 / 我们提供哪些服务', $terms);
        self::assertStringNotContainsString('<style>', $terms);
    }

    public function testTermsPageKeyAliasesTermConditionDefaults(): void
    {
        $terms = PolicyDocumentDefaults::forPage('terms');
        $tc = PolicyDocumentDefaults::forPage('term-condition');
        self::assertSame($tc['title'] ?? null, $terms['title'] ?? null);
        self::assertSame(count($tc['sections'] ?? []), count($terms['sections'] ?? []));
        self::assertGreaterThanOrEqual(8, count($terms['sections'] ?? []));
    }

    public function testDefaultsPreservePrivacyComplianceAnchorsAndCookieUrlKey(): void
    {
        $privacy = PolicyDocumentDefaults::forPage('privacy');
        self::assertSame('amazon', $privacy['variant'] ?? null);
        self::assertGreaterThanOrEqual(8, count($privacy['sections'] ?? []));
        $blob = json_encode($privacy, JSON_UNESCAPED_UNICODE);
        self::assertIsString($blob);
        self::assertStringContainsString('PayPal、Stripe', $blob);
        self::assertStringContainsString('中国境内', $blob);
        $cookieKeys = [];
        foreach ($privacy['related_links'] as $link) {
            if (($link['url_key'] ?? '') !== '') {
                $cookieKeys[] = (string)$link['url_key'];
            }
        }
        self::assertContains('cookie', $cookieKeys);
        self::assertNotContains('cookies', $cookieKeys);

        $refund = PolicyDocumentDefaults::forPage('refund');
        $refundBlob = json_encode($refund, JSON_UNESCAPED_UNICODE) ?: '';
        self::assertStringContainsString('质量问题、错发漏发、运输损坏', $refundBlob);
    }

    public function testWidgetLibraryRegistersLayoutXor(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        $tpl = 'Weline_Theme::theme/frontend/widgets/content/policy-document/default.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/widgets/content/policy-document/default.phtml'
        );
        self::assertStringContainsString('@widget.code {policy-document}', $src);
        self::assertStringContainsString('@widget.placement {layout}', $src);
        self::assertStringContainsString('@widget.default_injections {[]}', $src);
    }

    public function testHydrateParamsFillsEmptySectionsForEditorConfig(): void
    {
        $hydrated = PolicyDocumentDefaults::hydrateParams([
            'page' => 'default',
            'variant' => 'plain',
            'title' => '',
            'sections' => [],
            'related_links' => [],
        ]);
        self::assertSame('政策页面', $hydrated['title'] ?? null);
        self::assertGreaterThanOrEqual(5, count($hydrated['sections'] ?? []));
        self::assertSame('1 / 总则', $hydrated['sections'][0]['title'] ?? null);
        self::assertStringContainsString('本政策适用于', (string)($hydrated['sections'][0]['body'] ?? ''));

        $privacy = PolicyDocumentDefaults::hydrateParams([
            'page' => 'privacy',
            'variant' => 'amazon',
            'sections' => [],
        ]);
        self::assertSame('隐私政策', $privacy['title'] ?? null);
        self::assertTrue((bool)($privacy['show_legal_contact'] ?? false));
        self::assertGreaterThanOrEqual(8, count($privacy['sections'] ?? []));
        self::assertNotEmpty($privacy['related_links'] ?? []);

        // Non-empty sections must not be overwritten by catalog defaults.
        $kept = PolicyDocumentDefaults::hydrateParams([
            'page' => 'privacy',
            'title' => '自定义标题',
            'sections' => [
                ['id' => 'custom-1', 'title' => '自定义条款', 'body' => '<p>x</p>'],
            ],
            'related_links' => [
                ['label' => '自定义', 'enabled' => true, 'url_key' => 'faq'],
            ],
        ]);
        self::assertSame('自定义标题', $kept['title'] ?? null);
        self::assertCount(1, $kept['sections'] ?? []);
        self::assertSame('自定义条款', $kept['sections'][0]['title'] ?? null);
    }

    public function testRuntimeInlineAndEditorHydratePolicyDocumentParams(): void
    {
        $taglib = (string)file_get_contents(dirname(__DIR__, 4) . '/Widget/Taglib/Widget.php');
        self::assertStringContainsString('PolicyDocumentDefaults::hydrateParams', $taglib);
        $editor = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/ThemeEditor.php');
        self::assertStringContainsString('policy-document', $editor);
        self::assertStringContainsString('PolicyDocumentDefaults::hydrateParams', $editor);
        $paramRender = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Widget/ParamRender.php');
        self::assertStringContainsString('PolicyDocumentDefaults::hydrateParams', $paramRender);
        $slotRenderer = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/SlotRendererService.php');
        self::assertStringContainsString("widgetCode === 'policy-document'", $slotRenderer);
        self::assertStringContainsString('PolicyDocumentDefaults::hydrateParams', $slotRenderer);
    }
}
