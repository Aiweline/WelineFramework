<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class MailFrontendRegisterLoginContractTest extends TestCase
{
    public function testCustomerRegisterExposesExtrasSlot(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Customer/view/templates/frontend/account/register.phtml');
        self::assertStringContainsString('account-register-extras', $src);
        self::assertStringNotContainsString("->fetch('Weline_Mail::", $src);
        self::assertSame(1, substr_count($src, '<w:slot id="account-register-extras"'));
        self::assertSame(1, substr_count($src, '<w:slot id="account-mail-register-panel"'));
        self::assertStringContainsString('ob_start()', $src);
        self::assertStringContainsString('ob_get_clean()', $src);
        self::assertStringContainsString('showRegisterTabs', $src);
        self::assertStringContainsString('mailRegisterPanelHtml', $src);
        self::assertStringContainsString('data-w-component="tabs"', $src);
        self::assertStringContainsString('customer-register-panel', $src);
        self::assertStringContainsString('mail-register-panel', $src);
        self::assertStringContainsString('captcha="off"', $src);
        $panelEcho = strpos($src, '<?= $mailRegisterPanelHtml ?>');
        $formClose = strpos($src, '</w:form>');
        self::assertNotFalse($panelEcho);
        self::assertNotFalse($formClose);
        self::assertGreaterThan($formClose, $panelEcho, 'Mail panel HTML must render after main register form');
    }

    public function testCustomerLoginHasNoDedicatedMailLogin(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Customer/view/templates/frontend/account/login.phtml');
        self::assertStringNotContainsString('account-mail-login', $src);
        self::assertStringNotContainsString('Weline_Mail::templates/frontend/widgets/account-mail-login', $src);
        self::assertStringContainsString('account-login-social-providers', $src);
        self::assertFileDoesNotExist(BP . 'app/code/Weline/Mail/view/templates/frontend/widgets/account-mail-login.phtml');
        self::assertFileDoesNotExist(BP . 'app/code/Weline/Mail/Service/MailFrontendLoginService.php');
    }

    public function testWidgetDefaultInjectionsRegisterOnly(): void
    {
        $widgets = include BP . 'app/code/Weline/Mail/extends/module/Weline_Widget/Weline_Mail/widget.php';
        self::assertArrayHasKey('account-mail-register', $widgets);
        self::assertArrayNotHasKey('account-mail-login', $widgets);
        self::assertSame('account-mail-register-panel', $widgets['account-mail-register']['slot'] ?? null);
        self::assertSame('injection', $widgets['account-mail-register']['placement']);
        $injections = $widgets['account-mail-register']['default_injections'];
        self::assertSame(['account/register', 'account'], array_column($injections, 'layout_type'));
        self::assertSame(['default', 'auth'], array_column($injections, 'layout_option'));
        foreach ($injections as $injection) {
            self::assertSame('account-mail-register-panel', $injection['slot']);
            self::assertTrue($injection['required']);
            self::assertSame('panel', $injection['config']['mail_register_variant']);
        }
    }

    public function testFeatureConfigKeysAndDefaults(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Mail/Service/MailFrontendFeatureConfig.php');
        self::assertStringContainsString('mail/frontend_register/enabled', $src);
        self::assertStringContainsString('mail/frontend_register/aux_email_verify', $src);
        self::assertStringNotContainsString('mail/frontend_login/enabled', $src);
        self::assertStringContainsString('return $this->bool(self::KEY_REGISTER_ENABLED, false)', $src);
        self::assertStringContainsString('return $this->bool(self::KEY_AUX_VERIFY, true)', $src);
        $cfgTpl = (string)file_get_contents(BP . 'app/code/Weline/Mail/extends/module/Weline_SystemConfig/Config/frontend/mail-frontend.phtml');
        self::assertStringNotContainsString('mail/frontend_login/enabled', $cfgTpl);
    }

    public function testRegisterWidgetGatesOnConfig(): void
    {
        $tpl = (string)file_get_contents(BP . 'app/code/Weline/Mail/view/templates/frontend/widgets/account-mail-register.phtml');
        self::assertStringContainsString('isRegisterEnabled', $tpl);
        self::assertStringContainsString('isAuxEmailVerifyEnabled', $tpl);
        self::assertStringContainsString('mail_register_variant', $tpl);
        self::assertStringContainsString('w-auth-login__mail-panel', $tpl);
        self::assertStringNotContainsString('<details', $tpl);
    }

    public function testCacheInvalidatorObservesResourceChanged(): void
    {
        self::assertFileExists(BP . 'app/code/Weline/Mail/Observer/MailFrontendConfigResourceChanged.php');
        $event = (string)file_get_contents(BP . 'app/code/Weline/Mail/etc/event.xml');
        self::assertStringContainsString('MailFrontendConfigResourceChanged', $event);
        self::assertStringContainsString('resource_changed', $event);
    }
}
