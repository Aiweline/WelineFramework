<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Console\Setup;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Console\Setup\Upgrade;

class UpgradeArgsValidationTest extends TestCase
{
    private Upgrade $upgrade;

    /** @var Printing&MockObject */
    private Printing $printing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->printing = $this->createMock(Printing::class);
        $this->upgrade = new Upgrade($this->printing);
    }

    public function testValidateSupportedArgsAcceptsPrefixedAndNormalizedKeys(): void
    {
        $args = [
            'command' => 'setup:upgrade',
            'route' => true,
            '--route' => true,
            'model' => true,
            '--model' => true,
            'module' => 'Weline_DataTable',
            '--module' => 'Weline_DataTable',
            'stage' => 'schema_diff',
            '--stage' => 'schema_diff',
            'skip-env-check' => true,
            '--skip-env-check' => true,
            'help' => true,
            '--help' => true,
            'h' => true,
            '-h' => true,
        ];

        $method = new \ReflectionMethod($this->upgrade, 'validateSupportedArgs');
        $method->setAccessible(true);
        $method->invoke($this->upgrade, $args);

        $this->addToAssertionCount(1);
    }

    public function testValidateSupportedArgsStillRejectsUnknownPrefixedKey(): void
    {
        $args = [
            'command' => 'setup:upgrade',
            '--unknown-option' => true,
        ];

        $method = new \ReflectionMethod($this->upgrade, 'validateSupportedArgs');
        $method->setAccessible(true);

        $this->expectException(\Weline\Framework\App\Exception::class);
        $this->expectExceptionMessage('--unknown-option');

        $method->invoke($this->upgrade, $args);
    }
}
