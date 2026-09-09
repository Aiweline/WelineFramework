<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Duplicate;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Duplicate\DuplicateReportUrlBuilder;

final class DuplicateReportUrlBuilderTest extends TestCase
{
    public function testStableReportPathIncludesBackendFrontNameWhenConfigured(): void
    {
        $builder = new DuplicateReportUrlBuilder('jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH');

        self::assertSame(
            '/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/seo/backend/duplicate/report?run_id=42',
            $builder->pathForRun(42)
        );
        self::assertSame(
            '/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/seo/backend/duplicate/report?run_id=42&grade=duplicate',
            $builder->pathForRun(42, 'duplicate')
        );
        self::assertSame(
            '/seo/backend/duplicate/report?run_id=42',
            $builder->pathForRun(42, null, false)
        );
        self::assertSame(
            '/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/seo/backend/duplicate',
            $builder->panelPath()
        );
    }

    public function testAbsoluteReportUrlKeepsFrontNameOnOriginBase(): void
    {
        $builder = new DuplicateReportUrlBuilder('jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH');
        $url = $builder->absoluteForRun(7, 'https://admin.example.test:9555');

        self::assertSame(
            'https://admin.example.test:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/seo/backend/duplicate/report?run_id=7',
            $url
        );
        self::assertNotSame(
            'https://admin.example.test:9555/seo/backend/duplicate/report?run_id=7',
            $url
        );
    }

    public function testExplicitBaseWithoutFrontNameFlagKeepsLegacyRouteOnly(): void
    {
        $builder = new DuplicateReportUrlBuilder('jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH');
        self::assertSame(
            'https://admin.example.test/seo/backend/duplicate/report?run_id=7',
            $builder->absoluteForRun(7, 'https://admin.example.test', null, false)
        );
    }
}
