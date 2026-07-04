<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\SingleFlightCoordinator;

class SingleFlightCoordinatorTest extends TestCase
{
    public function testFirstAcquireReturnsTokenAndSecondReturnsNullUntilReleased(): void
    {
        $coordinator = new SingleFlightCoordinator();

        $token = $coordinator->acquire('sf_key_basic', timeoutMs: 0, ttlSeconds: 5);
        $this->assertNotNull($token, 'first acquire should obtain the lock token');

        $second = $coordinator->acquire('sf_key_basic', timeoutMs: 0, ttlSeconds: 5);
        $this->assertNull($second, 'second acquire should fail while held');

        $coordinator->release('sf_key_basic', $token);

        $third = $coordinator->acquire('sf_key_basic', timeoutMs: 0, ttlSeconds: 5);
        $this->assertNotNull($third, 'after release, lock can be re-acquired');
        $coordinator->release('sf_key_basic', $third);
    }

    public function testIsolatesAcrossKeys(): void
    {
        $coordinator = new SingleFlightCoordinator();

        $tokenA = $coordinator->acquire('sf_key_a', timeoutMs: 0, ttlSeconds: 5);
        $tokenB = $coordinator->acquire('sf_key_b', timeoutMs: 0, ttlSeconds: 5);

        $this->assertNotNull($tokenA);
        $this->assertNotNull($tokenB);
        $this->assertNotSame($tokenA, $tokenB);

        $coordinator->release('sf_key_a', $tokenA);
        $coordinator->release('sf_key_b', $tokenB);
    }

    public function testReleaseWithWrongTokenIsNoop(): void
    {
        $coordinator = new SingleFlightCoordinator();

        $token = $coordinator->acquire('sf_wrong_token_key', timeoutMs: 0, ttlSeconds: 5);
        $this->assertNotNull($token);

        $coordinator->release('sf_wrong_token_key', 'not-the-real-token');

        $denied = $coordinator->acquire('sf_wrong_token_key', timeoutMs: 0, ttlSeconds: 5);
        $this->assertNull($denied, 'lock must remain held when wrong token is released');

        $coordinator->release('sf_wrong_token_key', $token);
    }
}
