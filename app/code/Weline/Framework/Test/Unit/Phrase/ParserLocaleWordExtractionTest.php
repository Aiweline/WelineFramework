<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\Parser;

final class ParserLocaleWordExtractionTest extends TestCase
{
    public function testFlatteningKeepsTranslatedValuesAndLaterTranslatedOverrides(): void
    {
        $words = [
            'Weline_First' => ['保留' => 'Keep', '覆盖' => 'Old', '补全' => '补全', 7 => 'ignored'],
            '保留' => '保留',
            '覆盖' => 'New',
            '补全' => 'Translated',
            'Weline_Last' => ['保留' => '保留', '新增' => 'Added', '无效' => ['nested']],
        ];
        $original = $words;
        self::assertSame(['保留' => 'Keep', '覆盖' => 'New', '补全' => 'Translated', '新增' => 'Added'], $this->extract($words));
        self::assertSame($original, $words);
    }

    public function testSelectedModulesOverrideFlatFallbackWithoutLoadingOtherModules(): void
    {
        $words = [
            '保留' => 'Keep', '覆盖' => 'Old', '补全' => '补全',
            'Weline_Theme' => ['保留' => '保留', '覆盖' => 'New', '补全' => 'Translated'],
            'Weline_Other' => ['非选中' => 'Not selected'],
            7 => 'ignored',
        ];
        self::assertSame(['保留' => 'Keep', '覆盖' => 'New', '补全' => 'Translated'], $this->extract($words, ['Weline_Theme']));
    }

    public function testLargeFlatDictionaryDoesNotCopyTheGrowingMapForEveryWord(): void
    {
        $words = [];
        for ($i = 0; $i < 24000; ++$i) { $words['source-' . $i] = 'translated-' . $i; }
        $start = hrtime(true);
        $actual = $this->extract($words);
        $seconds = (hrtime(true) - $start) / 1e9;
        self::assertSame($words, $actual);
        // Generous ceiling for a small in-memory map; the quadratic regression takes seconds.
        self::assertLessThan(2.0, $seconds);
    }

    private function extract(array $words, array $modules = []): array
    {
        return (new \ReflectionMethod(Parser::class, 'extractModuleWords'))->invoke(null, $words, $modules);
    }
}
