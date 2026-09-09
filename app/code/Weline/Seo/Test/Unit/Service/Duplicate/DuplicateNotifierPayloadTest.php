<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Duplicate;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Duplicate\DuplicateNotifier;

final class DuplicateNotifierPayloadTest extends TestCase
{
    public function testPayloadContainsReportUrl(): void
    {
        $payload = (new DuplicateNotifier())->buildPayload(
            99,
            3,
            2,
            1,
            'https://admin.example.test/seo/backend/duplicate/report?run_id=99'
        );

        self::assertSame('seo_duplicate_content', $payload['topic']);
        self::assertStringContainsString('report?run_id=99', $payload['content']);
        self::assertSame(
            'https://admin.example.test/seo/backend/duplicate/report?run_id=99',
            $payload['options']['metadata']['report_url']
        );
        self::assertSame('seo_dup_run_99', $payload['options']['dedupe_key']);
    }
}
