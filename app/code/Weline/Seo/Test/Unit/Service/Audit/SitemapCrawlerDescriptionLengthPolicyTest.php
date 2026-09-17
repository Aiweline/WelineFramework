<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Audit;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Audit\SitemapCrawlerAuditService;

/**
 * Google documents no fixed meta description length; our crawl audit must not
 * deduct for landing near an industry heuristic band (e.g. 89 vs 90).
 */
final class SitemapCrawlerDescriptionLengthPolicyTest extends TestCase
{
    public function testEightyNineCharsDoesNotCreateLengthIssue(): void
    {
        $issues = $this->auditMetaWithDescription(
            '长安汉服水墨中国风独立站，精选明制、宋制、唐制汉服与马面裙及传统配饰，覆盖日常出行、节日庆典与礼仪场合；提供形制说明、尺码参考、面料要点与搭配灵感，助你更快选到合身又得体的款式。'
        );

        self::assertArrayNotHasKey('description_length', $issues);
        self::assertArrayNotHasKey('description_missing', $issues);
    }

    public function testEmptyDescriptionStillWarns(): void
    {
        $issues = $this->auditMetaWithDescription('');

        self::assertArrayHasKey('description_missing', $issues);
        self::assertSame('warning', $issues['description_missing']['severity']);
        self::assertGreaterThan(0, (int)$issues['description_missing']['deduction']);
        self::assertArrayNotHasKey('description_length', $issues);
    }

    public function testVeryShortDescriptionIsNoticeWithoutDeduction(): void
    {
        $issues = $this->auditMetaWithDescription('汉服商城');

        self::assertArrayHasKey('description_length', $issues);
        self::assertSame('notice', $issues['description_length']['severity']);
        self::assertSame(0, (int)$issues['description_length']['deduction']);
    }

    public function testSourceNoLongerHardCodesNinetyToOneSeventyBand(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/Audit/SitemapCrawlerAuditService.php'
        );

        self::assertStringNotContainsString('90-170', $source);
        self::assertStringNotContainsString('$descriptionLength < 90', $source);
        self::assertStringContainsString('$descriptionLength < 50', $source);
        self::assertStringContainsString('$descriptionLength > 320', $source);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function auditMetaWithDescription(string $description): array
    {
        $service = (new \ReflectionClass(SitemapCrawlerAuditService::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SitemapCrawlerAuditService::class, 'auditMeta');

        $issues = [];
        $pageIssueIds = [];
        $facts = [
            'url' => 'https://example.test/',
            'title' => '长安汉服 · Hanfu Atelier | 水墨汉服商城首页',
            'description' => $description,
        ];
        $args = [$facts, &$issues, &$pageIssueIds];
        $method->invokeArgs($service, $args);

        return $issues;
    }
}
