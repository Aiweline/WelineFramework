<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Console\Setup;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Console\Setup\Upgrade;

class UpgradeRegistryDedupeContractTest extends TestCase
{
    private Upgrade $upgrade;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var Printing&MockObject $printing */
        $printing = $this->createMock(Printing::class);
        $this->upgrade = new Upgrade($printing);
    }

    public function testResolveRegistryCollectScopeSkipsFullWhenMinusMUnknown(): void
    {
        $method = new \ReflectionMethod($this->upgrade, 'resolveRegistryCollectScope');
        $method->setAccessible(true);

        self::assertSame(
            ['modules' => [], 'skip_bootstrap_collect' => false],
            $method->invoke($this->upgrade, [], ['Weline_Theme'])
        );
        self::assertSame(
            ['modules' => ['Weline_Theme'], 'skip_bootstrap_collect' => false],
            $method->invoke($this->upgrade, ['Weline_Theme', 'Ghost'], ['Weline_Theme', 'Weline_Faq'])
        );
        self::assertSame(
            ['modules' => [], 'skip_bootstrap_collect' => true],
            $method->invoke($this->upgrade, ['Ghost_Module'], ['Weline_Theme'])
        );
        self::assertSame(
            ['modules' => ['Ghost_Module'], 'skip_bootstrap_collect' => false],
            $method->invoke($this->upgrade, ['Ghost_Module'], [])
        );
    }

    public function testUpgradeSourceRemovesMidDuplicateAndSetsStep2Flag(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 5) . '/Setup/Console/Setup/Upgrade.php'
        );
        self::assertIsString($src);
        self::assertStringNotContainsString(
            "section('setup:upgrade module registry incremental refresh')",
            $src
        );
        self::assertStringContainsString('跳过重复的中段注册表增量', $src);
        self::assertStringContainsString("section('setup:upgrade step 2 incremental registry refresh')", $src);
        self::assertMatchesRegularExpression(
            '/setup:upgrade step 2 (?:incremental|full) registry refresh[\s\S]{0,1500}registryCollectedInThisRun\s*=\s*true/',
            $src
        );
        self::assertStringContainsString('route-only registry incremental', $src);
    }
}
