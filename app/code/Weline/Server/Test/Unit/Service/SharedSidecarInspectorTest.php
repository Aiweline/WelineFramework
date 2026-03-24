<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SharedSidecarInspector;

final class SharedSidecarInspectorTest extends TestCase
{
    public function testExtractOptionValueTrimsTrailingQuotesFromWindowsCommandLine(): void
    {
        $inspector = new SharedSidecarInspector();
        $method = new \ReflectionMethod($inspector, 'extractOptionValue');
        $method->setAccessible(true);

        $value = $method->invoke(
            $inspector,
            '"C:\\php\\php.exe" "E:\\WelineFramework\\DEV-workspace\\app\\code\\Weline\\Server\\bin\\session_server.php" '
            . '127.0.0.1 29070 "shared-session-29070" --instance-name=shared-session-29070" '
            . '--token-file-name=session_server.smoke.29070.token" --shared-service=1',
            'token-file-name'
        );

        self::assertSame('session_server.smoke.29070.token', $value);
    }

    public function testResolveInstanceNameTrimsTrailingQuotesFromPositionalInstanceToken(): void
    {
        $inspector = new SharedSidecarInspector();
        $method = new \ReflectionMethod($inspector, 'resolveInstanceName');
        $method->setAccessible(true);

        $instanceName = $method->invoke(
            $inspector,
            '"C:\\php\\php.exe" "E:\\WelineFramework\\DEV-workspace\\app\\code\\Weline\\Server\\bin\\session_server.php" '
            . '127.0.0.1 29070 "shared-session-29070"" --shared-service=1'
        );

        self::assertSame('shared-session-29070', $instanceName);
    }
}
