<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;

final class FrontendQueryGatewaySessionFactoryTest extends TestCase
{
    public function testGatewayUsesInjectedRequestScopedSessionFactory(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Service/Query/FrontendQueryGateway.php'
        );

        self::assertStringContainsString(
            'private readonly SessionFactory $sessionFactory',
            $source,
        );
        self::assertStringNotContainsString('SessionFactory::getInstance()', $source);
        self::assertStringContainsString(
            "'guest' => !\$this->sessionFactory->createFrontendSession()->isLoggedIn()",
            $source,
        );
        self::assertStringContainsString(
            "? \$this->sessionFactory->createBackendSession()",
            $source,
        );
        self::assertStringContainsString(
            ": \$this->sessionFactory->createFrontendSession()",
            $source,
        );
    }
}
