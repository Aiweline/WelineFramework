<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\CuratedProductNameTranslation;

final class CuratedProductNameTranslationTest extends TestCase
{
    private const SOURCE = '民国风女装唐装素衣禅茶服改良汉服新中式上衣采耳足疗师工作服装';
    private const ENGLISH = 'Republican-Era Inspired Tea Ceremony Top';

    public function testItBackfillsAnExactSourceNameWhenTheLocaleIsMissingOrCopied(): void
    {
        $copy = new CuratedProductNameTranslation([self::SOURCE => self::ENGLISH]);

        self::assertSame(self::ENGLISH, $copy->replacement(self::SOURCE));
        self::assertSame(self::ENGLISH, $copy->replacement(self::SOURCE, ['value' => '']));
        self::assertSame(self::ENGLISH, $copy->replacement(self::SOURCE, ['value' => self::SOURCE]));
    }

    public function testItPreservesIndependentTranslationsAndClearedOverlays(): void
    {
        $copy = new CuratedProductNameTranslation([self::SOURCE => self::ENGLISH]);

        self::assertNull($copy->replacement(self::SOURCE, ['value' => 'Merchant-edited name']));
        self::assertNull($copy->replacement(self::SOURCE, ['value' => self::ENGLISH]));
        self::assertNull($copy->replacement(self::SOURCE, ['value' => null, 'cleared' => true]));
    }

    public function testTheCuratedCatalogProvidesActualEnglishProductNames(): void
    {
        $path = dirname(__DIR__, 3) . '/data/hanfu-product-en-names.php';
        self::assertFileExists($path);
        $names = require $path;
        $copy = new CuratedProductNameTranslation($names);

        self::assertSame(self::ENGLISH, $copy->replacement(self::SOURCE));
        self::assertSame(
            'Embroidered Two-Way Ruqun · Waist-High or Chest-High',
            $copy->replacement('齐胸襦裙 汉服女绣花齐腰齐胸两穿襦裙 日常汉元素表演服女装'),
        );
        self::assertNull($copy->replacement('Unreviewed new product'));
        foreach ($names as $source => $name) {
            self::assertNotSame('', trim($name), $source);
            self::assertDoesNotMatchRegularExpression('/\p{sc=Han}|^Product\s+[A-Z0-9-]+$/u', $name);
        }
    }
}
