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
            'background-optimize' => true,
            '--background-optimize' => true,
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

    public function testOptimizationRunsSynchronouslyByDefaultAndBackgroundRequiresExplicitOptIn(): void
    {
        $method = new \ReflectionMethod($this->upgrade, 'shouldRunBackgroundOptimize');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($this->upgrade, []));
        self::assertTrue($method->invoke($this->upgrade, ['background-optimize' => true]));
        self::assertFalse($method->invoke($this->upgrade, [
            'background-optimize' => true,
            'sync' => true,
        ]));
        self::assertFalse($method->invoke($this->upgrade, [
            'background-optimize' => true,
            'skip-background-optimize' => true,
        ]));
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

    public function testRouteOnlyRequestDoesNotImplyModelOrSchemaUpgrade(): void
    {
        $method = new \ReflectionMethod($this->upgrade, 'isRouteOnlyUpgradeRequest');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($this->upgrade, ['route' => true]));
        self::assertFalse($method->invoke($this->upgrade, ['route' => true, 'model' => true]));
        self::assertFalse($method->invoke($this->upgrade, ['model' => true]));
    }

    public function testModuleMetadataNeedsRefreshWhenManifestVersionChanged(): void
    {
        $directory = sys_get_temp_dir() . '/weline-upgrade-manifest-' . bin2hex(random_bytes(6));
        mkdir($directory . '/etc', 0777, true);
        file_put_contents($directory . '/etc/module.php', <<<'PHP'
<?php
return [
    'name' => 'Weline_Example',
    'version' => '1.0.1',
    'requires' => ['Weline_Framework' => '*'],
];
PHP);

        try {
            $method = new \ReflectionMethod($this->upgrade, 'moduleMetadataNeedsRefresh');
            self::assertTrue($method->invoke(
                $this->upgrade,
                ['name' => 'Weline_Example', 'version' => '1.0.0', 'dependencies' => ['Weline_Framework']],
                $directory . '/register.php',
            ));
            self::assertFalse($method->invoke(
                $this->upgrade,
                ['name' => 'Weline_Example', 'version' => '1.0.1', 'dependencies' => ['Weline_Framework']],
                $directory . '/register.php',
            ));
        } finally {
            unlink($directory . '/etc/module.php');
            rmdir($directory . '/etc');
            rmdir($directory);
        }
    }
}
