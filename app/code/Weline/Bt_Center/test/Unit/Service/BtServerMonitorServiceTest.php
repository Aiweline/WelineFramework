<?php

declare(strict_types=1);

namespace Weline\Bt_Center\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Bt_Center\Model\BtServer;
use Weline\Bt_Center\Service\BtServerMonitorService;

class BtServerMonitorServiceTest extends TestCase
{
    public function testShouldNotifyOnFirstFailure(): void
    {
        $this->assertTrue(
            BtServerMonitorService::shouldNotifyOnTransition(BtServer::CHECK_STATUS_UNKNOWN, BtServer::CHECK_STATUS_DOWN)
        );
    }

    public function testShouldNotNotifyOnFirstSuccess(): void
    {
        $this->assertFalse(
            BtServerMonitorService::shouldNotifyOnTransition(BtServer::CHECK_STATUS_UNKNOWN, BtServer::CHECK_STATUS_UP)
        );
    }

    public function testShouldNotifyOnRecovery(): void
    {
        $this->assertTrue(
            BtServerMonitorService::shouldNotifyOnTransition(BtServer::CHECK_STATUS_DOWN, BtServer::CHECK_STATUS_UP)
        );
    }

    public function testBuildNotificationForFailure(): void
    {
        $notification = BtServerMonitorService::buildNotification(
            [
                BtServer::schema_fields_SERVER_ID => 1,
                BtServer::schema_fields_NAME => '服务器1',
                BtServer::schema_fields_EXTERNAL_URL => 'https://3.7.199.83:22248/f9a64fa7',
            ],
            [
                'http_code' => 500,
                'response_time_ms' => 321,
                'error_message' => 'timeout',
            ],
            BtServer::CHECK_STATUS_UP,
            BtServer::CHECK_STATUS_DOWN
        );

        $this->assertNotNull($notification);
        $this->assertSame('error', $notification['type']);
        $this->assertStringContainsString('服务器1', $notification['title']);
        $this->assertStringContainsString('timeout', $notification['content']);
    }
}
