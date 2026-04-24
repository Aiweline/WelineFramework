<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\SharedSidecarInspector;

final class SharedSidecarInspectorTest extends TestCase
{
    public function testExtractTokenFileNameFromCommandLineStatic(): void
    {
        $cmd = 'php session_server.php 127.0.0.1 19970 x --token-file-name=session_server.custom.token --shared-service=1';
        self::assertSame(
            'session_server.custom.token',
            SharedSidecarInspector::extractTokenFileNameFromCommandLine($cmd)
        );
        self::assertSame('', SharedSidecarInspector::extractTokenFileNameFromCommandLine(''));
    }

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

    public function testIsSharedServiceProcessRejectsNonSharedSessionServerProcess(): void
    {
        $inspector = new SharedSidecarInspector();
        $method = new \ReflectionMethod($inspector, 'isSharedServiceProcess');
        $method->setAccessible(true);

        $isShared = $method->invoke(
            $inspector,
            '"C:\\php\\php.exe" "E:\\WelineFramework\\DEV-workspace\\app\\code\\Weline\\Server\\bin\\session_server.php" '
            . '127.0.0.1 19970 test --instance-name=test --token-file-name=session_server.token',
            ControlMessage::ROLE_SESSION_SERVER
        );

        self::assertFalse((bool) $isShared);
    }

    public function testIsSharedServiceProcessAcceptsLegacyScopedSessionProcessName(): void
    {
        $inspector = new SharedSidecarInspector();
        $method = new \ReflectionMethod($inspector, 'isSharedServiceProcess');
        $method->setAccessible(true);
        $scope = \Weline\Server\Service\MasterProcess::getProjectScopeToken();

        $isShared = $method->invoke(
            $inspector,
            '"C:\\php\\php.exe" "E:\\WelineFramework\\DEV-workspace\\app\\code\\Weline\\Server\\bin\\session_server.php" '
            . '127.0.0.1 19970 test --instance-name=test --token-file-name=session_server.token '
            . '--name=weline-wls-session-test-' . $scope,
            ControlMessage::ROLE_SESSION_SERVER
        );

        self::assertTrue((bool) $isShared);
    }

    public function testIsSharedServiceProcessAcceptsSharedInstanceNameMarker(): void
    {
        $inspector = new SharedSidecarInspector();
        $method = new \ReflectionMethod($inspector, 'isSharedServiceProcess');
        $method->setAccessible(true);

        $isShared = $method->invoke(
            $inspector,
            '"C:\\php\\php.exe" "E:\\WelineFramework\\DEV-workspace\\app\\code\\Weline\\Server\\bin\\session_server.php" '
            . '127.0.0.1 19970 shared-session-19970 --instance-name=shared-session-19970 --token-file-name=session_server.token',
            ControlMessage::ROLE_SESSION_SERVER
        );

        self::assertTrue((bool) $isShared);
    }

    public function testBuildReusableResultFromIndexedCommandLine(): void
    {
        $inspector = new SharedSidecarInspector();
        $method = new \ReflectionMethod($inspector, 'buildReusableResultFromCommandLine');
        $method->setAccessible(true);
        $scope = \Weline\Server\Service\MasterProcess::getProjectScopeToken();

        $result = $method->invoke(
            $inspector,
            [
                'in_use' => true,
                'reusable' => false,
                'pid' => 0,
                'port' => 26422,
                'role' => '',
                'instance_name' => '',
                'token_file_name' => 'session_server.token',
                'process_name' => '',
                'command_line' => '',
            ],
            12345,
            '--name=weline-wls-session-default-' . $scope . ' --token-file-name=session_server.custom.token',
            ControlMessage::ROLE_SESSION_SERVER,
            'session_server.token'
        );

        self::assertTrue($result['reusable']);
        self::assertSame(12345, $result['pid']);
        self::assertSame(ControlMessage::ROLE_SESSION_SERVER, $result['role']);
        self::assertSame('session_server.custom.token', $result['token_file_name']);
        self::assertSame('weline-wls-session-default-' . $scope, $result['process_name']);
    }

    public function testBuildCommandLineFromIndexedProcessNameAddsSharedMarker(): void
    {
        $inspector = new SharedSidecarInspector();
        $method = new \ReflectionMethod($inspector, 'buildCommandLineFromIndexedProcessName');
        $method->setAccessible(true);
        $scope = \Weline\Server\Service\MasterProcess::getProjectScopeToken();

        self::assertSame(
            '--name=weline-wls-memory-default-' . $scope . ' --shared-service=1',
            $method->invoke($inspector, '--name=weline-wls-memory-default-' . $scope)
        );
    }
}
