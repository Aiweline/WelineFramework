<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

final class MetaLocalScopeVersionContractTest extends TestCase
{
    public function testModelDeclaresScopeAndVersionOnIdentity(): void
    {
        $path = dirname(__DIR__, 3) . '/Model/MetaLocal.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("schema_fields_SCOPE = 'scope'", $src);
        self::assertStringContainsString("schema_fields_VERSION_ID = 'version_id'", $src);
        self::assertStringContainsString('DEFAULT_SCOPE', $src);
        self::assertStringContainsString('DEFAULT_VERSION_ID', $src);
        self::assertStringContainsString('uk_meta_local_identify_locale_key_scope_version', $src);
        self::assertStringContainsString('idx_scope', $src);
        self::assertStringContainsString('idx_version_id', $src);
        self::assertStringContainsString(
            "['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id']",
            $src
        );
        self::assertStringContainsString('function whereNaturalKey(', $src);
        self::assertStringNotContainsString(
            "columns: ['meta_identify', 'locale_code', 'config_key'], type: 'UNIQUE'",
            $src
        );
    }

    public function testBackendFileUsesMetaTranslationNaturalKeyPath(): void
    {
        $file = dirname(__DIR__, 3) . '/Controller/Backend/Config/File.php';
        $src = (string)file_get_contents($file);

        self::assertStringContainsString('MetaTranslation::setTranslatedValue(', $src);
        self::assertStringContainsString('MetaTranslation::getTranslatedValue(', $src);
        self::assertStringNotContainsString('forceCheck()->save()', $src);
        self::assertStringNotContainsString('MetaLocal::schema_fields_META_ID', $src);
    }

    public function testHelperExposesScopedApiAndDelegatesGc(): void
    {
        $helper = dirname(__DIR__, 3) . '/Helper/MetaTranslation.php';
        $src = (string)file_get_contents($helper);

        self::assertStringContainsString('function getTranslatedValueWithScope(', $src);
        self::assertStringContainsString('function setTranslatedValueWithScope(', $src);
        self::assertStringContainsString('function purgeByVersionId(', $src);
        self::assertStringContainsString('function purgeByScopePrefix(', $src);
        self::assertStringContainsString('MetaLocalGcService', $src);
        self::assertStringContainsString('MetaLocalWordParser', $src);
        self::assertStringContainsString('schema_fields_SCOPE', $src);
        self::assertStringContainsString('schema_fields_VERSION_ID', $src);

        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringNotContainsString('w_meta_local', $upgrade);
        self::assertStringNotContainsString("schema_fields_VERSION_ID", $upgrade);
    }

    public function testWordParserAndGcServiceExist(): void
    {
        $parser = dirname(__DIR__, 3) . '/Service/MetaLocalWordParser.php';
        $gc = dirname(__DIR__, 3) . '/Service/MetaLocalGcService.php';
        self::assertFileExists($parser);
        self::assertFileExists($gc);
        $parserSrc = (string)file_get_contents($parser);
        self::assertStringContainsString('function parseDictionaryWord(', $parserSrc);
        self::assertStringContainsString('function parsePath(', $parserSrc);
        $gcSrc = (string)file_get_contents($gc);
        self::assertStringContainsString('function purgeByVersionId(', $gcSrc);
        self::assertStringContainsString('function purgeByScopePrefix(', $gcSrc);
        self::assertStringContainsString('DEFAULT_SCOPE', $gcSrc);
    }
}
