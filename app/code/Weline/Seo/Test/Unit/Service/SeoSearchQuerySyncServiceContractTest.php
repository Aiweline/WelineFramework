<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SeoSearchQuerySyncServiceContractTest extends TestCase
{
    public function testSyncAndEvolveStayOnPublishedBindingsAndHeatQueue(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/SeoSearchQuerySyncService.php'
        );

        self::assertStringContainsString('function syncWebsite', $source);
        self::assertStringContainsString('function evolveFromQueryHeat', $source);
        self::assertStringContainsString('rankTargetsByQueryHeat', $source);
        self::assertStringContainsString("enqueueAnalyze(", $source);
        self::assertStringContainsString("'pagebuilder_ai_site'", $source);
        self::assertStringContainsString("'trigger_source' => 'query_heat'", $source);
        self::assertStringContainsString("'apply_intent' => 'auto_draft'", $source);
        self::assertStringContainsString('$ranked[0]', $source);
        self::assertStringNotContainsString('GuoLaiRen\\PageBuilder', $source);
    }
}
