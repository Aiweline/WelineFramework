<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class EmailBindingServiceContractTest extends TestCase
{
    public function testSendVerificationUsesSmtpQueryWithModuleFallback(): void
    {
        $serviceFile = dirname(__DIR__, 3) . '/Service/EmailBindingService.php';
        $providerFile = dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php';

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

        $mailProvider = (string) file_get_contents(dirname(__DIR__, 3) . '/extends/MailChannelProvider.php');
        $this->assertStringContainsString("'default_templates'", $mailProvider);
        $this->assertFileExists(dirname(__DIR__, 3) . '/view/email/email_binding/zh_Hans_CN.html');

        $provider = (string) file_get_contents($providerFile);
        $this->assertStringContainsString('getLastErrorMessage()', $provider);
    }
}
