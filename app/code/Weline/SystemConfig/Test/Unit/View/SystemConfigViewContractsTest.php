<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllSystemConfigViewContractsPass().
 */
final class SystemConfigViewContractsTest extends TestCase
{
    public function testAllSystemConfigViewContractsPass(): void
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
            'ConfigCenterGlobalEditable::testGlobalScopeIsWritableBaselineNotReadOnly' => function () {
            $this->assert_ConfigCenterGlobalEditable_testGlobalScopeIsWritableBaselineNotReadOnly();
        },
            'ConfigCenterSaveFormAction::testTemplatePostsToBackendPathNotAbsoluteBackendUrlTag' => function () {
            $this->assert_ConfigCenterSaveFormAction_testTemplatePostsToBackendPathNotAbsoluteBackendUrlTag();
        },
            'ConfigCenterSaveFormAction::testControllerRedirectsViaBackendRouteNotAbsoluteUrl' => function () {
            $this->assert_ConfigCenterSaveFormAction_testControllerRedirectsViaBackendRouteNotAbsoluteUrl();
        },
            'ConfigCenterSaveFormAction::testTemplateExposesGoogleOauthJsonFilePicker' => function () {
            $this->assert_ConfigCenterSaveFormAction_testTemplateExposesGoogleOauthJsonFilePicker();
        },
            'ConfigCenterSaveFormAction::testTemplateSupportsMultiselectAndCountryProviderMap' => function () {
            $this->assert_ConfigCenterSaveFormAction_testTemplateSupportsMultiselectAndCountryProviderMap();
        },
            'ConfigIndexImageField::testConfigCenterRendersImageAndFilePickers' => function () {
            $this->assert_ConfigIndexImageField_testConfigCenterRendersImageAndFilePickers();
        },
            'ConfigIndexThemeField::testConfigCenterUsesThemeFieldStructureForFiltersAndValues' => function () {
            $this->assert_ConfigIndexThemeField_testConfigCenterUsesThemeFieldStructureForFiltersAndValues();
        },
        ];
    }

private function assert_ConfigCenterGlobalEditable_testGlobalScopeIsWritableBaselineNotReadOnly(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/templates/backend/config/index.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('$isGlobalScope = $targetKind === \'global\'', $src);
    self::assertStringNotContainsString('$scopeReadOnly = $targetKind === \'global\'', $src);
    self::assertStringNotContainsString('Global 层仅查看继承值', $src);
    self::assertStringContainsString('Global 为全站基线配置', $src);
    self::assertStringContainsString('$fieldDisabled = $isLocked', $src);
    self::assertStringContainsString('<?php if (!empty($template[\'fields\'])): ?>', $src);
    self::assertDoesNotMatchRegularExpression('/if\s*\(\s*!empty\(\$template\[\'fields\'\]\)\s*&&\s*!\$scopeReadOnly\s*\)/', $src, 'Save actions must not be gated behind Global read-only.');
}

private function assert_ConfigCenterSaveFormAction_testTemplatePostsToBackendPathNotAbsoluteBackendUrlTag(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/templates/backend/config/index.phtml';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('getBackendUrlPath($postPath)', $src);
    self::assertStringContainsString('$formAction = \'\';', $src);
    self::assertStringContainsString('action="<?= htmlspecialchars($formAction, ENT_QUOTES, \'UTF-8\') ?>"', $src);
    self::assertStringNotContainsString('action="@backend-url{$postPath}"', $src);
}

private function assert_ConfigCenterSaveFormAction_testControllerRedirectsViaBackendRouteNotAbsoluteUrl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Controller/Backend/Config.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("\$this->redirect('weline_systemconfig/backend/config'", $src);
    self::assertStringContainsString('getBackendUrlPath(\'weline_systemconfig/backend/config\')', $src);
    self::assertStringNotContainsString('getBackendUrl(\'weline_systemconfig/backend/config\'', $src);
    self::assertStringContainsString('expandGoogleOAuthClientJsonImport', $src);
    self::assertStringContainsString('GoogleOAuthClientJsonImporter', $src);
    self::assertStringContainsString('readUploadedGoogleOAuthClientJson', $src);
    self::assertStringContainsString('isSensitiveUnchangedPlaceholder', $src);
    self::assertStringContainsString('validation_failed', $src);
    self::assertStringContainsString('errors', $src);
}

private function assert_ConfigCenterSaveFormAction_testTemplateExposesGoogleOauthJsonFilePicker(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/templates/backend/config/index.phtml';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('enctype="multipart/form-data"', $src);
    self::assertStringContainsString('data-w-system-config-save-form="1"', $src);
    self::assertStringContainsString('data-w-system-config-save-submit="1"', $src);
    self::assertStringContainsString('data-w-google-oauth-json-file=', $src);
    self::assertStringContainsString('google_oauth_client_json_file', $src);
    self::assertStringContainsString('import_file', $src);
    self::assertStringContainsString('form-actions--dock', $src);
    self::assertStringContainsString('data-w-system-config-save-dock="1"', $src);
    self::assertStringContainsString('is-active', $src);
    self::assertStringContainsString('wsc_save_dock_activated', $src);
    self::assertStringContainsString('data-w-system-config-reauth="1"', $src);
    self::assertStringContainsString('data-w-system-config-reauth-panel="1"', $src);
    self::assertStringContainsString('data-w-system-config-sensitive="1"', $src);
    self::assertStringContainsString('data-w-system-config-reauth-hint="1"', $src);
    $css = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/statics/css/system-config.css');
    self::assertStringContainsString('--backend-theme-sidebar-width', $css);
    self::assertStringContainsString('form-actions--dock', $css);
    self::assertStringContainsString('form-actions--dock:not(.is-active)', $css);
    self::assertStringContainsString('reauth-field:not([hidden])', $css);
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/statics/js/system-config-filter.js');
    self::assertStringContainsString('findValueControl', $js);
    self::assertStringContainsString('parseSelectedFile', $js);
    self::assertStringContainsString('syncReauthVisibility', $js);
    self::assertStringContainsString('formNeedsReauth', $js);
    self::assertStringContainsString('initSystemConfigSaveDock', $js);
    self::assertStringContainsString('syncSaveDocks', $js);
    $publishedJs = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-system-config.js');
    self::assertStringContainsString('syncReauthVisibility', $publishedJs);
    self::assertStringContainsString('initSystemConfigSaveDock', $publishedJs);
    self::assertStringContainsString('scrollGuideTargetIntoView', $publishedJs);
    self::assertStringContainsString('/* Weline UI source: js/system-config-guide.js */', $publishedJs);
    $publishedCss = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-system-config.css');
    self::assertStringContainsString('reauth-field:not([hidden])', $publishedCss);
    self::assertStringContainsString('form-actions--dock:not(.is-active)', $publishedCss);
    self::assertStringContainsString('system-config-collapsible-hint', $src);
    self::assertStringContainsString('w-system-config__hint-details', $src);
    self::assertStringContainsString('w-system-config__hint-details', $css);
    self::assertStringContainsString('w-system-config__hint-details', $publishedCss);
    self::assertStringContainsString('选择后立即解析填入下方 Client ID / Secret', $src);
    self::assertStringContainsString('data-w-google-oauth-json-status', $src);
    self::assertStringContainsString('当前后台登录密码', $src);
    self::assertStringContainsString('autocomplete="off"', $src);
    self::assertStringContainsString('data-lpignore="true"', $src);
}

private function assert_ConfigCenterSaveFormAction_testTemplateSupportsMultiselectAndCountryProviderMap(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/templates/backend/config/index.phtml';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("in_array(\$fieldType, ['multiselect', 'select_multi'], true)", $src);
    self::assertStringContainsString('country_provider_map', $src);
    self::assertStringContainsString('options-source', $src);
    self::assertStringContainsString('i18n:countries', $src);
    self::assertStringContainsString('$resolveFieldOptions', $src);
    self::assertStringContainsString('$optionsToSearchSelectJson', $src);
    self::assertStringContainsString('w:theme:search-select', $src);
    self::assertStringContainsString('data-w-map-row-template="1"', $src);
    self::assertStringNotContainsString('<template data-w-map-row-template', $src);
    self::assertStringContainsString('data-w-map-provider-host="1"', $src);
    self::assertStringContainsString('data-provider-options-json', $src);
    self::assertStringContainsString('selection="multi"', $src);
    self::assertStringContainsString('multi-levels="country"', $src);
    self::assertStringNotContainsString('class="w-select" name="values[', $src);
    self::assertStringContainsString('w:theme:address', $src);
    self::assertStringContainsString('data-w-system-config-address-countries', $src);
    self::assertStringContainsString('address_countries', $src);
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/statics/js/system-config-filter.js');
    self::assertStringContainsString('initSystemConfigCountryProviderMap', $js);
    self::assertStringContainsString('createCountryAddressRoot', $js);
    self::assertStringContainsString('ensureRowProvider', $js);
    self::assertStringContainsString('WelineThemeSearchSelect', $js);
    self::assertStringContainsString('data-map-incomplete', $js);
    self::assertStringContainsString('countryCodesFromHost', $js);
    self::assertStringContainsString('同一行多选国家共享供应商', $js);
    self::assertStringNotContainsString('enforceSingleMapCountry', $js);
    self::assertStringContainsString('unlockMapFieldInherit', $js);
    $publishedJs = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-system-config.js');
    self::assertStringContainsString('initSystemConfigCountryProviderMap', $publishedJs);
    self::assertStringContainsString('createCountryAddressRoot', $publishedJs);
    self::assertStringContainsString('ensureRowProvider', $publishedJs);
    self::assertStringContainsString('countryCodesFromHost', $publishedJs);
    self::assertStringContainsString('data-map-incomplete', $publishedJs);
    self::assertStringContainsString('scrollGuideTargetIntoView', $publishedJs);
    self::assertStringContainsString('w-system-config__map-row', $src);
    self::assertStringContainsString('data-w-system-config-map-value', $src);
    self::assertStringContainsString('<textarea', $src);
    $searchSelect = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Taglib/SearchSelect.php');
    self::assertStringContainsString('options-json', $searchSelect);
    self::assertStringContainsString('mountInto', $searchSelect);
    self::assertStringContainsString('is-multiple', $searchSelect);
    $service = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Service/SystemConfigCenterService.php');
    self::assertStringContainsString("'multiselect', 'select_multi', 'tags'", $service);
    $captcha = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Captcha/extends/module/Weline_SystemConfig/Config/backend/captcha.phtml');
    self::assertStringContainsString('type="select"', $captcha);
    self::assertStringContainsString('type="address_countries"', $captcha);
    self::assertStringContainsString('type="country_provider_map"', $captcha);
    self::assertStringContainsString('options-source="i18n:countries"', $captcha);
}

private function assert_ConfigIndexImageField_testConfigCenterRendersImageAndFilePickers(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/templates/backend/config/index.phtml';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString("in_array(\$fieldType, ['image', 'file'], true)", $content);
    self::assertStringContainsString('Weline\MediaManager\Block\WelineMedia::class', $content);
    self::assertStringContainsString('framework_view_process_block', $content);
    self::assertStringContainsString("__('选择图片')", $content);
    self::assertStringContainsString("path-global", $content);
    self::assertStringContainsString('lock-root-global', $content);
    self::assertStringContainsString('{website}', $content);
    self::assertStringContainsString('wscMediaPath', $content);
    self::assertStringContainsString("'identity_root' => 'config'", $content);
    self::assertStringContainsString("'identity_code' => \$fieldKey", $content);
    self::assertStringContainsString("'identity_scope' => \$wscIdentityScope", $content);
    self::assertStringContainsString("'strong_ref' => '1'", $content);
}

private function assert_ConfigIndexThemeField_testConfigCenterUsesThemeFieldStructureForFiltersAndValues(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/templates/backend/config/index.phtml';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('w-system-config__filters-disclosure', $content);
    self::assertStringContainsString('data-w-system-config-inherit-toggle', $content);
    self::assertStringContainsString('$adapterScopeParams', $content);
    self::assertStringContainsString('array_merge($routeParams, $queryParams)', $content);
    self::assertStringContainsString('emitFlashToast', $content);
    self::assertStringContainsString('adapterBlocked', $content);
    self::assertStringContainsString('w-system-config__filters-sticky', $content);
    self::assertStringContainsString('w-system-config__group-disclosure', $content);
    self::assertStringContainsString('data-w-disclosure-trigger', $content);
    self::assertStringContainsString('w-system-config__field-current', $content);
    self::assertStringNotContainsString('<th><lang>Scope</lang></th>', $content);
    self::assertStringContainsString('w-system-config__filters', $content);
    self::assertStringContainsString('Weline_Component::form/search-bar.phtml', $content);
    self::assertStringContainsString('$client_mode = true', $content);
    self::assertStringContainsString('wsc-search-empty', $content);
    self::assertStringContainsString('weline-system-config.js', $content);
    self::assertStringContainsString('class="w-field__control"', $content);
    self::assertStringNotContainsString('w-system-config__filter-submit', $content);
    self::assertStringNotContainsString('w-search-inline__control', $content);
    self::assertStringContainsString('data-testid="system-config-version-entry"', $content);
    self::assertStringContainsString('data-testid="system-config-version-panel"', $content);
    self::assertStringContainsString('data-wsc-version-toggle', $content);
    self::assertStringContainsString('id="wsc-version-panel"', $content);
    self::assertStringContainsString('<lang>版本管理</lang>', $content);
    self::assertStringNotContainsString('<lang>最近版本</lang>', $content);
    $entryPos = strpos($content, 'data-testid="system-config-version-entry"');
    $panelPos = strpos($content, 'id="wsc-version-panel"');
    $heroPos = strpos($content, 'w-system-config__hero');
    $filtersPos = strpos($content, 'w-system-config__filters-sticky');
    self::assertNotFalse($entryPos);
    self::assertNotFalse($panelPos);
    self::assertNotFalse($heroPos);
    self::assertNotFalse($filtersPos);
    self::assertGreaterThan($heroPos, $entryPos);
    self::assertGreaterThan($entryPos, $panelPos);
    self::assertLessThan($filtersPos, $panelPos, '版本管理面板必须在顶部 hero 与筛选区之间，不得追加在底部');
    self::assertStringNotContainsString("w-system-config__version-panel", $content);
}
}
