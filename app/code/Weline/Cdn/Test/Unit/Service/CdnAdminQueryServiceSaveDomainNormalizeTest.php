<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\CdnAdminQueryService;

/**
 * saveDomain 入参归一化：布尔旗标必须落 0/1，避免 PostgreSQL integer 列绑定失败。
 */
final class CdnAdminQueryServiceSaveDomainNormalizeTest extends TestCase
{
    public function testNormalizeBoolFlagsToIntegers(): void
    {
        $normalized = CdnAdminQueryService::normalizeSaveDomainParams([
            'site_id' => 3,
            'adapter' => 'cloudflare',
            'domain_name' => 'ChanganHanfu.COM.',
            'zone_id' => 'fe99b5abcd',
            'inherit_default' => false,
            'enabled' => true,
            'warmup_interval_seconds' => 300,
        ]);

        self::assertTrue($normalized['success']);
        self::assertSame(3, $normalized['site_id']);
        self::assertSame('cloudflare', $normalized['adapter']);
        self::assertSame('changanhanfu.com', $normalized['domain_name']);
        self::assertSame('fe99b5abcd', $normalized['zone_id']);
        self::assertNull($normalized['account_id']);
        self::assertSame(0, $normalized['inherit_default']);
        self::assertSame(1, $normalized['enabled']);
        self::assertSame(300, $normalized['warmup_interval_seconds']);
    }

    public function testNormalizeAcceptsZeroOneIntegersAndEmptyAccount(): void
    {
        $normalized = CdnAdminQueryService::normalizeSaveDomainParams([
            'site_id' => '0',
            'adapter' => 'cloudflare',
            'domain_name' => 'example.com',
            'zone_id' => 'zone-1',
            'account_id' => '',
            'inherit_default' => 0,
            'enabled' => 1,
        ]);

        self::assertTrue($normalized['success']);
        self::assertSame(0, $normalized['site_id']);
        self::assertNull($normalized['account_id']);
        self::assertSame(0, $normalized['inherit_default']);
        self::assertSame(1, $normalized['enabled']);
    }

    public function testNormalizeRejectsMissingRequiredFields(): void
    {
        $normalized = CdnAdminQueryService::normalizeSaveDomainParams([
            'site_id' => 1,
            'adapter' => 'cloudflare',
            'domain_name' => '',
            'zone_id' => 'zone-1',
        ]);

        self::assertFalse($normalized['success']);
        self::assertNotEmpty($normalized['message'] ?? '');
    }
}
