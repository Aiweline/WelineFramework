<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 邮件模板后台 UI + send 路径契约（控制器/视图/菜单/provider/sendlog）。
 */
final class MailTemplateUiSendContractTest extends TestCase
{
    public function testBackendUiAndMenuExist(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/Controller/Backend/Template.php');
        self::assertFileExists($root . '/view/templates/Backend/Template/listing.phtml');
        self::assertFileExists($root . '/view/templates/Backend/Template/edit.phtml');
        self::assertFileExists($root . '/view/Backend/Template/listing.phtml');
        self::assertFileExists($root . '/view/Backend/Template/edit.phtml');

        $menu = (string)file_get_contents($root . '/etc/backend/menu.xml');
        self::assertStringContainsString('system_smtp_template', $menu);
        self::assertStringContainsString('渠道管理', $menu);
        self::assertStringContainsString('smtp/backend/template/listing', $menu);
        self::assertStringContainsString('order="3"', $menu);

        $controller = (string)file_get_contents($root . '/Controller/Backend/Template.php');
        self::assertStringContainsString('Weline_Smtp::system_smtp_template', $controller);
        self::assertStringContainsString('SystemConfigTargetScopeService', $controller);
        self::assertStringContainsString('MailTemplateSeeder', $controller);
        self::assertStringContainsString('findInheritFrom', $controller);
        self::assertStringContainsString('ActiveLocaleCodeProvider', $controller);
        self::assertStringContainsString('open_channel', $controller);
        self::assertStringContainsString('filter_q', $controller);
        self::assertStringContainsString('filter_locales', $controller);
        self::assertStringContainsString('listingReturnState', $controller);
        self::assertStringContainsString('withListingReturn', $controller);
        self::assertStringContainsString('listing_return', $controller);
        self::assertStringContainsString("'locales'", $controller);
        self::assertStringContainsString("'present_count'", $controller);
        self::assertStringContainsString('getInstalledActiveCodes', $controller);
        self::assertStringContainsString('function getEdit', $controller);
        self::assertStringContainsString('postSave', $controller);
        self::assertStringContainsString('postReset', $controller);
        self::assertStringContainsString('postCopy', $controller);
        self::assertStringContainsString('SOURCE_CUSTOM', $controller);
        self::assertStringContainsString('normalizeEmailHtml', $controller);
        self::assertStringContainsString('array_key_exists($key, $post)', $controller);
        self::assertStringContainsString('website_code', $controller);

        $listingStub = (string)file_get_contents($root . '/view/templates/Backend/Template/listing.phtml');
        self::assertStringContainsString('view/Backend/Template/listing.phtml', $listingStub);
        self::assertStringNotContainsString('data-testid="smtp-channel-listing"', $listingStub);

        $listing = (string)file_get_contents($root . '/view/Backend/Template/listing.phtml');
        self::assertStringContainsString('data-testid="smtp-channel-listing"', $listing);
        self::assertStringContainsString('data-testid="smtp-channel-listing-header"', $listing);
        self::assertStringContainsString('渠道管理', $listing);
        self::assertStringContainsString('<w:scope', $listing);
        self::assertStringContainsString('data-testid="smtp-channel-list"', $listing);
        self::assertStringContainsString('<details class="w-smtp-channel"', $listing);
        self::assertStringContainsString('smtp-channel-locale-table', $listing);
        self::assertStringContainsString('smtp-channel-inherit', $listing);
        self::assertStringContainsString('data-testid="smtp-channel-search"', $listing);
        self::assertStringContainsString('搜索渠道', $listing);
        self::assertStringContainsString('按范围筛选模板', $listing);
        self::assertStringContainsString('data-testid="smtp-locale-tag-filter"', $listing);
        self::assertStringContainsString('w:i18n:language:select', $listing);
        self::assertStringContainsString('multiple="true"', $listing);
        self::assertStringContainsString('id="smtp-locale-filter"', $listing);
        self::assertStringContainsString('语言标签过滤', $listing);
        self::assertStringContainsString('网站默认语', $listing);
        self::assertStringContainsString('data-initial-q', $listing);
        self::assertStringContainsString('data-initial-locales', $listing);
        self::assertStringContainsString('data-focus-locale', $listing);
        self::assertStringContainsString('focus_locale', $listing);
        self::assertStringContainsString('scrollIntoView', $listing);
        self::assertStringContainsString('writeListingParams', $listing);
        self::assertStringNotContainsString('data-smtp-locale-tag', $listing);
        self::assertStringNotContainsString('smtp-template-locale', $listing);
        self::assertStringNotContainsString('view/templates/Backend/Template/listing.phtml', $listing);

        $resolver = (string)file_get_contents($root . '/Service/MailTemplateResolver.php');
        self::assertStringContainsString('default_LANGUAGE_CODE', $resolver);
        self::assertStringContainsString('resolveWebsiteDefaultLanguage', $resolver);

        $editStub = (string)file_get_contents($root . '/view/templates/Backend/Template/edit.phtml');
        self::assertStringContainsString('view/Backend/Template/edit.phtml', $editStub);
        self::assertStringNotContainsString('w:editor-manager', $editStub);

        $edit = (string)file_get_contents($root . '/view/Backend/Template/edit.phtml');
        // 自研可视化：禁止再挂 CKEditor / editor-manager
        self::assertStringNotContainsString('w:editor-manager', $edit);
        self::assertStringNotContainsString('ckeditorInstance', $edit);
        self::assertStringNotContainsString('<w:editor', $edit);
        self::assertDoesNotMatchRegularExpression('/<textarea[^>]*\seditor\b/', $edit);
        self::assertStringContainsString('data-testid="smtp-template-body-source"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-editor-shell"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-edit-toolbar"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-back-listing"', $edit);
        self::assertStringContainsString('← 返回渠道管理', $edit);
        self::assertStringContainsString("'channel' => \$channel", $edit);
        self::assertStringContainsString('smtp/backend/template/listing', $edit);
        self::assertStringContainsString('listing_return', $edit);
        self::assertStringContainsString('data-testid="smtp-template-return-q"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-return-locales"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-return-focus-locale"', $edit);
        self::assertStringContainsString('name="focus_locale"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-edit-scope"', $edit);
        self::assertStringContainsString('<w:scope', $edit);
        self::assertStringContainsString('id="smtp-template-edit-scope"', $edit);
        self::assertStringContainsString('searchParams.set(\'target_scope\'', $edit);
        self::assertStringContainsString('searchParams.delete(\'template_id\')', $edit);
        self::assertStringContainsString('searchParams.delete(\'website_code\')', $edit);
        self::assertStringContainsString('smtp-tpl-locale_wrapper', $edit);
        self::assertStringContainsString('data-w-component="language-select"', $edit);
        self::assertStringContainsString('window.location.assign', $edit);
        self::assertStringContainsString('el.matches(\'input[name="target_scope"]\')', $edit);
        self::assertStringNotContainsString('<code class="w-text" data-size="sm"><?= $h($selectedScope) ?></code>', $edit);
        self::assertStringContainsString('data-testid="smtp-visual-editor"', $edit);
        self::assertStringContainsString('data-testid="smtp-ve-region-tabs"', $edit);
        self::assertStringContainsString('data-testid="smtp-ve-tab-all"', $edit);
        self::assertStringContainsString('data-region="all"', $edit);
        self::assertStringContainsString('isAllRegionView', $edit);
        self::assertStringContainsString('resolveRegionRoot', $edit);
        self::assertStringContainsString('data-testid="smtp-ve-style-toolbar"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-vars-content"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-vars-identity"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-vars-tone"', $edit);
        self::assertStringContainsString('w-smtp-visual-editor', $edit);
        self::assertStringContainsString('shell_regions_json', $edit);
        self::assertStringNotContainsString('ckeditorInstance', $edit);
        self::assertStringNotContainsString('allowSourceEditorSync', $edit);
        self::assertStringNotContainsString('bindSourceEditor', $edit);
        self::assertStringContainsString('data-testid="smtp-template-vars"', $edit);
        self::assertStringContainsString('data-var-code', $edit);
        self::assertStringContainsString("'{' + '{var.'", $edit);
        self::assertStringContainsString("{{var.' . \$code", $edit);
        self::assertStringContainsString('data-testid="smtp-template-live-preview"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-preview-iframe"', $edit);
        self::assertStringContainsString('sandbox="allow-same-origin"', $edit);
        $controllerSrc = (string)file_get_contents($root . '/Controller/Backend/Template.php');
        self::assertStringContainsString('preview_shell_html', $controllerSrc);
        self::assertStringContainsString('preview_samples', $controllerSrc);
        self::assertStringContainsString('buildPreviewSamples', $controllerSrc);
        self::assertStringContainsString('buildPreviewSamples($mergedVars, $editScope, $editLocale)', $controllerSrc);
        self::assertStringContainsString('MailShellRegionStore', $controllerSrc);
        self::assertStringContainsString('MailThemeFontCatalog', $controllerSrc);
        self::assertStringContainsString('shell_regions_json', $controllerSrc);
        self::assertStringContainsString('readMailChannelFromRequest', $controllerSrc);
        self::assertStringContainsString("str_contains(\$value, '::')", $controllerSrc);
        self::assertStringContainsString('name="mail_channel"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-mail-channel"', $edit);
        $brandSrc = (string)file_get_contents($root . '/Service/MailBrandContextService.php');
        self::assertStringContainsString('function buildPreviewSamples', $brandSrc);
        self::assertStringContainsString('localizePreviewText', $brandSrc);
        self::assertStringContainsString('scopeLockPreviewSample', $brandSrc);
        self::assertStringContainsString('data-weline-mail-var', $edit);
        self::assertStringContainsString('smtp-ve-var-hint', $edit);
        self::assertStringContainsString('applyTextColor', $edit);
        self::assertStringContainsString('pickStyleTarget', $edit);
        self::assertStringContainsString('w-smtp-ve-selected', $edit);
        self::assertStringContainsString('outline-offset:-6px', $edit);
        self::assertStringContainsString('is-floating', $edit);
        self::assertStringContainsString('positionFloatingToolbar', $edit);
        self::assertStringContainsString('hideFloatingToolbar', $edit);
        self::assertStringContainsString('clearActiveSelection', $edit);
        self::assertStringNotContainsString('allowSourceEditorSync', $edit);
        self::assertStringNotContainsString('bindSourceEditor', $edit);
        self::assertStringContainsString('smtp-template-advanced', $edit);
        self::assertStringContainsString('width:1%', $edit);
        self::assertStringContainsString('min-width:4.5em', $edit);
        self::assertStringContainsString('renderPreviewIfBlocks', $edit);
        self::assertStringContainsString('restoreMailIfBlocks', $edit);
        self::assertStringContainsString('scrubPreviewDebris', $edit);
        self::assertStringContainsString('data-weline-mail-if', $edit);
        self::assertStringContainsString('getViewportRectForEl', $edit);
        // 可视化须展示邮件配置三区背景图，禁止再强制 none
        self::assertStringNotContainsString('background-image:none', $edit);
        self::assertStringContainsString('background-size:cover', $edit);
        self::assertStringContainsString('overflow-wrap:break-word', $edit);
        self::assertStringNotContainsString('overflow-wrap:anywhere', $edit);
        self::assertStringContainsString('smtp-ve-text-color-field', $edit);
        self::assertStringContainsString('smtp-ve-color-hex', $edit);
        self::assertStringContainsString('文字颜色', $edit);
        self::assertStringContainsString('w-smtp-ve-color-input', $edit);
        self::assertStringContainsString("'{' + '{MAIL_BODY}}'", $edit);
        self::assertStringContainsString('form="smtp-template-save-form"', $edit);
        self::assertStringContainsString('form="smtp-template-reset-form"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-copy"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-reset"', $edit);
        self::assertStringContainsString('data-testid="smtp-template-save"', $edit);
        self::assertStringNotContainsString('view/templates/Backend/Template/edit.phtml', $edit);
        self::assertDoesNotMatchRegularExpression('/<textarea[^>]*\seditor\b/', $edit);
    }

    public function testSendPathUsesTemplateServices(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = (string)file_get_contents($root . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
        self::assertStringContainsString('MailTemplateResolver', $provider);
        self::assertStringContainsString('MailTemplateRenderer', $provider);
        self::assertStringContainsString('MailTemplateSendContext', $provider);
        self::assertStringContainsString('use_template', $provider);
        self::assertStringContainsString("'vars'", $provider);
        self::assertStringContainsString('override_subject', $provider);
        self::assertStringContainsString('override_content', $provider);
        self::assertStringContainsString("'__template_id'", $provider);
        self::assertStringContainsString("'__locale'", $provider);
        self::assertStringContainsString('mergeInto($vars, $storageScope, $resolvedLocale', $provider);
        self::assertStringContainsString("'locale'", $provider);
        self::assertStringContainsString("'scope'", $provider);
        self::assertStringContainsString("'channel'", $provider);

        $log = (string)file_get_contents($root . '/Model/SmtpSendLog.php');
        self::assertStringContainsString("schema_fields_TEMPLATE_ID = 'template_id'", $log);
        self::assertStringContainsString("schema_fields_LOCALE = 'locale'", $log);

        $sender = (string)file_get_contents($root . '/Helper/SmtpSender.php');
        self::assertStringContainsString('__template_id', $sender);
        self::assertStringContainsString('__locale', $sender);
        self::assertStringContainsString('schema_fields_TEMPLATE_ID', $sender);
        self::assertStringContainsString('schema_fields_LOCALE', $sender);

        self::assertFileExists($root . '/Service/MailTemplateSendContext.php');
    }

    public function testRendererEscapesAndRaw(): void
    {
        $renderer = new \Weline\Smtp\Service\MailTemplateRenderer();
        $out = $renderer->render('Hi {{var.name}}', ['name' => '<b>x</b>'], ['name']);
        self::assertSame('Hi &lt;b&gt;x&lt;/b&gt;', $out);
        $raw = $renderer->render('Hi {{var.name|raw}}', ['name' => '<b>x</b>'], ['name']);
        self::assertSame('Hi <b>x</b>', $raw);
        $stripped = $renderer->stripScripts('<p>a</p><script>alert(1)</script>');
        self::assertStringNotContainsString('<script', $stripped);
    }

    public function testRendererEvaluatesIfBlocks(): void
    {
        $renderer = new \Weline\Smtp\Service\MailTemplateRenderer();
        $tpl = 'X{{#if var.continue_pay_url}}<a href="{{var.continue_pay_url}}">Pay</a>{{/if}}Y';
        $with = $renderer->render($tpl, ['continue_pay_url' => 'https://pay.test/x'], ['continue_pay_url']);
        self::assertSame('X<a href="https://pay.test/x">Pay</a>Y', $with);
        self::assertStringNotContainsString('{{#if', $with);
        self::assertStringNotContainsString('{{/if}}', $with);

        $without = $renderer->render($tpl, ['continue_pay_url' => ''], ['continue_pay_url']);
        self::assertSame('XY', $without);
        self::assertStringNotContainsString('Pay', $without);
    }

    public function testUnpaidOrderReminderTemplateIfCleared(): void
    {
        $root = dirname(__DIR__, 2);
        $file = $root . '/view/email/unpaid_order_reminder/en_US.html';
        if (!is_file($file)) {
            // 模块种子在 Marketing；Smtp catalog 同步同语法
            $file = dirname($root) . '/Marketing/view/email/unpaid_order_reminder/en_US.html';
        }
        self::assertFileExists($file);
        $tpl = (string)file_get_contents($file);
        $renderer = new \Weline\Smtp\Service\MailTemplateRenderer();
        $out = $renderer->render($tpl, [
            'customer_name' => 'Buyer',
            'order_number' => 'WB-1',
            'currency' => 'USD',
            'grand_total' => '99.00',
            'created_at' => '2026-09-13 10:00:00',
            'continue_pay_url' => 'https://shop.test/pay',
        ], ['customer_name', 'order_number', 'currency', 'grand_total', 'created_at', 'continue_pay_url']);
        self::assertStringContainsString('https://shop.test/pay', $out);
        self::assertStringContainsString('Continue payment', $out);
        self::assertStringNotContainsString('{{#if', $out);
        self::assertStringNotContainsString('{{/if}}', $out);
    }
}
