<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Source contract: setDefaultModel must rewrite in place (uk_service_type_priority).
 */
final class DefaultModelManagerSetDefaultContractTest extends TestCase
{
    public function testSetDefaultModelRewritesExistingServiceTypeRow(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Ai/Service/DefaultModelManager.php'
        );

        self::assertStringContainsString('deleteSiblingRowsForServiceType', $source);
        self::assertStringContainsString("schema_fields_ID, \$keepId, '!='", $source);
        self::assertStringContainsString('uk_service_type_priority', $source);
        // setDefaultModel must not clearDefaults()-then-insert (collides on same priority).
        $methodPos = strpos($source, 'function setDefaultModel(');
        self::assertNotFalse($methodPos);
        $methodChunk = substr($source, (int)$methodPos, 1800);
        self::assertStringNotContainsString('clearDefaults($serviceType)', $methodChunk);
        self::assertStringContainsString('existingTypeRow', $methodChunk);
    }

    public function testDefaultSetApiSuppressesInlineW18nMarkers(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Ai/extends/module/Weline_Framework/Query/AiQueryProvider.php'
        );

        self::assertStringContainsString('withoutInlinePhraseMark', $source);
        self::assertStringContainsString('function defaultSet', $source);
    }
}
