<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\B2BHangAdminListPresenter;

final class B2BHangAdminListPresenterTest extends TestCase
{
    public function testSeverityPriorityDangerOverWarningAndInfo(): void
    {
        $p = new B2BHangAdminListPresenter();
        $sev = $p->resolveSeverity(
            ['blocked' => true, 'level' => 'country', 'message' => '禁运地址'],
            '偏远提示（启发式）：新疆',
            3,
        );
        self::assertSame(B2BHangAdminListPresenter::SEVERITY_DANGER, $sev['severity']);
        self::assertNotEmpty($sev['severity_reasons']);
        self::assertStringContainsString('禁运', implode(' ', $sev['severity_reasons']));
    }

    public function testSeverityWarningFromRemoteWithoutEmbargo(): void
    {
        $p = new B2BHangAdminListPresenter();
        $sev = $p->resolveSeverity(
            ['blocked' => false, 'level' => null, 'message' => ''],
            '偏远提示（启发式）：西藏',
            0,
        );
        self::assertSame(B2BHangAdminListPresenter::SEVERITY_WARNING, $sev['severity']);
    }

    public function testSeverityInfoFromUnreadOnly(): void
    {
        $p = new B2BHangAdminListPresenter();
        $sev = $p->resolveSeverity(
            ['blocked' => false, 'level' => null, 'message' => ''],
            null,
            2,
        );
        self::assertSame(B2BHangAdminListPresenter::SEVERITY_INFO, $sev['severity']);
    }

    public function testRemoteHintRequiresProvinceText(): void
    {
        $p = new B2BHangAdminListPresenter();
        self::assertNull($p->remoteHintFromAddress(['city' => '乌鲁木齐']));
        self::assertNull($p->remoteHintFromAddress(['province_region_id' => 12]));
        $hint = $p->remoteHintFromAddress(['province' => '新疆维吾尔自治区']);
        self::assertNotNull($hint);
        self::assertStringContainsString('偏远', (string)$hint);
    }

    public function testRemoteHintIgnoresNonRemoteProvince(): void
    {
        $p = new B2BHangAdminListPresenter();
        self::assertNull($p->remoteHintFromAddress(['province' => '广东省']));
    }
}
