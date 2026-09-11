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

        // DEV without SMTP must NOT auto-bind; caller surfaces verification_url for click-to-bind.
        self::assertFalse(
            $ok,
            'Expected DEV fallback to withhold auto-bind when SMTP is unavailable'
        );
        self::assertNotSame('', $service->getLastErrorMessage());
        self::assertStringContainsString('未自动绑定', $service->getLastErrorMessage());
        self::assertNotSame('', $service->getLastVerificationUrl());
        self::assertStringContainsString('/customerservice/frontend/bind/verify', $service->getLastVerificationUrl());
    }
}
