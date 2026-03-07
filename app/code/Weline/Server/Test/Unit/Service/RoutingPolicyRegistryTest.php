<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;

class RoutingPolicyRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        RoutingPolicyRegistry::clear();
    }

    public function testRegistryStoresAndResolvesPolicy(): void
    {
        RoutingPolicyRegistry::update([
            'routing' => [
                'session' => ['hijack_file_driver' => true],
                'cache' => ['hijack_file_driver' => false],
            ],
            'endpoints' => [
                'session' => ['host' => '127.0.0.1', 'port' => 21970],
                'memory' => ['host' => '127.0.0.1', 'port' => 21971],
            ],
        ]);

        $this->assertTrue(RoutingPolicyRegistry::shouldHijackSessionFile());
        $this->assertFalse(RoutingPolicyRegistry::shouldHijackCacheFile());
        $this->assertSame(['host' => '127.0.0.1', 'port' => 21970], RoutingPolicyRegistry::getSessionEndpoint());
        $this->assertSame(['host' => '127.0.0.1', 'port' => 21971], RoutingPolicyRegistry::getMemoryEndpoint());
    }
}

