<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\FrontendSessionCookieMigrator;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

/**
 * Guest Session allocation during createFrontendSession must not emit cookies
 * on cookieless storefront requests (FPC publish gate).
 */
final class FrontendSessionCookieMigratorAnonymousFpcTest extends TestCase
{
    protected function tearDown(): void
    {
        FrontendSessionCookieMigrator::resetRequestState();
        parent::tearDown();
    }

    public function testMigrateIfNeededDoesNotStartSessionWithoutRequestCookies(): void
    {
        FrontendSessionCookieMigrator::resetRequestState();

        $started = false;
        $session = $this->createMock(SessionInterface::class);
        $session->method('isStarted')->willReturnCallback(static function () use (&$started): bool {
            return $started;
        });
        $session->expects(self::never())->method('start');
        $session->expects(self::never())->method('get');
        $session->expects(self::never())->method('set');
        $session->expects(self::never())->method('save');

        $factory = $this->createMock(SessionFactory::class);
        $factory->expects(self::never())->method('createStorage');

        FrontendSessionCookieMigrator::migrateIfNeeded($session, $factory);
        FrontendSessionCookieMigrator::migrateIfNeeded($session, $factory);

        self::assertFalse($started);
    }
}
