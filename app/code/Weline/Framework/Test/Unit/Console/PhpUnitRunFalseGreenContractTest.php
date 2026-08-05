<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class PhpUnitRunFalseGreenContractTest extends TestCase
{
    public function testLegacyCommandRegistrationResolvesToCanonicalRunner(): void
    {
        $reflection = new \ReflectionClass(
            \Weline\Framework\UnitTest\Console\PhpUnit\Run::class
        );

        self::assertStringStartsWith(
            BP . 'app' . DS . 'code' . DS,
            (string)$reflection->getFileName(),
            'phpunit:run 不得回落到 vendor 中的历史实现。'
        );
        self::assertTrue($reflection->isSubclassOf(
            \Weline\Framework\Test\Console\PhpUnit\Run::class
        ));
    }

    public function testCanonicalRunnerRejectsZeroTestOutput(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/Console/PhpUnit/Run.php'
        );

        self::assertStringContainsString('outputConfirmsTestExecution', $source);
        self::assertStringContainsString('测试运行器未确认执行任何测试', $source);
        self::assertStringContainsString('$exitCode = 2', $source);
        self::assertStringContainsString(
            '$totalTests === 0 && $expectedTotalTests > 0',
            $source,
            '报告必须优先采用 PHPUnit 的实际 case 数，禁止出现超过 100% 的假统计。'
        );
    }
}
