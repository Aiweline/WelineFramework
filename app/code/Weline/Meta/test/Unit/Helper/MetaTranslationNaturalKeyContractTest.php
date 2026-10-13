<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

/**
 * MetaTranslation must address w_meta_local by the five-column natural key
 * (meta_identify + locale + config_key + scope + version_id), never by a
 * meta_id triple that drops scope/version_id=0.
 */
final class MetaTranslationNaturalKeyContractTest extends TestCase
{
    public function testLoadAndMutatePathsUseNaturalKeyHelper(): void
    {
        $path = dirname(__DIR__, 3) . '/Helper/MetaTranslation.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('whereNaturalKey(', $src);
        self::assertStringContainsString(
            'function loadRow(',
            $src
        );
        self::assertMatchesRegularExpression(
            '/private static function loadRow\(\s*string \$metaIdentify/s',
            $src
        );
        self::assertStringNotContainsString(
            'loadRow($metaId,',
            $src
        );
    }
}
