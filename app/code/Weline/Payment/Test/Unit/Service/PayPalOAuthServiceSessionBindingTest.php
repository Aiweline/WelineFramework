<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PayPalOAuthServiceSessionBindingTest extends TestCase
{
    public function testSessionMethodUsesBackendSessionFactory(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PayPalOAuthService.php'
        );

        self::assertStringContainsString(
            'SessionFactory::getInstance()->createBackendSession()',
            $source,
        );
        self::assertStringContainsString('$this->session()->save();', $source);
        self::assertStringNotContainsString('ObjectManager::getInstance(Session::class)', $source);
    }
}
