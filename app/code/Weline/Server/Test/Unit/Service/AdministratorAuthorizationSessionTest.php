<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\AdministratorAuthorizationSession;

final class AdministratorAuthorizationSessionTest extends TestCase
{
    public function testOneInteractiveAuthorizationIsReusedByEveryPrivilegedAction(): void
    {
        $commands = [];
        $session = new AdministratorAuthorizationSession(
            commandRunner: static function (array $command) use (&$commands): int {
                $commands[] = $command;

                return 0;
            },
            interactiveProbe: static fn(): bool => true,
            effectiveUidProbe: static fn(): int => 501,
            sudoBinary: '/test/sudo',
            osFamily: 'Darwin',
        );

        self::assertTrue($session->runPrivileged(['/test/editor', '--domain=one.weline.test']));
        self::assertTrue($session->runPrivileged(['/test/editor', '--domain=two.weline.test']));

        self::assertSame(['/test/sudo', '-v'], $commands[0] ?? null);
        self::assertSame(
            ['/test/sudo', '-n', '--', '/test/editor', '--domain=one.weline.test'],
            $commands[1] ?? null,
        );
        self::assertSame(
            ['/test/sudo', '-n', '--', '/test/editor', '--domain=two.weline.test'],
            $commands[2] ?? null,
        );
        self::assertCount(3, $commands, 'sudo -v must run exactly once per start invocation.');
    }

    public function testNonInteractiveSessionNeverAttemptsAuthorizationOrMutation(): void
    {
        $commands = [];
        $session = new AdministratorAuthorizationSession(
            commandRunner: static function (array $command) use (&$commands): int {
                $commands[] = $command;

                return 0;
            },
            interactiveProbe: static fn(): bool => false,
            effectiveUidProbe: static fn(): int => 501,
            sudoBinary: '/test/sudo',
            osFamily: 'Darwin',
        );

        self::assertFalse($session->runPrivileged(['/test/editor', '--domain=one.weline.test']));
        self::assertSame([], $commands);
    }
}
