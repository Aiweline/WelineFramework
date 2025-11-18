<?php
declare(strict_types=1);

/**
 * 移动端推送集成测试
 * 
 * 测试场景: 移动端设备管理和推送通知功能
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiMobileDevice;
use Weline\Ai\Model\AiMobileNotification;
use Weline\Ai\Service\MobileManager;

class MobilePushIntegrationTest extends TestCase
{
    private AiMobileDevice $deviceModel;
    private AiMobileNotification $notificationModel;
    private MobileManager $mobileManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deviceModel = new AiMobileDevice();
        $this->notificationModel = new AiMobileNotification();
        $this->mobileManager = new MobileManager();
    }

    /**
     * 测试设备注册功能
     */
    public function testDeviceRegistration(): void
    {
        $deviceData = [
            'user_id' => 1,
            'device_id' => 'test-device-001',
            'device_type' => 'ios',
            'push_token' => 'test-push-token-123',
            'is_active' => 1
        ];
        
        $device = new AiMobileDevice();
        $device->setData($deviceData);
        $result = $device->save();
        
        $this->assertTrue($result);
        $this->assertGreaterThan(0, $device->getId());
    }

    /**
     * 测试推送通知发送
     */
    public function testPushNotificationSending(): void
    {
        // 注册设备
        $device = $this->createTestDevice();
        
        // 发送推送通知
        $result = $this->mobileManager->sendPushNotification(
            $device->getUserId(),
            '测试通知',
            '这是一条测试通知',
            'ai_response'
        );
        
        $this->assertTrue($result);
    }

    /**
     * 创建测试设备
     */
    private function createTestDevice(): AiMobileDevice
    {
        $device = new AiMobileDevice();
        $device->setData([
            'user_id' => 1,
            'device_id' => 'test-device-' . uniqid(),
            'device_type' => 'ios',
            'push_token' => 'test-token-' . uniqid(),
            'is_active' => 1
        ]);
        $device->save();
        
        return $device;
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        $this->deviceModel->getCollection()
            ->where('device_id', 'LIKE', 'test-device-%')
            ->delete();
        
        parent::tearDown();
    }
}
