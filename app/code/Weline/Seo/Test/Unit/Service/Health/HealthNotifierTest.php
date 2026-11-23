<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Health;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Health\HealthNotifier;

final class HealthNotifierTest extends TestCase
{
    public function testNoMessageWhenNothingToReport(): void
    {
        $payload = (new HealthNotifier())->buildPayload(1, ['issues' => 0], [], []);

        self::assertNull($payload);
    }

    public function testBuildsPayloadWithCountsAndDedupe(): void
    {
        $payload = (new HealthNotifier())->buildPayload(
            7,
            ['issues' => 5, 'by_issue' => ['not_found' => 3, 'server_503' => 2]],
            ['planned' => 2, 'restores' => 1, 'brake' => false],
            ['applied' => 1, 'failed' => 0]
        );

        self::assertNotNull($payload);
        self::assertSame('seo_sitemap', $payload['topic']);
        self::assertSame('warning', $payload['type']);
        self::assertSame('seo_health:7:' . date('Y-m-d'), $payload['dedupe_key']);
        self::assertSame(7, $payload['metadata']['website_id']);
        self::assertSame(5, $payload['metadata']['issues']);
        self::assertSame(2, $payload['metadata']['planned']);
        self::assertSame(1, $payload['metadata']['restores']);
    }

    public function testBrakeIsUrgentAndExplains(): void
    {
        $payload = (new HealthNotifier())->buildPayload(
            7,
            ['issues' => 60],
            ['planned' => 60, 'brake' => true, 'brake_reason' => '单次上限 50 条'],
            ['applied' => 50, 'failed' => 0]
        );

        self::assertNotNull($payload);
        self::assertSame('error', $payload['type']);
        self::assertStringContainsString('安全阀', $payload['content']);
    }
}
