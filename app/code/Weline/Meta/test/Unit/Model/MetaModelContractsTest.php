<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Model;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMetaModelContractsPass().
 */
final class MetaModelContractsTest extends TestCase
{
    public function testAllMetaModelContractsPass(): void
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
            'MetaLocalScopeVersion::testModelDeclaresScopeAndVersionOnIdentity' => function () {
            $this->assert_MetaLocalScopeVersion_testModelDeclaresScopeAndVersionOnIdentity();
        },
            'MetaLocalScopeVersion::testBackendFileUsesMetaTranslationNaturalKeyPath' => function () {
            $this->assert_MetaLocalScopeVersion_testBackendFileUsesMetaTranslationNaturalKeyPath();
        },
            'MetaLocalScopeVersion::testHelperExposesScopedApiAndDelegatesGc' => function () {
            $this->assert_MetaLocalScopeVersion_testHelperExposesScopedApiAndDelegatesGc();
        },
            'MetaLocalScopeVersion::testWordParserAndGcServiceExist' => function () {
            $this->assert_MetaLocalScopeVersion_testWordParserAndGcServiceExist();
        },
        ];
    }

private function assert_MetaLocalScopeVersion_testModelDeclaresScopeAndVersionOnIdentity(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Model/MetaLocal.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("schema_fields_SCOPE = 'scope'", $src);
    self::assertStringContainsString("schema_fields_VERSION_ID = 'version_id'", $src);
    self::assertStringContainsString('DEFAULT_SCOPE', $src);
    self::assertStringContainsString('DEFAULT_VERSION_ID', $src);
    self::assertStringContainsString('uk_meta_local_identify_locale_key_scope_version', $src);
    self::assertStringContainsString('idx_scope', $src);
    self::assertStringContainsString('idx_version_id', $src);
    self::assertStringContainsString("['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id']", $src);
    self::assertStringContainsString('function whereNaturalKey(', $src);
    self::assertStringNotContainsString("columns: ['meta_identify', 'locale_code', 'config_key'], type: 'UNIQUE'", $src);
}

private function assert_MetaLocalScopeVersion_testBackendFileUsesMetaTranslationNaturalKeyPath(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Controller/Backend/Config/File.php';
    $src = (string) file_get_contents($file);
    self::assertStringContainsString('MetaTranslation::setTranslatedValue(', $src);
    self::assertStringContainsString('MetaTranslation::getTranslatedValue(', $src);
    self::assertStringNotContainsString('forceCheck()->save()', $src);
    self::assertStringNotContainsString('MetaLocal::schema_fields_META_ID', $src);
}

private function assert_MetaLocalScopeVersion_testHelperExposesScopedApiAndDelegatesGc(): void
{
    $helper = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Helper/MetaTranslation.php';
    $src = (string) file_get_contents($helper);
    self::assertStringContainsString('function getTranslatedValueWithScope(', $src);
    self::assertStringContainsString('function setTranslatedValueWithScope(', $src);
    self::assertStringContainsString('function purgeByVersionId(', $src);
    self::assertStringContainsString('function purgeByScopePrefix(', $src);
    self::assertStringContainsString('MetaLocalGcService', $src);
    self::assertStringContainsString('MetaLocalWordParser', $src);
    self::assertStringContainsString('schema_fields_SCOPE', $src);
    self::assertStringContainsString('schema_fields_VERSION_ID', $src);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Setup/Upgrade.php');
    self::assertStringNotContainsString('w_meta_local', $upgrade);
    self::assertStringNotContainsString("schema_fields_VERSION_ID", $upgrade);
}

private function assert_MetaLocalScopeVersion_testWordParserAndGcServiceExist(): void
{
    $parser = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Service/MetaLocalWordParser.php';
    $gc = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Service/MetaLocalGcService.php';
    self::assertFileExists($parser);
    self::assertFileExists($gc);
    $parserSrc = (string) file_get_contents($parser);
    self::assertStringContainsString('function parseDictionaryWord(', $parserSrc);
    self::assertStringContainsString('function parsePath(', $parserSrc);
    $gcSrc = (string) file_get_contents($gc);
    self::assertStringContainsString('function purgeByVersionId(', $gcSrc);
    self::assertStringContainsString('function purgeByScopePrefix(', $gcSrc);
    self::assertStringContainsString('DEFAULT_SCOPE', $gcSrc);
}
}
