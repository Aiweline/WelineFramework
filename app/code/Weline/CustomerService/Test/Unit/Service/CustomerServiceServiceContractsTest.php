<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Service\BindCaptchaGuard;
use Weline\CustomerService\Service\ChatService;
use Weline\CustomerService\Service\WidgetTranslationService;

/**
 * Aggregated contract suite (merged from 9 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerServiceServiceContractsPass().
 */
final class CustomerServiceServiceContractsTest extends TestCase
{
    public function testAllCustomerServiceServiceContractsPass(): void
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
            'BackendMenuSignalProvider::testProviderEmitsConsoleSourceAndUsesAgentUnreadAggregate' => function () {
            $this->assert_BackendMenuSignalProvider_testProviderEmitsConsoleSourceAndUsesAgentUnreadAggregate();
        },
            'BackendMenuSignalProvider::testChatServiceCountUnreadForAgentMatchesConsoleReadFloor' => function () {
            $this->assert_BackendMenuSignalProvider_testChatServiceCountUnreadForAgentMatchesConsoleReadFloor();
        },
            'BindCaptchaGuard::testBindCaptchaGuardDeclaresStableIntentAndFormId' => function () {
            $this->assert_BindCaptchaGuard_testBindCaptchaGuardDeclaresStableIntentAndFormId();
        },
            'BindCaptchaGuard::testBindControllerVerifiesCaptchaBeforeSendingEmail' => function () {
            $this->assert_BindCaptchaGuard_testBindControllerVerifiesCaptchaBeforeSendingEmail();
        },
            'BindCaptchaGuard::testQueryProviderVerifiesCaptchaBeforeSendingEmail' => function () {
            $this->assert_BindCaptchaGuard_testQueryProviderVerifiesCaptchaBeforeSendingEmail();
        },
            'BindCaptchaGuard::testBindCaptchaChallengeSupportsLocalPrefer' => function () {
            $this->assert_BindCaptchaGuard_testBindCaptchaChallengeSupportsLocalPrefer();
        },
            'ChatMessagesHistory::testGetMessagesBeforeExistsAndGetMessagesOrdersById' => function () {
            $this->assert_ChatMessagesHistory_testGetMessagesBeforeExistsAndGetMessagesOrdersById();
        },
            'ConsoleSessionPriority::testSortPutsLongestUnreadWaitFirstThenNewest' => function () {
            $this->assert_ConsoleSessionPriority_testSortPutsLongestUnreadWaitFirstThenNewest();
        },
            'ConsoleSessionPriority::testFrontendContractsDefaultSelectAndIncomingFeedback' => function () {
            $this->assert_ConsoleSessionPriority_testFrontendContractsDefaultSelectAndIncomingFeedback();
        },
            'ConsoleSessionPriority::testConsoleSessionListTitlePrefersCustomerName' => function () {
            $this->assert_ConsoleSessionPriority_testConsoleSessionListTitlePrefersCustomerName();
        },
            'CustomerServiceSettings::testSettingsSourceUsesSystemConfigKeysAndWebsiteLocaleFallback' => function () {
            $this->assert_CustomerServiceSettings_testSettingsSourceUsesSystemConfigKeysAndWebsiteLocaleFallback();
        },
            'EmailBindingService::testSendVerificationUsesSmtpQueryWithModuleFallback' => function () {
            $this->assert_EmailBindingService_testSendVerificationUsesSmtpQueryWithModuleFallback();
        },
            'EmailValidation::testEmailBindingServiceDeclaresStrictValidationAndNoSilentDevBind' => function () {
            $this->assert_EmailValidation_testEmailBindingServiceDeclaresStrictValidationAndNoSilentDevBind();
        },
            'EmailValidation::testFrontendAndControllersShareStrictEmailGate' => function () {
            $this->assert_EmailValidation_testFrontendAndControllersShareStrictEmailGate();
        },
            'EmailValidation::testChatUiSupportsIdentityDaySeparatorAndNotifications' => function () {
            $this->assert_EmailValidation_testChatUiSupportsIdentityDaySeparatorAndNotifications();
        },
            'EmailValidation::testVerifyPageBroadcastsBoundEventForOpenerChat' => function () {
            $this->assert_EmailValidation_testVerifyPageBroadcastsBoundEventForOpenerChat();
        },
            'GuestSendGate::testAwaitingAgentReplyWhenNewestIsCustomer' => function () {
            $this->assert_GuestSendGate_testAwaitingAgentReplyWhenNewestIsCustomer();
        },
            'GuestSendGate::testGuestSendGateSurfaceInServiceAndProviders' => function () {
            $this->assert_GuestSendGate_testGuestSendGateSurfaceInServiceAndProviders();
        },
            'WidgetComposerToolbarI18n::testEnglishWidgetDictionaryTranslatesComposerTools' => function () {
            $this->assert_WidgetComposerToolbarI18n_testEnglishWidgetDictionaryTranslatesComposerTools();
        },
            'WidgetComposerToolbarI18n::testWidgetJsRefreshesComposerToolLabelsOnLocaleChange' => function () {
            $this->assert_WidgetComposerToolbarI18n_testWidgetJsRefreshesComposerToolLabelsOnLocaleChange();
        },
        ];
    }

private function assert_BackendMenuSignalProvider_testProviderEmitsConsoleSourceAndUsesAgentUnreadAggregate(): void
{
    $path = BP . 'app/code/Weline/CustomerService/extends/module/Weline_Admin/BackendMenuSignalProvider/CustomerServiceConsoleMenuSignalProvider.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('BackendMenuSignalProviderInterface', $src);
    self::assertStringContainsString("MENU_SOURCE_ID = 'Weline_CustomerService::console'", $src);
    self::assertStringContainsString('countUnreadForAgent', $src);
    self::assertStringContainsString('schema_fields_IS_ACTIVE', $src);
    self::assertStringNotContainsString('::session', $src);
    self::assertStringNotContainsString('STATUS_WAITING', $src);
}

private function assert_BackendMenuSignalProvider_testChatServiceCountUnreadForAgentMatchesConsoleReadFloor(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/CustomerService/Service/ChatService.php');
    self::assertStringContainsString('function countUnreadForAgent', $src);
    $start = strpos($src, 'function countUnreadForAgent');
    self::assertNotFalse($start);
    $body = substr($src, (int) $start, 2200);
    self::assertStringContainsString('STATUS_ACTIVE', $body);
    self::assertStringContainsString('SENDER_TYPE_CUSTOMER', $body);
    self::assertStringContainsString('schema_fields_LAST_READ_TIME', $body);
    self::assertStringContainsString('schema_fields_TRANSFERRED_AT', $body);
    self::assertStringContainsString('1970-01-01 00:00:00', $body);
    self::assertStringNotContainsString('STATUS_WAITING', $body);
    self::assertStringNotContainsString('ON CONFLICT', $body);
    self::assertStringNotContainsString('ON DUPLICATE', $body);
    self::assertStringNotContainsString('DATE_FORMAT', $body);
    self::assertStringNotContainsString('COALESCE(', $body);
}

private function assert_BindCaptchaGuard_testBindCaptchaGuardDeclaresStableIntentAndFormId(): void
{
    $this->assertSame('customerservice_bind_email', BindCaptchaGuard::INTENT);
    $this->assertSame('cs-bind-form', BindCaptchaGuard::FORM_ID);
}

private function assert_BindCaptchaGuard_testBindControllerVerifiesCaptchaBeforeSendingEmail(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Controller/Frontend/Bind.php');
    $this->assertStringContainsString('BindCaptchaGuard', $controller);
    $this->assertStringContainsString('$this->bindCaptchaGuard->verify($submission, $this->request)', $controller);
    $this->assertStringContainsString('getCaptchaChallenge', $controller);
}

private function assert_BindCaptchaGuard_testQueryProviderVerifiesCaptchaBeforeSendingEmail(): void
{
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php');
    $guard = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Service/BindCaptchaGuard.php');
    $this->assertStringContainsString('BindCaptchaGuard', $provider);
    $this->assertStringContainsString('$this->bindCaptchaGuard->verify($params, $this->request)', $provider);
    $this->assertStringContainsString('captcha_provider', $provider);
    $this->assertStringContainsString('人机验证失败或已过期，请重试', $provider);
    $this->assertStringContainsString('captcha_degrade', $provider);
    $this->assertStringContainsString('captcha_detail', $provider);
    $this->assertStringContainsString('lastFailureDetail', $guard);
    $this->assertStringContainsString('empty_token_props', $guard);
    $this->assertStringContainsString('shouldOfferLocalDegrade', $guard);
    $this->assertStringContainsString('shouldOfferLocalDegrade', $provider);
    $this->assertStringContainsString('人机验证未完成，请再试一次', $provider);
    $this->assertStringNotContainsString('Captcha verification failed or expired. Please try again.', $provider);
}

private function assert_BindCaptchaGuard_testBindCaptchaChallengeSupportsLocalPrefer(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Controller/Frontend/Bind.php');
    $guard = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Service/BindCaptchaGuard.php');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/customer-service.js');
    $this->assertStringContainsString("getGet('prefer'", $controller);
    $this->assertStringContainsString('allowsLocalDegrade', $guard);
    $this->assertStringNotContainsString('isLocalTestHost', $guard);
    $this->assertStringContainsString("prefer', 'local_image'", $js);
    $this->assertStringContainsString('captcha_degrade', $js);
    $this->assertStringContainsString('function notifyCaptchaDegrade', $js);
    $this->assertStringContainsString('function autoDegradeBindCaptcha', $js);
    $this->assertStringContainsString('csCaptchaUserSubmit', $js);
    $this->assertStringContainsString('quietHint', $js);
    $this->assertStringContainsString('请填写下方图码后发送验证邮件', $js);
    $this->assertStringContainsString("weline:captcha:degrade", $js);
    $this->assertStringContainsString('readCaptchaField', $js);
    $this->assertStringContainsString('function ensureRemoteBindCaptchaToken', $js);
    $this->assertStringContainsString('weline:form:prepare-submit', $js);
    $this->assertStringContainsString('data-weline-captcha-provider', $js);
    $this->assertStringContainsString('ensureLocalCaptchaHint', $js);
    $this->assertStringContainsString('请填写本地图码', $js);
    $this->assertStringContainsString('keepBusinessResult: true', $js);
    $this->assertStringContainsString("cache: 'no-store'", $js);
    $this->assertStringContainsString('response.status === 404 || response.status === 503', $js);
    $this->assertStringContainsString("code || '') === 'maintenance'", $js);
    $this->assertStringContainsString('async function showBindPrompt', $js);
    $this->assertStringContainsString('SharedResponseCachePolicy::forbid', $controller);
}

private function assert_ChatMessagesHistory_testGetMessagesBeforeExistsAndGetMessagesOrdersById(): void
{
    $ref = new \ReflectionClass(ChatService::class);
    $this->assertTrue($ref->hasMethod('getMessagesBefore'));
    $this->assertTrue($ref->hasMethod('getMessages'));
    $src = (string) file_get_contents($ref->getFileName());
    $this->assertStringContainsString("order(ChatMessage::schema_fields_ID, 'DESC')", $src);
    $this->assertStringContainsString('function getMessagesBefore', $src);
}

private function assert_ConsoleSessionPriority_testSortPutsLongestUnreadWaitFirstThenNewest(): void
{
    $ref = new \ReflectionClass(ChatService::class);
    /** @var ChatService $service */
    $service = $ref->newInstanceWithoutConstructor();
    $sorted = $service->sortConsoleSessionsByPriority([['session_id' => 4, 'unread_count' => 0, 'last_message_at' => '2026-09-09T17:00:00+08:00', 'waiting_since_at' => null], ['session_id' => 12, 'unread_count' => 1, 'last_message_at' => '2026-09-09T17:30:00+08:00', 'waiting_since_at' => '2026-09-09T17:29:00+08:00'], ['session_id' => 6, 'unread_count' => 2, 'last_message_at' => '2026-09-09T17:35:00+08:00', 'waiting_since_at' => '2026-09-09T15:00:00+08:00']]);
    self::assertSame([6, 12, 4], array_map(static fn(array $row): int => (int) $row['session_id'], $sorted));
}

private function assert_ConsoleSessionPriority_testFrontendContractsDefaultSelectAndIncomingFeedback(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService';
    $js = (string) file_get_contents($base . '/view/statics/js/backend-console.js');
    $css = (string) file_get_contents($base . '/view/statics/css/backend-console.css');
    $ctrl = (string) file_get_contents($base . '/Controller/Backend/Console.php');
    $service = (string) file_get_contents($base . '/Service/ChatService.php');
    $proto = (string) file_get_contents($base . '/view/statics/prototype/console-session-priority-logic.html');
    $tpl = (string) file_get_contents($base . '/view/templates/backend/Console/index.phtml');
    self::assertStringContainsString('autoSelectPreferredSession', $js);
    self::assertStringContainsString('notifyIncomingMessage', $js);
    self::assertStringContainsString('sortSessionsByPriority', $js);
    self::assertStringContainsString('cs-session-alert', $css);
    self::assertStringContainsString('defaultSessionId', $ctrl);
    self::assertStringContainsString('defaultSessionId', $tpl);
    self::assertStringContainsString('sortConsoleSessionsByPriority', $service);
    self::assertStringContainsString('enrichConsoleSessionRows', $service);
    self::assertStringContainsString('consoleSessionListTitle', $service);
    self::assertStringContainsString('customer_display_name', $service);
    self::assertStringContainsString('sessionListTitle', $js);
    self::assertStringContainsString('customer_kind', $tpl);
    self::assertStringContainsString('list_title', $tpl);
    self::assertStringContainsString('等待最久', $proto);
}

private function assert_ConsoleSessionPriority_testConsoleSessionListTitlePrefersCustomerName(): void
{
    $ref = new \ReflectionClass(ChatService::class);
    /** @var ChatService $service */
    $service = $ref->newInstanceWithoutConstructor();
    self::assertSame('张三', $service->consoleSessionListTitle(['session_id' => 6, 'customer_kind' => 'customer', 'customer_display_name' => '张三']));
    self::assertSame('#6', $service->consoleSessionListTitle(['session_id' => 6, 'customer_kind' => 'guest', 'customer_display_name' => 'guest@example.com']));
    self::assertSame('#6', $service->consoleSessionListTitle(['session_id' => 6, 'customer_kind' => 'customer', 'customer_display_name' => '']));
}

private function assert_CustomerServiceSettings_testSettingsSourceUsesSystemConfigKeysAndWebsiteLocaleFallback(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService';
    $settings = (string) file_get_contents($moduleRoot . '/Service/CustomerServiceSettings.php');
    $template = (string) file_get_contents($moduleRoot . '/extends/module/Weline_SystemConfig/Config/frontend/customer-service.phtml');
    $configController = (string) file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
    $configTemplate = (string) file_get_contents($moduleRoot . '/view/templates/backend/Config/index.phtml');
    $menuXml = (string) file_get_contents($moduleRoot . '/etc/backend/menu.xml');
    $chat = (string) file_get_contents($moduleRoot . '/Controller/Frontend/Chat.php');
    $query = (string) file_get_contents($moduleRoot . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php');
    $chatService = (string) file_get_contents($moduleRoot . '/Service/ChatService.php');
    $widget = (string) file_get_contents($moduleRoot . '/view/templates/frontend/widgets/customer-service-float.phtml');
    self::assertStringContainsString('customer_service/general/enabled', $template);
    self::assertStringContainsString('customer_service/general/default_agent_locale', $template);
    self::assertStringContainsString('customer_service/general/default_customer_locale', $template);
    self::assertStringContainsString('type="ai_model"', $template);
    self::assertStringContainsString('type="locale"', $template);
    self::assertStringContainsString('scope="global,website,store"', $template);
    self::assertStringContainsString('ConfigReader', $settings);
    self::assertStringContainsString('State::resolveWebsiteDefaultLanguage', $settings);
    self::assertStringContainsString('migrateLegacyOnce', $settings);
    self::assertStringContainsString('processLegacySettled', $settings);
    self::assertStringContainsString('getScopedConfigRow', $settings);
    self::assertStringContainsString('KEY_ENABLED', $settings);
    self::assertStringContainsString('SystemConfigTargetScopeService', $configController);
    self::assertStringContainsString('return $this->fetch()', $configController);
    self::assertStringNotContainsString('weline_systemconfig/backend/config', $configController);
    self::assertStringContainsString('<w:config:embed', $configTemplate);
    self::assertStringContainsString('module="Weline_CustomerService"', $configTemplate);
    self::assertStringContainsString('area="frontend"', $configTemplate);
    self::assertStringContainsString('<w:scope', $configTemplate);
    self::assertStringContainsString('action="*/backend/config"', $menuXml);
    self::assertStringNotContainsString('CustomerServiceConfig', $chat);
    self::assertStringContainsString('CustomerServiceSettings', $chat);
    self::assertStringContainsString('CustomerServiceSettings', $query);
    self::assertStringContainsString('defaultAgentLocale()', $chatService);
    self::assertStringContainsString('defaultCustomerLocale()', $chatService);
    self::assertStringContainsString('isServiceEnabled()', $widget);
    self::assertStringContainsString('defaultCustomerLocale', $widget);
    self::assertStringContainsString('@widget.code {customer-service-float}', $widget);
    self::assertStringContainsString('@widget.slot {storefront-float-end}', $widget);
    self::assertFileDoesNotExist($moduleRoot . '/view/hooks/Weline_Theme/frontend/layouts/base/float-slot-end.phtml');
    $widgetPhp = $moduleRoot . '/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
    $tpl = 'Weline_CustomerService::templates/frontend/widgets/customer-service-float.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    self::assertStringContainsString('"slot":"storefront-float-end"', $widget);
    self::assertStringContainsString('"area":"footer"', $widget);
    self::assertStringContainsString('"required":true', $widget);
}

private function assert_EmailBindingService_testSendVerificationUsesSmtpQueryWithModuleFallback(): void
{
    $serviceFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Service/EmailBindingService.php';
    $providerFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php';
    $this->assertFileExists($serviceFile);
    $service = (string) file_get_contents($serviceFile);
    self::assertStringContainsString("w_query('smtp', 'isAvailable'", $service);
    self::assertStringContainsString("w_query('smtp', 'send'", $service);
    self::assertStringContainsString("'Weline_CustomerService'", $service);
    self::assertStringContainsString("'Weline_Smtp'", $service);
    self::assertStringContainsString("'channel' => 'Weline_CustomerService::email_binding'", $service);
    self::assertStringContainsString("'vars'", $service);
    self::assertStringContainsString("'verification_url'", $service);
    self::assertStringNotContainsString("'subject' =>", $service);
    self::assertStringNotContainsString("'content' => \$content", $service);
    self::assertStringContainsString('getLastErrorMessage', $service);
    self::assertStringContainsString('sendVerificationEmailDevFallback', $service);
    self::assertStringContainsString('isValidEmail', $service);
    self::assertStringNotContainsString('noreply@example.com', $service);
    $mailProvider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/extends/MailChannelProvider.php');
    $this->assertStringContainsString("'default_templates'", $mailProvider);
    $this->assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/email/email_binding/zh_Hans_CN.html');
    $provider = (string) file_get_contents($providerFile);
    $this->assertStringContainsString('getLastErrorMessage()', $provider);
}

private function assert_EmailValidation_testEmailBindingServiceDeclaresStrictValidationAndNoSilentDevBind(): void
{
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Service/EmailBindingService.php');
    $this->assertStringContainsString('function isValidEmail', $service);
    $this->assertStringContainsString('FILTER_VALIDATE_EMAIL', $service);
    $this->assertStringContainsString('announceIdentityChange', $service);
    $this->assertStringContainsString('postSystemMessage', $service);
    $this->assertStringContainsString('getLastVerificationUrl', $service);
    $this->assertStringContainsString('请点击下方链接完成绑定', $service);
    $this->assertStringContainsString("getUrl('/customerservice/frontend/bind/verify'", $service);
    $this->assertDoesNotMatchRegularExpression('/function sendVerificationEmailDevFallback\([\s\S]*?bindCustomerToSession\(/', $service);
}

private function assert_EmailValidation_testFrontendAndControllersShareStrictEmailGate(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/customer-service.js');
    $bind = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Controller/Frontend/Bind.php');
    $query = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php');
    $this->assertStringContainsString('function isValidBindEmail', $js);
    $this->assertStringContainsString('isValidBindEmail(email)', $js);
    $this->assertStringContainsString('isValidEmail($email)', $bind);
    $this->assertStringContainsString('isValidEmail($email)', $query);
    $this->assertStringContainsString("'identity'", $query);
    $this->assertStringContainsString('buildSessionIdentity', $query);
}

private function assert_EmailValidation_testChatUiSupportsIdentityDaySeparatorAndNotifications(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/customer-service.js');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/css/customer-service.css');
    $message = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Model/ChatMessage.php');
    $this->assertStringContainsString('applySessionIdentity', $js);
    $this->assertStringContainsString('appendDaySeparatorIfNeeded', $js);
    $this->assertStringContainsString('notifyIncomingAgentMessage', $js);
    $this->assertStringContainsString('cs-identity-chip', $js);
    $this->assertStringContainsString('appendLinkedBody', $js);
    $this->assertStringContainsString('linkUrl', $js);
    $this->assertStringContainsString('verification_url', $js);
    $this->assertStringContainsString("target = '_blank'", $js);
    $this->assertStringContainsString('.cs-notice-alert__link', $css);
    $this->assertStringContainsString('bindEmailBoundSync', $js);
    $this->assertStringContainsString('onEmailBindingConfirmed', $js);
    $this->assertStringContainsString('weline-cs-email-bound', $js);
    $this->assertStringContainsString('SENDER_TYPE_SYSTEM', $message);
    $this->assertStringContainsString('.cs-day-separator', $css);
    $this->assertStringContainsString('.cs-message.system', $css);
}

private function assert_EmailValidation_testVerifyPageBroadcastsBoundEventForOpenerChat(): void
{
    $verify = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/Bind/verify.phtml');
    $bind = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Controller/Frontend/Bind.php');
    $this->assertStringContainsString('weline-cs-email-bound', $verify);
    $this->assertStringContainsString('cs_email_bound_v1', $verify);
    $this->assertStringContainsString('BroadcastChannel', $verify);
    $this->assertStringContainsString('session_token', $bind);
    $this->assertStringContainsString('home_url', $bind);
}

private function assert_GuestSendGate_testAwaitingAgentReplyWhenNewestIsCustomer(): void
{
    $this->assertFalse(ChatService::isAwaitingAgentReplyFromSenders([]));
    $this->assertTrue(ChatService::isAwaitingAgentReplyFromSenders([ChatMessage::SENDER_TYPE_CUSTOMER]));
    $this->assertFalse(ChatService::isAwaitingAgentReplyFromSenders([ChatMessage::SENDER_TYPE_AGENT, ChatMessage::SENDER_TYPE_CUSTOMER]));
    $this->assertTrue(ChatService::isAwaitingAgentReplyFromSenders([ChatMessage::SENDER_TYPE_CUSTOMER, ChatMessage::SENDER_TYPE_AGENT]));
}

private function assert_GuestSendGate_testGuestSendGateSurfaceInServiceAndProviders(): void
{
    $serviceFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Service/ChatService.php';
    $providerFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php';
    $chatController = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Controller/Frontend/Chat.php';
    $widgetJs = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/customer-service.js';
    $bodyEnd = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml';
    $service = (string) file_get_contents($serviceFile);
    $provider = (string) file_get_contents($providerFile);
    $controller = (string) file_get_contents($chatController);
    $js = (string) file_get_contents($widgetJs);
    $hook = (string) file_get_contents($bodyEnd);
    $this->assertStringContainsString('resolveGuestSendGate', $service);
    $this->assertStringContainsString('assertCustomerMaySend', $service);
    $this->assertStringContainsString('isSessionEmailBound', $service);
    $this->assertStringContainsString('guest_send', $provider);
    $this->assertStringContainsString('guest_send_locked', $provider);
    $this->assertStringContainsString('assertCustomerMaySend', $controller);
    $this->assertStringContainsString('applyGuestSendGate', $js);
    $this->assertStringContainsString('cs-guest-send-gate', $hook);
    $this->assertStringContainsString('data-cs-open-bind-email', $hook);
    $this->assertStringContainsString('instanceof ServiceAgent', $service);
}

private function assert_WidgetComposerToolbarI18n_testEnglishWidgetDictionaryTranslatesComposerTools(): void
{
    $service = new WidgetTranslationService();
    $en = $service->getWidgetTranslationsForLocales(['en_US'])['en_US'] ?? [];
    $this->assertSame('Emoji', $en['表情'] ?? null);
    $this->assertSame('Image', $en['图片'] ?? null);
    $this->assertSame('File', $en['文件'] ?? null);
    $this->assertSame('Screenshot', $en['截图'] ?? null);
    $this->assertSame('Chat tools', $en['聊天工具'] ?? null);
}

private function assert_WidgetComposerToolbarI18n_testWidgetJsRefreshesComposerToolLabelsOnLocaleChange(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/customer-service.js');
    $this->assertStringContainsString('function updateWidgetLocaleText()', $js);
    $this->assertStringContainsString("data-cs-tool", $js);
    $this->assertStringContainsString("__('表情')", $js);
    $this->assertStringContainsString("__('图片')", $js);
    $this->assertStringContainsString("__('文件')", $js);
    $this->assertStringContainsString("__('截图')", $js);
    $this->assertStringContainsString("__('聊天工具')", $js);
    $this->assertMatchesRegularExpression('/function updateWidgetLocaleText\(\)[\s\S]*composerLabels[\s\S]*__\(\'表情\'\)[\s\S]*data-cs-tool/', $js);
}
}
