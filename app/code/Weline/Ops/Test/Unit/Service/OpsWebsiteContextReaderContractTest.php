<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class OpsWebsiteContextReaderContractTest extends TestCase
{
    public function test_reader_collects_multi_source_evidence_pack(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/OpsWebsiteContextReader.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('site_facts', $src);
        self::assertStringContainsString('evidence_gaps', $src);
        self::assertStringContainsString('StorefrontCategoryTreeIndex', $src);
        self::assertStringContainsString('PolicyDocumentDefaults', $src);
        self::assertStringContainsString('getWebsiteLanguageCodes', $src);
        self::assertStringContainsString('STATUS_PUBLISHED', $src);
        self::assertStringContainsString('FaqItem', $src);
        self::assertStringNotContainsString('changanhanfu', $src);
        self::assertStringNotContainsString('daocharms', $src);
    }
}
