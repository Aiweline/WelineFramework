<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMetaHelperContractsPass().
 */
final class MetaHelperContractsTest extends TestCase
{
    public function testAllMetaHelperContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'MetaTranslationNaturalKey::testLoadAndMutatePathsUseNaturalKeyHelper' => function () {
            $this->assert_MetaTranslationNaturalKey_testLoadAndMutatePathsUseNaturalKeyHelper();
        },
            'MetaTranslationRequestMemo::testFindRowValueMemoizesPerRequestAndParamBag' => function () {
            $this->assert_MetaTranslationRequestMemo_testFindRowValueMemoizesPerRequestAndParamBag();
        },
        ];
    }

private function assert_MetaTranslationNaturalKey_testLoadAndMutatePathsUseNaturalKeyHelper(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Helper/MetaTranslation.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('whereNaturalKey(', $src);
    self::assertStringContainsString('function loadRow(', $src);
    self::assertMatchesRegularExpression('/private static function loadRow\(\s*string \$metaIdentify/s', $src);
    self::assertStringNotContainsString('loadRow($metaId,', $src);
}

private function assert_MetaTranslationRequestMemo_testFindRowValueMemoizesPerRequestAndParamBag(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Helper/MetaTranslation.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('private const REQUEST_MEMO_KEY', $src);
    self::assertStringContainsString('private const REQUEST_PARAM_MAP_KEY', $src);
    self::assertStringContainsString('RequestContext::isInitialized()', $src);
    self::assertStringContainsString('array_key_exists($memoKey, $memo)', $src);
    self::assertStringContainsString('str_starts_with($configKey, \'param.\')', $src);
    self::assertStringContainsString('private static function loadParamMap(', $src);
    self::assertStringContainsString('->where(MetaLocal::schema_fields_META_IDENTIFY, $metaIdentify)', $src);
    self::assertStringContainsString('$map[$rowLocale][$scope][$versionId][$key]', $src);
    self::assertStringContainsString('$paramMaps[$locale][$scope][$versionId][$configKey] ?? null', $src);
    self::assertStringContainsString('private static function findRowValue(', $src);
    self::assertStringContainsString('return self::findRowValue(', $src);
    $findStart = strpos($src, 'public static function findTranslatedValueWithScope');
    $findEnd = strpos($src, 'public static function deleteTranslatedValueWithScope');
    self::assertIsInt($findStart);
    self::assertIsInt($findEnd);
    $findBody = substr($src, (int)$findStart, (int)$findEnd - (int)$findStart);
    self::assertStringContainsString('self::findRowValue(', $findBody);
    self::assertStringNotContainsString('whereNaturalKey(', $findBody);
    self::assertStringContainsString('RequestContext::set(self::REQUEST_MEMO_KEY, $memo)', $src);
    self::assertStringContainsString('RequestContext::set(self::REQUEST_PARAM_MAP_KEY, $maps)', $src);
    self::assertStringContainsString('self::forgetRequestMemo();', $src);
    self::assertStringContainsString('RequestContext::set(self::REQUEST_MEMO_KEY, [])', $src);
    self::assertStringContainsString('RequestContext::set(self::REQUEST_PARAM_MAP_KEY, [])', $src);
}
}
