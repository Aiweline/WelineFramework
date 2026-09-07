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
            commandRunner: static function (array $command, ?array $environment = null) use (&$commands): int {
                $commands[] = ['argv' => $command, 'env' => $environment];

                return 0;
            },
            interactiveProbe: static fn(): bool => true,
            effectiveUidProbe: static fn(): int => 501,
            sudoBinary: '/test/sudo',
            osFamily: 'Darwin',
        );

        self::assertTrue($session->runPrivileged(['/test/editor', '--domain=one.test.weline.com']));
        self::assertTrue($session->runPrivileged(['/test/editor', '--domain=two.test.weline.com']));

        self::assertSame(['/test/sudo', '-v'], $commands[0]['argv'] ?? null);
        self::assertArrayHasKey('env', $commands[0]);
        self::assertNull($commands[0]['env']);
        self::assertSame(
            ['/test/sudo', '-n', '--', '/test/editor', '--domain=one.test.weline.com'],
            $commands[1]['argv'] ?? null,
        );
        self::assertSame(
            ['/test/sudo', '-n', '--', '/test/editor', '--domain=two.test.weline.com'],
            $commands[2]['argv'] ?? null,
        );
        self::assertCount(3, $commands, 'sudo -v must run exactly once per start invocation.');
    }

    public function testNonInteractiveWithoutAskpassNeverAttemptsAuthorizationOrMutation(): void
    {
        $commands = [];
        $session = new AdministratorAuthorizationSession(
            commandRunner: static function (array $command, ?array $environment = null) use (&$commands): int {
                $commands[] = $command;

                return 0;
            },
            interactiveProbe: static fn(): bool => false,
            effectiveUidProbe: static fn(): int => 501,
            sudoBinary: '/test/sudo',
            osFamily: 'Darwin',
            askpassPathResolver: static fn(): ?string => null,
        );

        self::assertFalse($session->runPrivileged(['/test/editor', '--domain=one.test.weline.com']));
        self::assertSame([], $commands);
    }

    public function testNonInteractiveDarwinUsesGuiAskpassOnceThenReusesTicket(): void
    {
        $commands = [];
        $session = new AdministratorAuthorizationSession(
            commandRunner: static function (array $command, ?array $environment = null) use (&$commands): int {
                $commands[] = ['argv' => $command, 'env' => $environment];

                return 0;
            },
            interactiveProbe: static fn(): bool => false,
            effectiveUidProbe: static fn(): int => 501,
            sudoBinary: '/test/sudo',
            osFamily: 'Darwin',
            askpassPathResolver: static fn(): ?string => '/test/wls_sudo_askpass.php',
        );

        self::assertTrue($session->runPrivileged(['/test/editor', '--domain=one.test.weline.com']));
        self::assertTrue($session->runPrivileged(['/test/editor', '--domain=two.test.weline.com']));

        self::assertSame(['/test/sudo', '-Av'], $commands[0]['argv'] ?? null);
        self::assertSame('/test/wls_sudo_askpass.php', $commands[0]['env']['SUDO_ASKPASS'] ?? null);
        self::assertSame('force', $commands[0]['env']['SUDO_ASKPASS_REQUIRE'] ?? null);
        self::assertSame(
            ['/test/sudo', '-n', '--', '/test/editor', '--domain=one.test.weline.com'],
            $commands[1]['argv'] ?? null,
        );
        self::assertArrayHasKey('env', $commands[1]);
        self::assertNull($commands[1]['env']);
        self::assertCount(3, $commands, 'sudo -Av must run exactly once per start invocation.');
    }
}
