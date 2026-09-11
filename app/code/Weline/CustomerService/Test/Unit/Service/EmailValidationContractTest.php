<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class EmailValidationContractTest extends TestCase
{
    public function testEmailBindingServiceDeclaresStrictValidationAndNoSilentDevBind(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/EmailBindingService.php');
        $this->assertStringContainsString('function isValidEmail', $service);
        $this->assertStringContainsString('FILTER_VALIDATE_EMAIL', $service);
        $this->assertStringContainsString('announceIdentityChange', $service);
        $this->assertStringContainsString('postSystemMessage', $service);
        $this->assertStringContainsString('getLastVerificationUrl', $service);
        $this->assertStringContainsString('请点击下方链接完成绑定', $service);
        $this->assertStringContainsString("getUrl('/customerservice/frontend/bind/verify'", $service);
        $this->assertDoesNotMatchRegularExpression(
            '/function sendVerificationEmailDevFallback\([\s\S]*?bindCustomerToSession\(/',
            $service
        );
    }

    public function testFrontendAndControllersShareStrictEmailGate(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/customer-service.js');
        $bind = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Bind.php');
        $query = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php'
        );

        $this->assertStringContainsString('function isValidBindEmail', $js);
        $this->assertStringContainsString('isValidBindEmail(email)', $js);
        $this->assertStringContainsString('isValidEmail($email)', $bind);
        $this->assertStringContainsString('isValidEmail($email)', $query);
        $this->assertStringContainsString("'identity'", $query);
        $this->assertStringContainsString('buildSessionIdentity', $query);
    }

    public function testChatUiSupportsIdentityDaySeparatorAndNotifications(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/customer-service.js');
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/css/customer-service.css');
        $message = (string) file_get_contents(dirname(__DIR__, 3) . '/Model/ChatMessage.php');

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

    public function testVerifyPageBroadcastsBoundEventForOpenerChat(): void
    {
        $verify = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Frontend/Bind/verify.phtml');
        $bind = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Bind.php');

        $this->assertStringContainsString('weline-cs-email-bound', $verify);
        $this->assertStringContainsString('cs_email_bound_v1', $verify);
        $this->assertStringContainsString('BroadcastChannel', $verify);
        $this->assertStringContainsString('session_token', $bind);
        $this->assertStringContainsString('home_url', $bind);
    }
}
