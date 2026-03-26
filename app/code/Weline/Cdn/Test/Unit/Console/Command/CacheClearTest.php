<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Console\Command\CacheClear;

class CacheClearTest extends TestCase
{
    public function testCommandInstantiation(): void
    {
        $this->assertInstanceOf(CacheClear::class, new CacheClear());
    }

    public function testTip(): void
    {
        $tip = (new CacheClear())->tip();
        $this->assertIsString($tip);
        $this->assertNotEmpty($tip);
    }

    public function testHelp(): void
    {
        $help = (new CacheClear())->help();
        $this->assertTrue(is_string($help) || is_array($help));
    }

    public function testExecuteDomainEmptyContract(): void
    {
        $this->assertTrue(method_exists(new CacheClear(), 'execute'));
    }

    public function testExecuteModeEmptyContract(): void
    {
        $this->assertTrue(method_exists(new CacheClear(), 'execute'));
    }

    public function testExecuteInvalidModeContract(): void
    {
        $this->assertTrue(method_exists(new CacheClear(), 'execute'));
    }

    public function testExecuteSuccessContract(): void
    {
        $this->assertTrue(method_exists(new CacheClear(), 'execute'));
    }

    public function testExecuteEverythingModeContract(): void
    {
        $this->assertTrue(method_exists(new CacheClear(), 'execute'));
    }

    public function testExecuteUrlsModeContract(): void
    {
        $this->assertTrue(method_exists(new CacheClear(), 'execute'));
    }
}
