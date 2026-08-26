<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Compare\Service\CompareNumericValueParser;

final class CompareNumericValueParserTest extends TestCase
{
    private CompareNumericValueParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CompareNumericValueParser();
    }

    public function testParsesStorageUnitsForComparison(): void
    {
        self::assertSame(128.0, $this->parser->parse('128GB'));
        self::assertSame(1024.0, $this->parser->parse('1TB'));
        self::assertGreaterThan($this->parser->parse('128GB'), $this->parser->parse('1TB'));
    }

    public function testParsesPlainNumbersAndLocalizedUnits(): void
    {
        self::assertSame(12.0, $this->parser->parse('12 个月'));
        self::assertSame(24.0, $this->parser->parse('24月'));
        self::assertSame(45.0, $this->parser->parse('45dB'));
        self::assertSame(2.5, $this->parser->parse('2.5kg'));
    }

    public function testReturnsNullForNonNumericText(): void
    {
        self::assertNull($this->parser->parse('羊毛'));
        self::assertNull($this->parser->parse('—'));
        self::assertNull($this->parser->parse(''));
    }
}
