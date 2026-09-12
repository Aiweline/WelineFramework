<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipListingDeleteService;

/**
 * Delete listings rejects empty ids and normalizes local product actions.
 */
final class DropshipListingDeleteServiceTest extends TestCase
{
    public function testDeleteListingsRejectsEmptyIds(): void
    {
        $svc = new DropshipListingDeleteService();
        $result = $svc->deleteListings([]);
        self::assertFalse($result['ok']);
        self::assertSame(0, $result['deleted']);
        self::assertSame('ids_required', $result['error'] ?? null);

        $result2 = $svc->deleteListings([0, -1, ''], 0, DropshipListingDeleteService::LOCAL_KEEP);
        self::assertFalse($result2['ok']);
        self::assertSame('ids_required', $result2['error'] ?? null);
        self::assertSame(DropshipListingDeleteService::LOCAL_KEEP, $result2['local_product_action'] ?? null);
    }

    public function testNormalizeLocalAction(): void
    {
        $svc = new DropshipListingDeleteService();
        self::assertSame(DropshipListingDeleteService::LOCAL_KEEP, $svc->normalizeLocalAction('keep'));
        self::assertSame(DropshipListingDeleteService::LOCAL_DISABLE, $svc->normalizeLocalAction('disable'));
        self::assertSame(DropshipListingDeleteService::LOCAL_ARCHIVE, $svc->normalizeLocalAction('archive'));
        self::assertSame(DropshipListingDeleteService::LOCAL_ARCHIVE, $svc->normalizeLocalAction('physical'));
        self::assertSame(DropshipListingDeleteService::LOCAL_ARCHIVE, $svc->normalizeLocalAction('1'));
        self::assertSame(DropshipListingDeleteService::LOCAL_DISABLE, $svc->normalizeLocalAction('nope'));
    }

    public function testServiceExposesDeleteListingsApi(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DropshipListingDeleteService.php');
        self::assertStringContainsString('function deleteListings', $src);
        self::assertStringContainsString('LOCAL_KEEP', $src);
        self::assertStringContainsString('LOCAL_ARCHIVE', $src);
        self::assertStringContainsString('ACTION_DISABLE', $src);
        self::assertStringContainsString('ACTION_ARCHIVE', $src);
        self::assertStringContainsString('ProductAdminCommandInterface', $src);
        self::assertStringContainsString("->where(DropshipListing::schema_fields_ID, \$exceptListingId, '!=')", $src);
        self::assertStringNotContainsString("'neq'", $src);
        self::assertStringContainsString('re-load before delete', $src);
    }
}
