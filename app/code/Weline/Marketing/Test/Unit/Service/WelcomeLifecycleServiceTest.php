<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Service\WelcomeLifecycleService;

final class WelcomeLifecycleServiceTest extends TestCase
{
    public function testSkipsWithoutEmail(): void
    {
        $svc = new WelcomeLifecycleService();
        $stats = $svc->handleRegistration([
            'customer_id' => 1,
            'email' => '',
        ]);
        self::assertSame(1, $stats['skipped']);
        self::assertSame(0, $stats['sent']);
    }

    public function testSkipsWithoutCustomerId(): void
    {
        $svc = new WelcomeLifecycleService();
        $stats = $svc->handleRegistration([
            'customer_id' => 0,
            'email' => 'a@example.com',
        ]);
        self::assertSame(1, $stats['skipped']);
    }
}
