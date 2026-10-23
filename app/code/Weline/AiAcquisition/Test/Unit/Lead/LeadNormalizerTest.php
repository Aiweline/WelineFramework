<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Test\Unit\Lead;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Service\Lead\LeadNormalizer;

/**
 * LeadNormalizer 纯函数测试：不依赖数据库与框架运行时。
 */
final class LeadNormalizerTest extends TestCase
{
    public function testNormalizesEmailUrlAndCompany(): void
    {
        $normalized = LeadNormalizer::normalize([
            'email' => '  MailTo:Alice@Example.COM?subject=Hi  ',
            'source_url' => 'example.com/path?utm_source=newsletter&b=2&utm_medium=email',
            'company_name' => "  Acme   🌍\tLtd  ",
            'source_platform' => '  LinkedIn ',
        ]);

        self::assertSame('alice@example.com', $normalized[Lead::schema_fields_EMAIL]);
        self::assertSame(
            'https://example.com/path?b=2',
            $normalized[Lead::schema_fields_SOURCE_URL]
        );
        self::assertSame('Acme 🌍 Ltd', $normalized[Lead::schema_fields_COMPANY_NAME]);
        self::assertSame('linkedin', $normalized[Lead::schema_fields_SOURCE_PLATFORM]);
        self::assertSame(Lead::EMAIL_UNKNOWN, $normalized[Lead::schema_fields_EMAIL_STATUS]);
    }

    public function testDedupeHashPrefersEmailThenDomainThenUrl(): void
    {
        $byEmail = LeadNormalizer::normalize([
            'email' => 'Alice@Example.com',
            'company_domain' => 'acme.com',
            'source_url' => 'https://example.com/a',
        ]);
        self::assertSame(
            hash('sha256', 'alice@example.com'),
            $byEmail[Lead::schema_fields_DEDUPE_HASH]
        );

        $byDomain = LeadNormalizer::normalize([
            'company_domain' => 'HTTPS://WWW.Acme.COM/path',
            'source_url' => 'https://example.com/a',
        ]);
        self::assertSame('acme.com', $byDomain[Lead::schema_fields_COMPANY_DOMAIN]);
        self::assertSame(
            hash('sha256', 'acme.com'),
            $byDomain[Lead::schema_fields_DEDUPE_HASH]
        );

        $byUrl = LeadNormalizer::normalize([
            'source_url' => 'example.com/x?utm_source=a',
        ]);
        self::assertSame(
            hash('sha256', 'https://example.com/x'),
            $byUrl[Lead::schema_fields_DEDUPE_HASH]
        );
    }

    public function testScoreIsClampedAndStatusWhitelisted(): void
    {
        $high = LeadNormalizer::normalize([
            'email' => 'a@example.com',
            'ai_score' => '12.345',
        ]);
        self::assertSame(10.0, $high[Lead::schema_fields_AI_SCORE]);

        $low = LeadNormalizer::normalize([
            'email' => 'a@example.com',
            'ai_score' => -3,
        ]);
        self::assertSame(0.0, $low[Lead::schema_fields_AI_SCORE]);

        $qualified = LeadNormalizer::normalize([
            'email' => 'a@example.com',
            'status' => 'QUALIFIED',
        ]);
        self::assertSame(Lead::STATUS_QUALIFIED, $qualified[Lead::schema_fields_STATUS]);

        $default = LeadNormalizer::normalize(['email' => 'a@example.com']);
        self::assertSame(Lead::STATUS_NEW, $default[Lead::schema_fields_STATUS]);
    }

    public function testBackfillsCountryContinentTimezoneAndCoordinates(): void
    {
        $normalized = LeadNormalizer::normalize([
            'email' => 'buyer@example.com',
            'country' => 'United States',
        ]);

        self::assertSame('US', $normalized[Lead::schema_fields_COUNTRY_CODE]);
        self::assertSame('United States', $normalized[Lead::schema_fields_COUNTRY_NAME]);
        self::assertSame('NA', $normalized[Lead::schema_fields_CONTINENT_CODE]);
        self::assertSame('America/New_York', $normalized[Lead::schema_fields_TIMEZONE]);
        self::assertSame(37.0902, $normalized[Lead::schema_fields_LATITUDE]);
        self::assertSame(-95.7129, $normalized[Lead::schema_fields_LONGITUDE]);

        $byChineseName = LeadNormalizer::normalize([
            'email' => 'buyer@example.com',
            'country_name' => '美国',
        ]);
        self::assertSame('US', $byChineseName[Lead::schema_fields_COUNTRY_CODE]);
        self::assertSame('NA', $byChineseName[Lead::schema_fields_CONTINENT_CODE]);
    }

    public function testAllowsMissingEmailAndUrlButRequiresDedupeKey(): void
    {
        $normalized = LeadNormalizer::normalize([
            'company_domain' => 'acme.example',
        ]);
        self::assertSame('', $normalized[Lead::schema_fields_EMAIL]);
        self::assertSame('', $normalized[Lead::schema_fields_SOURCE_URL]);
        self::assertSame(
            hash('sha256', 'acme.example'),
            $normalized[Lead::schema_fields_DEDUPE_HASH]
        );

        $this->expectException(InvalidArgumentException::class);
        LeadNormalizer::normalize(['company_name' => '只有公司名']);
    }

    public function testRejectsInvalidStatusScoreAndUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LeadNormalizer::normalize([
            'email' => 'a@example.com',
            'status' => 'pending',
        ]);
    }

    public function testRejectsInvalidScore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LeadNormalizer::normalize([
            'email' => 'a@example.com',
            'ai_score' => 'high',
        ]);
    }

    public function testRejectsInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LeadNormalizer::normalize([
            'source_url' => 'http://',
        ]);
    }

    public function testRejectsInvalidExplicitEmailStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LeadNormalizer::normalize([
            'email' => 'a@example.com',
            'email_status' => 'bounced',
        ]);
    }

    public function testMarksMalformedEmailAndPreservesChineseCompanyName(): void
    {
        $normalized = LeadNormalizer::normalize([
            'email' => 'not-an-email',
            'company_name' => '长安  汉服🌸  工作室',
            'source_url' => 'https://example.com/a',
        ]);

        self::assertSame('长安 汉服🌸 工作室', $normalized[Lead::schema_fields_COMPANY_NAME]);
        self::assertSame(Lead::EMAIL_INVALID, $normalized[Lead::schema_fields_EMAIL_STATUS]);
    }

    public function testAcceptsRunIdInInputWithoutError(): void
    {
        $normalized = LeadNormalizer::normalize([
            'email' => 'a@example.com',
            'run_id' => 12,
        ]);

        self::assertArrayNotHasKey('run_id', $normalized);
        self::assertSame(1, $normalized[Lead::schema_fields_SEEN_COUNT]);
    }
}
