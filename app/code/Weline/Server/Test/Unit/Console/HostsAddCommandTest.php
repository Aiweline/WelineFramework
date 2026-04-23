<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Hosts\Add;

final class HostsAddCommandTest extends TestCase
{
    public function testIsEligibleLocalHostname_acceptsWelineSubdomain(): void
    {
        $this->assertTrue(Add::isEligibleLocalHostname('p01234567.weline.test'));
        $this->assertTrue(Add::isEligibleLocalHostname('shop-1.weline.test'));
        $this->assertTrue(Add::isEligibleLocalHostname('queued-phase-flow.local.test'));
    }

    public function testIsEligibleLocalHostname_rejectsNonLocal(): void
    {
        $this->assertFalse(Add::isEligibleLocalHostname('example.com'));
        $this->assertFalse(Add::isEligibleLocalHostname('localhost'));
        $this->assertFalse(Add::isEligibleLocalHostname(''));
    }

    public function testIsEligibleLocalHostname_rejectsInvalidChars(): void
    {
        $this->assertFalse(Add::isEligibleLocalHostname('bad..x.local'));
        $this->assertFalse(Add::isEligibleLocalHostname('-bad.weline.test'));
    }
}
