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
}
