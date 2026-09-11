<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\I18nCsvCodec;

final class I18nCsvCodecTest extends TestCase
{
    public function testIsGarbledTextDetectsBomAndReplacementChar(): void
    {
        self::assertTrue(I18nCsvCodec::isGarbledText(I18nCsvCodec::UTF8_BOM . '无权限访问重定向前'));
        self::assertTrue(I18nCsvCodec::isGarbledText("坏\u{FFFD}键"));
        self::assertFalse(I18nCsvCodec::isGarbledText('无权限访问重定向前'));
        self::assertSame('', I18nCsvCodec::normalizeWord(I18nCsvCodec::UTF8_BOM . '权限'));
    }

    public function testIsJunkTranslationRejectsIncompleteJsonFragments(): void
    {
        self::assertTrue(I18nCsvCodec::isJunkTranslation('['));
        self::assertTrue(I18nCsvCodec::isJunkTranslation(']'));
        self::assertTrue(I18nCsvCodec::isJunkTranslation('{}'));
        self::assertTrue(I18nCsvCodec::isJunkTranslation('","'));
        self::assertFalse(I18nCsvCodec::isJunkTranslation('VIP0 初级'));
        self::assertFalse(I18nCsvCodec::isJunkTranslation('ভিআইপি০ প্রাথমিক'));
        self::assertFalse(I18nCsvCodec::isJunkTranslation(''));
    }

    public function testReadWordsDropsGarbledRowsInsteadOfSalvaging(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'i18n-bom-');
        self::assertNotFalse($tmp);

        // One file-level BOM, then a garbled key row, then a clean row.
        $payload = I18nCsvCodec::UTF8_BOM
            . I18nCsvCodec::UTF8_BOM . "无权限访问重定向前,\"第一次\"\n"
            . str_repeat(I18nCsvCodec::UTF8_BOM, 3) . "无权限访问重定向前,\"第二次\"\n"
            . "按模块筛选,\"Фильтр\"\n";
        file_put_contents($tmp, $payload);

        try {
            $words = I18nCsvCodec::readWords($tmp);
            self::assertArrayNotHasKey('无权限访问重定向前', $words);
            self::assertSame(['按模块筛选' => 'Фильтр'], $words);
        } finally {
            @unlink($tmp);
        }
    }

    public function testWriteWordsDropsGarbledKeysAndDoesNotWriteThem(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'i18n-round-');
        self::assertNotFalse($tmp);

        try {
            $map = [
                I18nCsvCodec::UTF8_BOM . '元数据' => 'Метаданные',
                '按模块筛选' => 'Фильтр по модулям',
            ];
            I18nCsvCodec::writeWords($tmp, $map);
            $raw = (string)file_get_contents($tmp);
            self::assertTrue(str_starts_with($raw, I18nCsvCodec::UTF8_BOM));
            self::assertStringNotContainsString("\n" . I18nCsvCodec::UTF8_BOM, $raw);
            self::assertStringNotContainsString('元数据', $raw);

            $words = I18nCsvCodec::readWords($tmp);
            self::assertSame(['按模块筛选' => 'Фильтр по модулям'], $words);
        } finally {
            @unlink($tmp);
        }
    }

    public function testSanitizeFileDropsGarbledRows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'i18n-sanitize-');
        self::assertNotFalse($tmp);
        file_put_contents(
            $tmp,
            I18nCsvCodec::UTF8_BOM
            . str_repeat(I18nCsvCodec::UTF8_BOM, 2) . "权限,\"A\"\n"
            . "干净键,\"B\"\n"
            . str_repeat(I18nCsvCodec::UTF8_BOM, 2) . "权限,\"C\"\n"
        );

        try {
            $result = I18nCsvCodec::sanitizeFile($tmp);
            self::assertTrue($result['changed']);
            self::assertSame(1, $result['words']);
            self::assertGreaterThanOrEqual(2, $result['dropped']);
            self::assertSame(['干净键' => 'B'], I18nCsvCodec::readWords($tmp));
        } finally {
            @unlink($tmp);
        }
    }
}
