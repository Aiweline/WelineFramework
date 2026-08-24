<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use Weline\CustomerService\Service\EmailBindingService;
use Weline\Framework\UnitTest\TestCore;

final class EmailBindingServiceSendFlowTest extends TestCore
{
    public function testSendVerificationSucceedsViaDevFallbackWhenSmtpUnavailable(): void
    {
        if (!\defined('DEV')) {
            \define('DEV', true);
        }

        /** @var EmailBindingService $service */
        $service = self::getInstance(EmailBindingService::class);

        $ok = $service->sendVerificationEmail(
            'aiweline@qq.com',
            'cs-test-session-' . \bin2hex(\random_bytes(8))
        );

        self::assertTrue(
            $ok,
            'Expected DEV fallback success when SMTP is unavailable. Last error: '
            . $service->getLastErrorMessage()
        );
        self::assertSame('', $service->getLastErrorMessage());
    }
}
