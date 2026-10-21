<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;
use Weline\Websites\Taglib\BuildSite;
use Weline\Websites\Taglib\ChannelSelect;
use Weline\Websites\Taglib\DomainSelect;
use Weline\Websites\Taglib\RegistrarSelect;
use Weline\Websites\Taglib\SearchableCodeSelect;
use Weline\Websites\Taglib\StoreSelect;
use Weline\Websites\Taglib\WebsiteForm;
use Weline\Websites\Taglib\WebsiteSelect;

/**
 * Aggregated contract suite (merged from 6 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesTaglibContractsPass().
 */
final class WebsitesTaglibContractsTest extends TestCase
{
    public function testAllWebsitesTaglibContractsPass(): void
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
            'DomainSelectBackendApiHost::testDomainSelectResolvesParentBackendApiHostForAdminRequest' => function () {
            $this->assert_DomainSelectBackendApiHost_testDomainSelectResolvesParentBackendApiHostForAdminRequest();
        },
            'DomainSelectListScroll::testDomainSelectUsesScrollContainerAndWheelHandlerForList' => function () {
            $this->assert_DomainSelectListScroll_testDomainSelectUsesScrollContainerAndWheelHandlerForList();
        },
            'DomainSelectSelectedCapsule::testDomainSelectRendersSelectedDomainCapsulesAndHydratesOnLoad' => function () {
            $this->assert_DomainSelectSelectedCapsule_testDomainSelectRendersSelectedDomainCapsulesAndHydratesOnLoad();
        },
            'DomainSelectSelectedCapsule::testWebsiteFormPassesSelectedDomainNamesInsteadOfCountSummary' => function () {
            $this->assert_DomainSelectSelectedCapsule_testWebsiteFormPassesSelectedDomainNamesInsteadOfCountSummary();
        },
            'SearchableCodeSelectInlineScript::testSearchableCodeSelectInlineScriptIsComplete' => function () {
            $this->assert_SearchableCodeSelectInlineScript_testSearchableCodeSelectInlineScriptIsComplete();
        },
            'SearchableCodeSelectThemeTokens::testSearchableCodeSelectStylesPreferThemeTokensOverHardcodedLightPalette' => function () {
            $this->assert_SearchableCodeSelectThemeTokens_testSearchableCodeSelectStylesPreferThemeTokensOverHardcodedLightPalette();
        },
            'WebsiteSelectThemeTokens::testWebsiteSelectStylesPreferThemeTokensOverHardcodedLightPalette' => function () {
            $this->assert_WebsiteSelectThemeTokens_testWebsiteSelectStylesPreferThemeTokensOverHardcodedLightPalette();
        },
            'WebsiteSelectThemeTokens::testWebsiteSelectSearchIncludesNameAndDomain' => function () {
            $this->assert_WebsiteSelectThemeTokens_testWebsiteSelectSearchIncludesNameAndDomain();
        },
            'WebsiteSelectThemeTokens::testWebsiteSelectChevronActionsAreVerticallyCentered' => function () {
            $this->assert_WebsiteSelectThemeTokens_testWebsiteSelectChevronActionsAreVerticallyCentered();
        },
            'WebsitesSelectRuntimeCallback::testSiblingSelectTaglibsExposeRuntimeCallback' => function () {
            $this->assert_WebsitesSelectRuntimeCallback_testSiblingSelectTaglibsExposeRuntimeCallback();
        },
            'WebsitesSelectRuntimeCallback::testStoreSelectRuntimeEmitsHtmlWithoutPhpSource' => function () {
            $this->assert_WebsitesSelectRuntimeCallback_testStoreSelectRuntimeEmitsHtmlWithoutPhpSource();
        },
            'WebsitesSelectRuntimeCallback::testChannelSelectRuntimeEmitsHtmlWithoutPhpSource' => function () {
            $this->assert_WebsitesSelectRuntimeCallback_testChannelSelectRuntimeEmitsHtmlWithoutPhpSource();
        },
            'WebsitesSelectRuntimeCallback::testRegistrarSelectRuntimeEmitsHtmlWithoutPhpSource' => function () {
            $this->assert_WebsitesSelectRuntimeCallback_testRegistrarSelectRuntimeEmitsHtmlWithoutPhpSource();
        },
            'WebsitesSelectRuntimeCallback::testDomainSelectBuildMarkupEmitsHtmlWithoutPhpSource' => function () {
            $this->assert_WebsitesSelectRuntimeCallback_testDomainSelectBuildMarkupEmitsHtmlWithoutPhpSource();
        },
            'WebsitesSelectRuntimeCallback::testDomainSelectRuntimeCallbackResolvesScopeAttrs' => function () {
            $this->assert_WebsitesSelectRuntimeCallback_testDomainSelectRuntimeCallbackResolvesScopeAttrs();
        },
            'WebsitesSelectRuntimeCallback::testSearchableCodeSelectCompileStillPrefacesAttributeResolverPhp' => function () {
            $this->assert_WebsitesSelectRuntimeCallback_testSearchableCodeSelectCompileStillPrefacesAttributeResolverPhp();
        },
            'WebsitesSelectRuntimeCallback::testBuildSiteExposesRuntimeCallback' => function () {
            $this->assert_WebsitesSelectRuntimeCallback_testBuildSiteExposesRuntimeCallback();
        },
            'WebsitesSelectRuntimeCallback::testWebsiteFormExposesRuntimeCallback' => function () {
            $this->assert_WebsitesSelectRuntimeCallback_testWebsiteFormExposesRuntimeCallback();
        },
        ];
    }

private function assert_DomainSelectBackendApiHost_testDomainSelectResolvesParentBackendApiHostForAdminRequest(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Taglib/DomainSelect.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function resolveBackendApiHost()', $source);
    self::assertStringContainsString('var candidate=window;', $source);
    self::assertStringContainsString('while(candidate)', $source);
    self::assertStringContainsString('weline-worker-backend-bootstrap', $source);
    self::assertStringContainsString('querySelectorAll', $source);
    self::assertStringContainsString('candidate.location.origin!==window.location.origin', $source);
    self::assertStringContainsString('candidate=candidate.parent;', $source);
    self::assertStringContainsString('var host=resolveBackendApiHost();', $source);
    self::assertStringContainsString('host.Weline.adminRequest("websites"', $source);
    self::assertStringNotContainsString('return window.Weline.adminRequest', $source);
}

private function assert_DomainSelectListScroll_testDomainSelectUsesScrollContainerAndWheelHandlerForList(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Taglib/DomainSelect.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function ensureListScrollable()', $source);
    self::assertStringContainsString('function resolveListBudget()', $source);
    self::assertStringContainsString('function measureDropdownChrome()', $source);
    self::assertStringContainsString('dropdown.style.maxHeight = "none"', $source);
    self::assertStringContainsString('preferredHeight: panelHeight', $source);
    self::assertStringContainsString('weline-domain-select-actions', $source);
    self::assertStringContainsString('function onDropdownWheel(e)', $source);
    self::assertStringContainsString('listScroller.scrollTop = next', $source);
    self::assertStringContainsString('requestAnimationFrame(function(){ ensureListScrollable(); })', $source);
    self::assertStringContainsString('touch-action: pan-y', $source);
    self::assertStringContainsString('min-height: 0', $source);
}

private function assert_DomainSelectSelectedCapsule_testDomainSelectRendersSelectedDomainCapsulesAndHydratesOnLoad(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Taglib/DomainSelect.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("'selected-domains' => false", $source);
    self::assertStringContainsString('data-selected-domains=', $source);
    self::assertStringContainsString('weline-domain-select-tag', $source);
    self::assertStringContainsString('function hydrateInitialSelection()', $source);
    self::assertStringContainsString('hydrateInitialSelection();', $source);
    self::assertStringContainsString('function syncDomainValuesField()', $source);
    self::assertStringContainsString('syncDomainValuesField();', $source);
    self::assertStringContainsString('input[name="domain_values"]', $source);
    self::assertStringNotContainsString("__('已选择 %s 个域名')", $source);
    self::assertStringNotContainsString('$t_selected', $source);
}

private function assert_DomainSelectSelectedCapsule_testWebsiteFormPassesSelectedDomainNamesInsteadOfCountSummary(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/form.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('selected-domains="domainSelectSelectedDomainsEscaped"', $source);
    self::assertStringContainsString('website-id="domainSelectWebsiteId"', $source);
    self::assertStringContainsString("__('点击选择域名（可多选）')", $source);
    self::assertStringContainsString('name="domain_values"', $source);
    self::assertStringContainsString('selectedDomainNamesStr', $source);
    self::assertStringNotContainsString('<input type="hidden" name="domain_values" value="">', $source);
    self::assertStringNotContainsString("__('已选择 %{1} 个域名'", $source);
}

private function assert_SearchableCodeSelectThemeTokens_testSearchableCodeSelectStylesPreferThemeTokensOverHardcodedLightPalette(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Taglib/SearchableCodeSelect.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('--weline-theme-surface', $content);
    self::assertStringContainsString('--weline-theme-surface-raised', $content);
    self::assertStringContainsString('--weline-theme-surface-muted', $content);
    self::assertStringContainsString('--weline-theme-surface-hover', $content);
    self::assertStringContainsString('--weline-theme-text', $content);
    self::assertStringContainsString('--weline-theme-border', $content);
    self::assertStringContainsString('--weline-theme-primary', $content);
    self::assertStringContainsString('background:var(--weline-theme-surface,var(--backend-color-card-bg,#fff))', $content);
    self::assertStringNotContainsString('background:#f8fafc', $content);
    self::assertStringNotContainsString('background:#f1f5f9', $content);
    self::assertStringNotContainsString('color:#162033', $content);
}

private function assert_WebsiteSelectThemeTokens_testWebsiteSelectStylesPreferThemeTokensOverHardcodedLightPalette(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Taglib/WebsiteSelect.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('--weline-theme-surface', $content);
    self::assertStringContainsString('--weline-theme-surface-raised', $content);
    self::assertStringContainsString('--weline-theme-surface-muted', $content);
    self::assertStringContainsString('--weline-theme-surface-hover', $content);
    self::assertStringContainsString('--weline-theme-text', $content);
    self::assertStringContainsString('--weline-theme-border', $content);
    self::assertStringContainsString('--weline-theme-primary', $content);
    self::assertStringContainsString('background:var(--weline-theme-surface,var(--backend-color-card-bg,#fff))', $content);
    self::assertStringContainsString('background:var(--weline-theme-surface-raised,var(--weline-theme-surface,var(--backend-color-card-bg,#fff)))', $content);
    self::assertStringNotContainsString('background:#f8fafc', $content);
    self::assertStringNotContainsString('background:#f1f5f9', $content);
    self::assertStringNotContainsString('color:#162033', $content);
}

private function assert_WebsiteSelectThemeTokens_testWebsiteSelectSearchIncludesNameAndDomain(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Taglib/WebsiteSelect.php';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('搜索站点名称或域名', $content);
    self::assertStringContainsString('function optionSearchHaystack(item){', $content);
    self::assertStringContainsString('item.url, item.domain, item.code', $content);
    self::assertStringContainsString('optionSearchHaystack(item).indexOf(kw)', $content);
}

private function assert_WebsiteSelectThemeTokens_testWebsiteSelectChevronActionsAreVerticallyCentered(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Taglib/WebsiteSelect.php';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('.weline-website-actions{display:inline-flex;align-items:center;justify-content:center', $content);
    self::assertStringContainsString('align-self:center', $content);
    self::assertStringContainsString('.weline-website-chevron{display:inline-flex;align-items:center;justify-content:center', $content);
    self::assertStringContainsString('weline-website-chevron" aria-hidden="true">▾</span>', $content);
    self::assertStringContainsString('min-height:var(--weline-control-height);padding:0 var(--weline-space-3)', $content);
    self::assertDoesNotMatchRegularExpression('/\.weline-website-trigger\{[^}]*[^n-]height:var\(--weline-control-height\)/', $content);
}

/**
 * @return list<class-string>
 */
private function selectClasses_WebsitesSelectRuntimeCallback(): array
{
    return [WebsiteSelect::class, StoreSelect::class, ChannelSelect::class, RegistrarSelect::class, DomainSelect::class, WebsiteForm::class, BuildSite::class];
}

private function assert_WebsitesSelectRuntimeCallback_testSiblingSelectTaglibsExposeRuntimeCallback(): void
{
    foreach (self::selectClasses_WebsitesSelectRuntimeCallback() as $class) {
        self::assertTrue(\method_exists($class, 'runtimeCallback'), $class . ' must expose runtimeCallback');
    }
}

private function assert_WebsitesSelectRuntimeCallback_testStoreSelectRuntimeEmitsHtmlWithoutPhpSource(): void
{
    $bag = ['storeValue' => 'main', 'storeOptions' => \json_encode([['value' => 'main', 'label' => 'Main Store', 'meta' => 'main']], JSON_UNESCAPED_UNICODE) ?: '[]'];
    $template = $this->mockTemplate_WebsitesSelectRuntimeCallback($bag);
    $html = StoreSelect::runtimeCallback()($template, 'tag-self-close', ['id' => 'runtime-store-select', 'name' => 'store_code', 'value' => 'storeValue', 'options' => 'storeOptions', 'allow-empty' => 'true', 'empty-label' => 'Website 层'], '');
    self::assertStringContainsString('data-component="store-select"', $html);
    self::assertStringContainsString('runtime-store-select_wrapper', $html);
    self::assertStringContainsString('Main Store', $html);
    self::assertStringNotContainsString('<?php', $html);
    self::assertStringNotContainsString('Weline_Taglib_resolve', $html);
    self::assertStringNotContainsString('$Taglib__', $html);
}

private function assert_WebsitesSelectRuntimeCallback_testChannelSelectRuntimeEmitsHtmlWithoutPhpSource(): void
{
    $bag = ['channelValue' => 'web', 'channelOptions' => \json_encode([['value' => 'web', 'label' => 'Web Channel', 'meta' => 'web']], JSON_UNESCAPED_UNICODE) ?: '[]'];
    $template = $this->mockTemplate_WebsitesSelectRuntimeCallback($bag);
    $html = ChannelSelect::runtimeCallback()($template, 'tag-self-close', ['id' => 'runtime-channel-select', 'name' => 'channel_code', 'value' => 'channelValue', 'options' => 'channelOptions'], '');
    self::assertStringContainsString('data-component="channel-select"', $html);
    self::assertStringContainsString('Web Channel', $html);
    self::assertStringNotContainsString('<?php', $html);
    self::assertStringNotContainsString('$Taglib__', $html);
}

private function assert_WebsitesSelectRuntimeCallback_testRegistrarSelectRuntimeEmitsHtmlWithoutPhpSource(): void
{
    $bag = ['registrarValue' => 'godaddy', 'registrarOptions' => \json_encode([['value' => 'godaddy', 'label' => 'GoDaddy', 'group' => 'US']], JSON_UNESCAPED_UNICODE) ?: '[]'];
    $template = $this->mockTemplate_WebsitesSelectRuntimeCallback($bag);
    $html = RegistrarSelect::runtimeCallback()($template, 'tag-self-close', ['id' => 'runtime-registrar-select', 'name' => 'registrar_id', 'value' => 'registrarValue', 'options' => 'registrarOptions', 'empty-label' => '请选择服务商'], '');
    self::assertStringContainsString('runtime-registrar-select_wrapper', $html);
    self::assertStringNotContainsString('<?php', $html);
    self::assertStringNotContainsString('$Taglib__', $html);
}

private function assert_WebsitesSelectRuntimeCallback_testDomainSelectBuildMarkupEmitsHtmlWithoutPhpSource(): void
{
    $html = DomainSelect::buildMarkup(['id' => 'runtime-domain-select', 'name' => 'domain', 'value' => '', 'display' => '请选择域名', 'multiple' => 'false', 'site-ready-only' => 'true', 'url' => 'websites/backend/api/domain-pool']);
    self::assertStringContainsString('runtime-domain-select_wrapper', $html);
    self::assertStringContainsString('请选择域名', $html);
    self::assertStringNotContainsString('<?php', $html);
    self::assertStringNotContainsString('$Taglib__', $html);
    self::assertStringNotContainsString('Weline_Taglib_resolve', $html);
}

private function assert_WebsitesSelectRuntimeCallback_testDomainSelectRuntimeCallbackResolvesScopeAttrs(): void
{
    $bag = ['domainValue' => 'example.com', 'domainDisplay' => 'example.com'];
    $template = $this->mockTemplate_WebsitesSelectRuntimeCallback($bag);
    $html = DomainSelect::runtimeCallback()($template, 'tag-self-close', ['id' => 'rt-domain', 'name' => 'domain', 'value' => 'domainValue', 'display' => 'domainDisplay'], '');
    self::assertStringContainsString('rt-domain_wrapper', $html);
    self::assertStringContainsString('example.com', $html);
    self::assertStringNotContainsString('<?php', $html);
}

private function assert_WebsitesSelectRuntimeCallback_testSearchableCodeSelectCompileStillPrefacesAttributeResolverPhp(): void
{
    $php = SearchableCodeSelect::render(['id' => 'compile-store', 'name' => 'store_code', 'class' => '', 'style' => '', 'form' => '', 'on-change' => '', 'allow-empty' => true, 'clearable' => true, 'component' => 'store-select', 'api-ns' => 'WelineStoreSelect', 'default-empty-label' => 'Website 层', 'default-placeholder' => '搜索', 'not-found' => '未找到', 'attributes' => ['id' => 'compile-store', 'value' => 'storeValue', 'options' => 'storeOptions']]);
    self::assertStringContainsString('<?php', $php);
    self::assertStringContainsString('SearchableCodeSelect::buildMarkup', $php);
}

private function assert_WebsitesSelectRuntimeCallback_testBuildSiteExposesRuntimeCallback(): void
{
    self::assertTrue(\method_exists(BuildSite::class, 'runtimeCallback'));
    self::assertTrue(\method_exists(BuildSite::class, 'buildMarkup'));
}

private function assert_WebsitesSelectRuntimeCallback_testWebsiteFormExposesRuntimeCallback(): void
{
    self::assertTrue(\method_exists(WebsiteForm::class, 'runtimeCallback'));
    self::assertTrue(\method_exists(WebsiteForm::class, 'buildMarkup'));
}

/**
 * @param array<string, mixed> $bag
 */
private function mockTemplate_WebsitesSelectRuntimeCallback(array $bag): Template
{
    $template = $this->createMock(Template::class);
    $template->method('getData')->willReturnCallback(static function (string $key = '', $index = null) use (&$bag): mixed {
        unset($index);
        if ($key === '') {
            return $bag;
        }
        return $bag[$key] ?? null;
    });
    return $template;
}

    /**
     * 历史回归：setValue() 之后的脚本生成行曾被截断，导致所有使用
     * <w:websites:store:select> 的后台页面抛 "Unexpected token ')'" 的 pageerror。
     * 这里锁住生成的内联脚本必须完整、绑定触发器、花括号配平。
     */
    private function assert_SearchableCodeSelectInlineScript_testSearchableCodeSelectInlineScriptIsComplete(): void
    {
        $markup = SearchableCodeSelect::buildMarkup([
            'id' => 'scs-contract-test',
            'name' => 'store_id',
            'options' => [['value' => '1', 'label' => 'Test Store']],
            'allow-empty' => true,
            'clearable' => true,
        ]);
        self::assertMatchesRegularExpression('/<script>\(function\(\)\{.*?\}\)\(\);<\/script>/s', $markup);
        preg_match('/<script>(.*?)<\/script>/s', $markup, $matches);
        $js = trim((string)($matches[1] ?? ''));
        self::assertStringContainsString('trigger.addEventListener("click"', $js, 'Select 脚本必须绑定触发器点击事件（脚本被截断的历史回归）。');
        self::assertStringContainsString('function renderList(', $js);
        self::assertStringEndsWith('})();', $js, 'Select 内联脚本必须以 IIFE 收尾。');
        self::assertSame(
            0,
            substr_count($js, '{') - substr_count($js, '}'),
            'Select 内联脚本花括号必须配平（setValue 缺少闭合大括号会导致页面 pageerror）。'
        );
    }
}
