<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Audit;

use PHPUnit\Framework\TestCase;

final class SitemapCrawlerEeatStrictContractTest extends TestCase
{
    public function testAuditStructuredDataContainsEeatStrictHelpers(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/Audit/SitemapCrawlerAuditService.php'
        );

        self::assertStringContainsString('auditEeatStrictStructuredSignals', $source);
        self::assertStringContainsString('eeat_org_sameas', $source);
        self::assertStringContainsString('eeat_article_author_missing', $source);
        self::assertStringContainsString('eeat_article_author_shallow', $source);
        self::assertStringContainsString('Helpful Content', $source);
        self::assertStringContainsString('who_person_url_or_sameas', $source);
    }
}
