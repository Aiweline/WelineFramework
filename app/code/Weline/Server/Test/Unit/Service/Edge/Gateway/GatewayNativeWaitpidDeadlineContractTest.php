<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayNativeWaitpidDeadlineContractTest extends TestCase
{
    public function testPrivilegedPosixChildrenNeverUseBlockingWaitpid(): void
    {
        $native = \dirname(__DIR__, 5)
            . '/Service/Edge/Gateway/Native/posix/';
        $broker = (string)\file_get_contents($native . 'wls_gateway_broker.c');
        $launcher = (string)\file_get_contents($native . 'wls_gateway_launcher.c');
        $blockingWait = '/waitpid\s*\(\s*[^,\n]+,\s*[^,\n]+,\s*0\s*\)/';

        self::assertDoesNotMatchRegularExpression($blockingWait, $broker);
        self::assertDoesNotMatchRegularExpression($blockingWait, $launcher);
        self::assertStringContainsString(
            'static int wls_wait_child_exit_until(',
            $broker,
        );
        self::assertStringContainsString(
            'WLS_NGINX_CHILD_REAP_RESERVE_MS',
            $broker,
        );
        self::assertSame(
            4,
            \substr_count($broker, 'wls_wait_root_self_test_child('),
            'The helper definition and all three root-only fork sites must remain bounded.',
        );
        self::assertStringContainsString(
            'WLS_ROOT_SELF_TEST_CHILD_KILL_REAP_MS',
            $broker,
        );
        self::assertSame(
            2,
            \substr_count($broker, 'wls_wait_child_exit_deadline_self_test('),
            'The Broker helper definition and --self-test call must remain wired.',
        );

        self::assertStringContainsString(
            'WLS_BROKER_TERM_GRACE_MILLISECONDS',
            $launcher,
        );
        self::assertStringContainsString(
            'WLS_BROKER_KILL_REAP_MILLISECONDS',
            $launcher,
        );
        self::assertStringContainsString(
            'waitpid(child, status, WNOHANG)',
            $launcher,
        );
        self::assertStringNotContainsString(
            'while (waitpid(broker_pid, &status, 0)',
            $launcher,
        );
        self::assertSame(
            2,
            \substr_count($launcher, 'wls_wait_child_exit_deadline_self_test('),
            'The Launcher helper definition and --self-test call must remain wired.',
        );
    }
}
