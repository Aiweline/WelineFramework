<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\PassthroughCore;
use Weline\Server\Log\WlsLogger;

final class PassthroughCoreMaintenanceLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        WlsLogger::reset();
        WlsLogger::getInstance()
            ->setStdoutEnabled(false)
            ->setFileEnabled(false);
    }

    protected function tearDown(): void
    {
        WlsLogger::reset();
    }

    public function testFormatMaintenanceLogContextIncludesPoolsAndDedicatedPort(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);

        $this->writePrivate($core, 'workerPorts', [19983, 19982]);
        $this->writePrivate($core, 'maintenanceWorkerPorts', [19003, 19002]);
        $this->writePrivate($core, 'maintenancePort', 19999);

        $context = (string) $this->invokePrivate($core, 'formatMaintenanceLogContext');

        self::assertStringContainsString('business_pool=19982,19983', $context);
        self::assertStringContainsString('maintenance_candidates=19002,19003', $context);
        self::assertStringContainsString('maintenance_port=19999', $context);
    }

    public function testLogMaintenanceDecisionStoresThrottleTimestampByKey(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);

        $this->invokePrivate($core, 'logMaintenanceDecision', [
            'unit-key',
            'first message',
            'INFO',
            10.0,
        ]);
        $loggedAt = (array) $this->readPrivate($core, 'maintenanceDecisionLoggedAt');
        self::assertArrayHasKey('unit-key', $loggedAt);
        $first = (float) $loggedAt['unit-key'];

        $this->invokePrivate($core, 'logMaintenanceDecision', [
            'unit-key',
            'second message',
            'INFO',
            10.0,
        ]);
        $loggedAt = (array) $this->readPrivate($core, 'maintenanceDecisionLoggedAt');

        self::assertSame($first, (float) $loggedAt['unit-key']);
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
