<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Output\Cli;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Output\Cli\Printing;

final class PrintingColorizeNoColorTest extends TestCase
{
    private mixed $previousNoColor = false;

    protected function setUp(): void
    {
        $this->previousNoColor = \getenv('NO_COLOR');
        \putenv('NO_COLOR=1');
    }

    protected function tearDown(): void
    {
        if ($this->previousNoColor === false) {
            \putenv('NO_COLOR');
            return;
        }
        \putenv('NO_COLOR=' . (string)$this->previousNoColor);
    }

    public function testColorizeIsPlainWhenNoColorIsSet(): void
    {
        $printer = new Printing();
        $text = $printer->colorize('服务器实例列表', Printing::NOTE);
        self::assertSame('服务器实例列表', $text);
        self::assertStringNotContainsString("\033", $text);
        self::assertStringNotContainsString('[34m', $text);
    }
}
