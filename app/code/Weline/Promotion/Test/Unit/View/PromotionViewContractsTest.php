<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllPromotionViewContractsPass().
 */
final class PromotionViewContractsTest extends TestCase
{
    public function testAllPromotionViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'HeaderDealsLinkWidget::testHeaderDealsLinkRegistersHomepageDefaultInjection' => function () {
            $this->assert_HeaderDealsLinkWidget_testHeaderDealsLinkRegistersHomepageDefaultInjection();
        },
            'HeaderDealsLinkWidget::testHeaderDealsLinkTemplateUsesPromotionDealsRoute' => function () {
            $this->assert_HeaderDealsLinkWidget_testHeaderDealsLinkTemplateUsesPromotionDealsRoute();
        },
            'PromotionDeskIndexTemplate::testDeskTemplateDoesNotUseHtmlDdTag' => function () {
            $this->assert_PromotionDeskIndexTemplate_testDeskTemplateDoesNotUseHtmlDdTag();
        },
            'PromotionDeskIndexTemplate::testDeskTemplateCompilesWithoutDdTagCollision' => function () {
            $this->assert_PromotionDeskIndexTemplate_testDeskTemplateCompilesWithoutDdTagCollision();
        },
            'PromotionThemeFormTemplate::testFormUsesActivityThemeVariableNotLayoutTheme' => function () {
            $this->assert_PromotionThemeFormTemplate_testFormUsesActivityThemeVariableNotLayoutTheme();
        },
            'PromotionThemeFormTemplate::testControllerAssignsActivityTheme' => function () {
            $this->assert_PromotionThemeFormTemplate_testControllerAssignsActivityTheme();
        },
            'PromotionThemeFormTemplate::testFormEmbedsLocalModelTranslationLabels' => function () {
            $this->assert_PromotionThemeFormTemplate_testFormEmbedsLocalModelTranslationLabels();
        },
            'PromotionThemeIndexTemplate::testIndexTemplateHasScopeFilterControls' => function () {
            $this->assert_PromotionThemeIndexTemplate_testIndexTemplateHasScopeFilterControls();
        },
            'PromotionThemeIndexTemplate::testControllerIndexAssignsScopeFilterData' => function () {
            $this->assert_PromotionThemeIndexTemplate_testControllerIndexAssignsScopeFilterData();
        },
            'ThemeScheduleWindowFormTemplate::testThemeFormExposesUtcWindowFields' => function () {
            $this->assert_ThemeScheduleWindowFormTemplate_testThemeFormExposesUtcWindowFields();
        },
        ];
    }

private function assert_HeaderDealsLinkWidget_testHeaderDealsLinkRegistersHomepageDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/extends/module/Weline_Widget/Weline_Promotion/widget.php';
    $tpl = 'Weline_Promotion::templates/frontend/widgets/header-deals-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/templates/frontend/widgets/header-deals-link.phtml');
    self::assertStringContainsString('@widget.code {header-deals-link}', $src);
    self::assertStringContainsString('@widget.slot {header-nav-extensions}', $src);
    self::assertStringContainsString('"slot":"header-nav-extensions"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_HeaderDealsLinkWidget_testHeaderDealsLinkTemplateUsesPromotionDealsRoute(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/templates/frontend/widgets/header-deals-link.phtml');
    self::assertStringContainsString('data-testid="header-deals-link"', $template);
    self::assertStringContainsString("@url{'promotion/deals'}", $template);
    self::assertStringContainsString('@widget.slot {header-nav-extensions}', $template);
    self::assertStringNotContainsString("'/promotion/deals'", $template);
}

private const TEMPLATE_RELATIVE_PATH_PromotionDeskIndexTemplate = '/view/templates/backend/promotion/index.phtml';

private function assert_PromotionDeskIndexTemplate_testDeskTemplateDoesNotUseHtmlDdTag(): void
{
    $templatePath = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . self::TEMPLATE_RELATIVE_PATH_PromotionDeskIndexTemplate;
    $source = (string) file_get_contents($templatePath);
    self::assertStringNotContainsString('<dd>', $source);
}

private function assert_PromotionDeskIndexTemplate_testDeskTemplateCompilesWithoutDdTagCollision(): void
{
    /** @var Taglib $taglib */
    $taglib = ObjectManager::getInstance(Taglib::class);
    /** @var Template $template */
    $template = ObjectManager::getInstance(Template::class);
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . self::TEMPLATE_RELATIVE_PATH_PromotionDeskIndexTemplate);
    $compiled = $taglib->compile($template, $source, 'promotion-desk-index-contract-' . uniqid('', true) . '.phtml');
    self::assertStringNotContainsString('<?=dd(', $compiled);
    self::assertStringContainsString('w-backend-page__heading', $compiled);
    self::assertStringContainsString('w-card', $compiled);
    self::assertStringNotContainsString('<dd>', $compiled);
}

private function assert_PromotionThemeFormTemplate_testFormUsesActivityThemeVariableNotLayoutTheme(): void
{
    $path = __DIR__ . '/../../../view/templates/backend/promotion/theme/form.phtml';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString("getData('activityTheme')", $content);
    self::assertStringNotContainsString("getData('theme')", $content);
}

private function assert_PromotionThemeFormTemplate_testControllerAssignsActivityTheme(): void
{
    $path = __DIR__ . '/../../../Controller/Backend/Theme.php';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString("assign('activityTheme'", $content);
    self::assertStringNotContainsString("assign('theme', is_array(\$theme)", $content);
}

private function assert_PromotionThemeFormTemplate_testFormEmbedsLocalModelTranslationLabels(): void
{
    $formPath = __DIR__ . '/../../../view/templates/backend/promotion/theme/form.phtml';
    $partialPath = __DIR__ . '/../../../view/templates/backend/promotion/theme/partials/local-field-label.phtml';
    $formContent = (string) file_get_contents($formPath);
    $partialContent = (string) file_get_contents($partialPath);
    self::assertStringContainsString('promotion-theme-local-label', $formContent);
    self::assertStringContainsString('Weline_I18n::css/local-translation.css', $formContent);
    self::assertStringContainsString('promotion-theme-form.css', $formContent);
    self::assertStringContainsString('PromotionActivityThemeLocal', $formContent);
    self::assertStringContainsString('<local', $formContent);
    self::assertStringContainsString('id="activityTheme.id"', $formContent);
    self::assertStringContainsString('保存后可翻译多语言', $formContent);
    self::assertStringContainsString('promotion-theme-form__sticky-bar', $formContent);
    self::assertStringContainsString('promotion-theme-form__sticky-shell', $formContent);
    self::assertStringContainsString('promotion-theme-form__sticky-inner', $formContent);
    self::assertStringContainsString('mode="bulk"', $formContent);
    self::assertStringContainsString('bulk-input="promotion-theme-nav"', $formContent);
    self::assertStringNotContainsString('bulk-fields', $formContent);
    self::assertStringNotContainsString('data-promotion-theme-bulk-ai', $formContent);
    self::assertStringNotContainsString('taglib-local-ai-bulk', $formContent);
    self::assertStringContainsString('一键 AI 翻译', $formContent);
    self::assertStringContainsString('allow-empty="false"', $formContent);
    self::assertStringContainsString('cross-website="false"', $formContent);
    self::assertStringContainsString('一站一活动', $formContent);
    self::assertStringNotContainsString('empty-label="@lang(全部 Website)"', $formContent);
    foreach (['nav_label', 'page_title', 'hero_lede', 'entry_title', 'entry_action_label', 'entry_subtitle'] as $field) {
        self::assertStringContainsString('field="' . $field . '"', $formContent, "missing local field hook: {$field}");
    }
    self::assertStringContainsString('PromotionActivityThemeLocal', $partialContent);
    self::assertStringContainsString('<local', $partialContent);
}

private function assert_PromotionThemeIndexTemplate_testIndexTemplateHasScopeFilterControls(): void
{
    $path = __DIR__ . '/../../../view/templates/backend/promotion/theme/index.phtml';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="promotion-theme-scope-filter"', $content);
    self::assertStringContainsString('w:websites:website:select', $content);
    self::assertStringContainsString('w:websites:store:select', $content);
    self::assertStringContainsString('w:websites:channel:select', $content);
    self::assertStringContainsString('scope_label', $content);
    self::assertStringContainsString('promotionThemeIndexReloadScope', $content);
}

private function assert_PromotionThemeIndexTemplate_testControllerIndexAssignsScopeFilterData(): void
{
    $path = __DIR__ . '/../../../Controller/Backend/Theme.php';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString("assign('filter_website_id'", $content);
    self::assertStringContainsString("assign('scope_filter_active'", $content);
    self::assertStringContainsString('describeScope', $content);
    self::assertStringContainsString('listForBackend($filters)', $content);
}

private function assert_ThemeScheduleWindowFormTemplate_testThemeFormExposesUtcWindowFields(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/templates/backend/promotion/theme/form.phtml');
    self::assertIsString($src);
    self::assertStringContainsString('promotion-theme-starts-at', $src);
    self::assertStringContainsString('promotion-theme-ends-at', $src);
    self::assertStringContainsString('按站点时区', $src);
    self::assertStringContainsString('utcSqlToLocalInput', $src);
}
}
