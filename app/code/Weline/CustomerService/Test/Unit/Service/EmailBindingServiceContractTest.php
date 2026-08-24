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

        $this->assertStringContainsString("w_query('smtp', 'isAvailable'", $service);
        $this->assertStringContainsString("w_query('smtp', 'send'", $service);
        $this->assertStringContainsString("'Weline_CustomerService'", $service);
        $this->assertStringContainsString("'Weline_Smtp'", $service);
        $this->assertStringContainsString('getLastErrorMessage', $service);
        $this->assertStringContainsString('sendVerificationEmailDevFallback', $service);
        $this->assertStringNotContainsString('noreply@example.com', $service);

        $provider = (string) file_get_contents($providerFile);
        $this->assertStringContainsString('getLastErrorMessage()', $provider);
    }
}
