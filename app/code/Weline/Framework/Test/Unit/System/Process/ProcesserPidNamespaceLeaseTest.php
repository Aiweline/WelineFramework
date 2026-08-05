<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserPidNamespaceLeaseTest extends TestCase
{
    public function testLinuxManagedRecordCarriesPidNamespaceIdentity(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux PID namespaces are not available on this platform.');
        }

        $namespaceId = $this->invokePrivateStatic('readPidNamespaceIdentity', [\getmypid()]);
        if (!\is_string($namespaceId) || $namespaceId === '') {
            self::markTestSkipped('The current Linux runtime does not expose /proc PID namespace identity.');
        }

        $record = $this->invokePrivateStatic('buildProcessIdentityRecord', [
            '--name=weline-namespace-test --launch-id=namespace-test --epoch=1',
            \getmypid(),
            'weline-namespace-test',
        ]);

        self::assertIsArray($record);
        self::assertSame($namespaceId, $record['pid_namespace_id'] ?? null);
    }

    public function testGcRecognizesOnlyARecordedForeignPidNamespaceAsForeign(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux PID namespaces are not available on this platform.');
        }

        $namespaceId = $this->invokePrivateStatic('readPidNamespaceIdentity');
        if (!\is_string($namespaceId) || $namespaceId === '') {
            self::markTestSkipped('The current Linux runtime does not expose /proc PID namespace identity.');
        }

        self::assertFalse($this->invokePrivateStatic('isForeignPidNamespaceRecord', [[]]));
        self::assertFalse($this->invokePrivateStatic('isForeignPidNamespaceRecord', [[
            'pid_namespace_id' => $namespaceId,
        ]]));
        self::assertTrue($this->invokePrivateStatic('isForeignPidNamespaceRecord', [[
            'pid_namespace_id' => $namespaceId === 'pid:[1]' ? 'pid:[2]' : 'pid:[1]',
        ]]));
    }

    private function invokePrivateStatic(string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod(Processer::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$arguments);
    }
}
