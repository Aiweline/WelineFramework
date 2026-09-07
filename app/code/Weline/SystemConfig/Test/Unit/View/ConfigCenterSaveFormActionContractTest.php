<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 配置中心表单 action / 保存回跳必须走后台 route 或同源 path，不能绝对 http 进前台 404。
 */
final class ConfigCenterSaveFormActionContractTest extends TestCase
{
    public function testTemplatePostsToBackendPathNotAbsoluteBackendUrlTag(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/templates/backend/config/index.phtml';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('getBackendUrlPath($postPath)', $src);
        self::assertStringContainsString('$formAction = \'\';', $src);
        self::assertStringContainsString('action="<?= htmlspecialchars($formAction, ENT_QUOTES, \'UTF-8\') ?>"', $src);
        self::assertStringNotContainsString('action="@backend-url{$postPath}"', $src);
    }

    public function testControllerRedirectsViaBackendRouteNotAbsoluteUrl(): void
    {
        $path = \dirname(__DIR__, 3) . '/Controller/Backend/Config.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString("\$this->redirect('weline_systemconfig/backend/config'", $src);
        self::assertStringContainsString('getBackendUrlPath(\'weline_systemconfig/backend/config\')', $src);
        self::assertStringNotContainsString(
            'getBackendUrl(\'weline_systemconfig/backend/config\'',
            $src,
        );
        self::assertStringContainsString('expandGoogleOAuthClientJsonImport', $src);
        self::assertStringContainsString('GoogleOAuthClientJsonImporter', $src);
        self::assertStringContainsString('readUploadedGoogleOAuthClientJson', $src);
        self::assertStringContainsString('isSensitiveUnchangedPlaceholder', $src);
    }

    public function testTemplateExposesGoogleOauthJsonFilePicker(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/templates/backend/config/index.phtml';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('enctype="multipart/form-data"', $src);
        self::assertStringContainsString('data-w-system-config-save-form="1"', $src);
        self::assertStringContainsString('data-w-system-config-save-submit="1"', $src);
        self::assertStringContainsString('data-w-google-oauth-json-file=', $src);
        self::assertStringContainsString('google_oauth_client_json_file', $src);
        self::assertStringContainsString('import_file', $src);
        self::assertStringContainsString('form-actions--dock', $src);
        self::assertStringContainsString('data-w-system-config-save-dock="1"', $src);
        self::assertStringContainsString('data-w-system-config-reauth="1"', $src);
        self::assertStringContainsString('data-w-system-config-reauth-panel="1"', $src);
        self::assertStringContainsString('data-w-system-config-sensitive="1"', $src);
        self::assertStringContainsString('data-w-system-config-reauth-hint="1"', $src);
        $css = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/css/system-config.css');
        self::assertStringContainsString('--backend-theme-sidebar-width', $css);
        self::assertStringContainsString('form-actions--dock', $css);
        self::assertStringContainsString('reauth-field:not([hidden])', $css);
        $js = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/system-config-filter.js');
        self::assertStringContainsString('findValueControl', $js);
        self::assertStringContainsString('parseSelectedFile', $js);
        self::assertStringContainsString('syncReauthVisibility', $js);
        self::assertStringContainsString('formNeedsReauth', $js);
        $publishedJs = (string)\file_get_contents(\dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-system-config.js');
        self::assertStringContainsString('syncReauthVisibility', $publishedJs);
        self::assertStringContainsString('scrollGuideTargetIntoView', $publishedJs);
        self::assertStringContainsString('/* Weline UI source: js/system-config-guide.js */', $publishedJs);
        $publishedCss = (string)\file_get_contents(\dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-system-config.css');
        self::assertStringContainsString('reauth-field:not([hidden])', $publishedCss);
        self::assertStringContainsString('选择后立即解析填入下方 Client ID / Secret', $src);
        self::assertStringContainsString('data-w-google-oauth-json-status', $src);
        self::assertStringContainsString('当前后台登录密码', $src);
        self::assertStringContainsString('autocomplete="off"', $src);
        self::assertStringContainsString('data-lpignore="true"', $src);
    }

    public function testTemplateSupportsMultiselectAndCountryProviderMap(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/templates/backend/config/index.phtml';
        $src = (string)\file_get_contents($path);
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

        $js = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/system-config-filter.js');
        self::assertStringContainsString('initSystemConfigCountryProviderMap', $js);
        self::assertStringContainsString('createCountryAddressRoot', $js);
        self::assertStringContainsString('ensureRowProvider', $js);
        self::assertStringContainsString('WelineThemeSearchSelect', $js);
        self::assertStringContainsString('data-map-incomplete', $js);
        self::assertStringContainsString('countryCodesFromHost', $js);
        self::assertStringContainsString('同一行多选国家共享供应商', $js);
        self::assertStringNotContainsString('enforceSingleMapCountry', $js);
        self::assertStringContainsString('unlockMapFieldInherit', $js);
        $publishedJs = (string)\file_get_contents(\dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-system-config.js');
        self::assertStringContainsString('initSystemConfigCountryProviderMap', $publishedJs);
        self::assertStringContainsString('createCountryAddressRoot', $publishedJs);
        self::assertStringContainsString('ensureRowProvider', $publishedJs);
        self::assertStringContainsString('countryCodesFromHost', $publishedJs);
        self::assertStringContainsString('data-map-incomplete', $publishedJs);
        self::assertStringContainsString('scrollGuideTargetIntoView', $publishedJs);
        self::assertStringContainsString('w-system-config__map-row', $src);
        self::assertStringContainsString('data-w-system-config-map-value', $src);
        self::assertStringContainsString('<textarea', $src);

        $searchSelect = (string)\file_get_contents(\dirname(__DIR__, 4) . '/Theme/Taglib/SearchSelect.php');
        self::assertStringContainsString('options-json', $searchSelect);
        self::assertStringContainsString('mountInto', $searchSelect);
        self::assertStringContainsString('is-multiple', $searchSelect);

        $service = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/SystemConfigCenterService.php');
        self::assertStringContainsString("'multiselect', 'select_multi', 'tags'", $service);

        $captcha = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Captcha/extends/module/Weline_SystemConfig/Config/backend/captcha.phtml'
        );
        self::assertStringContainsString('type="select"', $captcha);
        self::assertStringContainsString('type="address_countries"', $captcha);
        self::assertStringContainsString('type="country_provider_map"', $captcha);
        self::assertStringContainsString('options-source="i18n:countries"', $captcha);
    }
}
