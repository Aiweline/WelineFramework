<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Test\Console\E2e\Run;

final class E2eRunArgumentsTest extends TestCase
{
    public function testWorkersOneInlineValueSurvivesBooleanCliNormalization(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'buildPlaywrightArgs');
        $arguments = $method->invoke($run, [
            'command' => 'e2e:run',
            'workers' => true,
        ], [
            'spec' => '',
            'case' => '',
            'case_id' => '',
        ]);

        self::assertContains('--workers=1', $arguments);
        self::assertNotContains('--workers', $arguments);
    }

    public function testDefaultDisplayModeIsHeadlessWithoutHeadedFlag(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveDisplayMode');
        $mode = $method->invoke($run, ['--project=chromium'], ['headless' => false]);

        self::assertTrue($mode['headless']);
        self::assertSame('1', $mode['env']['PLAYWRIGHT_HEADLESS'] ?? null);
        self::assertFalse($mode['append_headed']);
    }

    public function testExplicitHeadedDisablesDefaultHeadlessEnv(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveDisplayMode');
        $mode = $method->invoke($run, ['--headed'], ['headless' => false]);

        self::assertFalse($mode['headless']);
        self::assertArrayNotHasKey('PLAYWRIGHT_HEADLESS', $mode['env']);
        self::assertFalse($mode['append_headed']);
    }

    public function testExplicitHeadlessFlagForcesHeadlessEvenWithHeadedArg(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveDisplayMode');
        $mode = $method->invoke($run, ['--headed'], ['headless' => true]);

        self::assertTrue($mode['headless']);
        self::assertSame('1', $mode['env']['PLAYWRIGHT_HEADLESS'] ?? null);
    }
}
