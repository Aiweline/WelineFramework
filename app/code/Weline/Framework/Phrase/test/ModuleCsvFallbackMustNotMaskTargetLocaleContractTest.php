<?php
declare(strict_types=1);

namespace Weline\Framework\Phrase\test;

use PHPUnit\Framework\TestCase;

/**
 * 契约：目标 locale（hi_IN/ar_SA）已有全局译文时，不得被 en_US 模块 CSV 热层抢先盖住。
 */
final class ModuleCsvFallbackMustNotMaskTargetLocaleContractTest extends TestCase
{
    public function testLoadModuleWordsForLocaleChainOnlyReadsTargetLocaleCsv(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/Parser.php');
        if (!preg_match(
            '/private static function loadModuleWordsForLocaleChain\(.*?^\s{4}\}/ms',
            $source,
            $m,
        )) {
            self::fail('loadModuleWordsForLocaleChain not found');
        }
        $snippet = $m[0];
        self::assertStringContainsString('$targetLocale', $snippet);
        self::assertStringContainsString('loadModuleWordsWithSharedCache($moduleName, $targetLocale', $snippet);
        self::assertStringNotContainsString('array_reverse($locales)', $snippet);
    }
}
