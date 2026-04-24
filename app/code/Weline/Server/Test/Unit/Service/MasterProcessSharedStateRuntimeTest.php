<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterProcess;

final class MasterProcessSharedStateRuntimeTest extends TestCase
{
    public function testApplySharedStateRuntimeConfigExposesRuntimeMetadataToServiceContext(): void
    {
        $master = new MasterProcess();
        $this->writePrivate($master, 'config', [
            'session_server_port' => 19970,
            'session_server_token_file_name' => 'session_server.shared.token',
            'memory_server_port' => 19971,
            'memory_server_token_file_name' => 'memory_server.shared.token',
            'shared_state' => [
                'session' => [
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.shared.token',
                    'reuse_existing' => true,
                    'process_name' => 'weline-wls-session-shared-19970',
                    'instance_name' => 'shared-session-19970',
                    'shared_service' => true,
                ],
                'memory' => [
                    'host' => '127.0.0.1',
                    'port' => 19971,
                    'token_file_name' => 'memory_server.shared.token',
                    'created_now' => true,
                    'process_name' => 'weline-wls-memory-shared-19971',
                    'instance_name' => 'shared-memory-19971',
                    'shared_service' => true,
                ],
            ],
        ]);

        $envConfig = $this->invokePrivate($master, 'applySharedStateRuntimeConfig', []);

        self::assertSame('127.0.0.1', $envConfig['wls']['shared_state']['runtime']['session']['host']);
        self::assertSame(19970, $envConfig['wls']['shared_state']['runtime']['session']['port']);
        self::assertSame('session_server.shared.token', $envConfig['wls']['shared_state']['runtime']['session']['token_file_name']);
        self::assertTrue((bool) ($envConfig['wls']['shared_state']['runtime']['session']['reuse_existing'] ?? false));
        self::assertSame('shared-session-19970', $envConfig['wls']['shared_state']['runtime']['session']['instance_name']);
        self::assertTrue((bool) ($envConfig['wls']['shared_state']['runtime']['memory']['created_now'] ?? false));
        self::assertSame('shared-memory-19971', $envConfig['wls']['shared_state']['runtime']['memory']['instance_name']);
    }

    public function testApplyRuntimeWlsConfigExposesMemoryLimitsToServiceContext(): void
    {
        $master = new MasterProcess();
        $this->writePrivate($master, 'config', [
            'worker_memory_limit' => '768',
            'dispatcher_memory_limit' => '1g',
        ]);

        $envConfig = $this->invokePrivate($master, 'applyRuntimeWlsConfig', []);

        self::assertSame('768M', $envConfig['wls']['worker_memory_limit']);
        self::assertSame('1G', $envConfig['wls']['dispatcher_memory_limit']);
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
