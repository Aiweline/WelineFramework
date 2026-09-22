<?php
declare(strict_types=1);

namespace Weline\Framework\Phrase\test;

use PHPUnit\Framework\TestCase;

/**
 * 契约：中文 locale 下模块 CSV 身份译（源=译）必须截断查找，
 * 禁止再落到 en_US locale/public 层把后台/前台刷成英文。
 */
final class ZhIdentityMustNotFallThroughToEnContractTest extends TestCase
{
    public function testTranslationFromLoadedLayersStopsOnZhModuleIdentity(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/Parser.php');
        if (!preg_match(
            '/private static function translationFromLoadedLayers\(.*?^\s{4}\}/ms',
            $source,
            $m,
        )) {
            self::fail('translationFromLoadedLayers not found');
        }
        $snippet = $m[0];
        self::assertStringContainsString('isChineseLocaleCode', $snippet);
        self::assertStringContainsString('untranslated placeholder', $snippet);
        // Must not only accept non-identity translations from modules (old bug).
        self::assertStringNotContainsString(
            '&& $translation !== \'\' && $translation !== $word) {' . "\n" . '                return $translation;',
            $snippet,
        );
    }

    public function testLoadLocaleWordsForLocaleChainAllowsZhIdentityToOverwriteEn(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/Parser.php');
        if (!preg_match(
            '/private static function loadLocaleWordsForLocaleChain\(.*?^\s{4}\}/ms',
            $source,
            $m,
        )) {
            self::fail('loadLocaleWordsForLocaleChain not found');
        }
        $snippet = $m[0];
        self::assertStringContainsString('array_merge', $snippet);
        self::assertMatchesRegularExpression('/\$words\s*=\s*\\\\array_merge\s*\(/', $snippet);
        self::assertDoesNotMatchRegularExpression('/mergePreferTranslatedWords\s*\(/', $snippet);
    }
}
