<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiMobileDevice;
use Weline\Ai\Model\AiMobileNotification;
use Weline\Framework\App\Exception;

/**
 * 移动端管理服务
 * 
 * 功能：
 * - 移动端设备管理
 * - 推送通知服务
 * - 移动端API优化
 * - 设备状态监控
 */
class MobileManager
{
    /**
     * @var AiMobileDevice
     */
    private AiMobileDevice $deviceModel;

    /**
     * @var AiMobileNotification
     */
    private AiMobileNotification $notificationModel;

    /**
     * 推送服务配置
     * 
     * @var array
     */
    private array $pushConfig = [];

    /**
     * 构造函数
     * 
     * @param AiMobileDevice $deviceModel
     * @param AiMobileNotification $notificationModel
     */
    public function __construct(
        AiMobileDevice $deviceModel,
        AiMobileNotification $notificationModel
    ) {
        $this->deviceModel = $deviceModel;
        $this->notificationModel = $notificationModel;
    }

    /**
     * 注册移动端设备
     * 
     * @param int $userId 用户ID
     * @param string $deviceId 设备ID
     * @param string $deviceType 设备类型
     * @param string $pushToken 推送令牌
     * @param array $deviceInfo 设备信息
     * @return AiMobileDevice
     * @throws Exception
     */
    public function registerDevice(
        int $userId,
        string $deviceId,
        string $deviceType,
        string $pushToken = '',
        array $deviceInfo = []
    ): AiMobileDevice {
        // 检查设备是否已存在
        $existingDevice = $this->getDeviceByUserAndDeviceId($userId, $deviceId);
        
        if ($existingDevice) {
            // 更新现有设备
            $existingDevice->setPushToken($pushToken)
                           ->setDeviceInfo($deviceInfo)
                           ->activate();
            $existingDevice->save();
            return $existingDevice;
        }

        // 创建新设备
        $device = new AiMobileDevice();
        $device->setData(AiMobileDevice::fields_USER_ID, $userId)
               ->setData(AiMobileDevice::fields_DEVICE_ID, $deviceId)
               ->setData(AiMobileDevice::fields_DEVICE_TYPE, $deviceType)
               ->setPushToken($pushToken)
               ->setDeviceInfo($deviceInfo)
               ->activate()
               ->save();

        return $device;
    }

    /**
     * 获取用户设备
     * 
     * @param int $userId 用户ID
     * @param string $deviceType 设备类型过滤
     * @param bool $activeOnly 仅激活设备
     * @return array
     */
    public function getUserDevices(int $userId, string $deviceType = '', bool $activeOnly = true): array
    {
        $query = $this->deviceModel->reset()
            ->where(AiMobileDevice::fields_USER_ID, $userId);

        if ($deviceType) {
            $query->where(AiMobileDevice::fields_DEVICE_TYPE, $deviceType);
        }

        if ($activeOnly) {
            $query->where(AiMobileDevice::fields_IS_ACTIVE, 1);
        }

        return $query->select()->fetch();
    }

    /**
     * 根据用户和设备ID获取设备
     * 
     * @param int $userId 用户ID
     * @param string $deviceId 设备ID
     * @return AiMobileDevice|null
     */
    public function getDeviceByUserAndDeviceId(int $userId, string $deviceId): ?AiMobileDevice
    {
        $device = $this->deviceModel->reset()
            ->where(AiMobileDevice::fields_USER_ID, $userId)
            ->where(AiMobileDevice::fields_DEVICE_ID, $deviceId)
            ->find()
            ->fetch();

        return $device->getId() ? $device : null;
    }

    /**
     * 更新设备活跃状态
     * 
     * @param int $userId 用户ID
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function updateDeviceActivity(int $userId, string $deviceId): bool
    {
        $device = $this->getDeviceByUserAndDeviceId($userId, $deviceId);
        
        if (!$device) {
            return false;
        }

        $device->updateLastActive()->save();
        return true;
    }

    /**
     * 停用设备
     * 
     * @param int $userId 用户ID
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function deactivateDevice(int $userId, string $deviceId): bool
    {
        $device = $this->getDeviceByUserAndDeviceId($userId, $deviceId);
        
        if (!$device) {
            return false;
        }

        $device->deactivate()->save();
        return true;
    }

    /**
     * 发送推送通知
     * 
     * @param int $userId 用户ID
     * @param string $title 通知标题
     * @param string $content 通知内容
     * @param string $type 通知类型
     * @param array $data 通知数据
     * @param string $deviceId 指定设备ID
     * @return bool
     */
    public function sendPushNotification(
        int $userId,
        string $title,
        string $content,
        string $type = AiMobileNotification::TYPE_AI_RESPONSE,
        array $data = [],
        string $deviceId = ''
    ): bool {
        // 获取用户设备
        $devices = $this->getUserDevices($userId, '', true);
        
        if (empty($devices)) {
            return false;
        }

        $successCount = 0;
        
        foreach ($devices as $device) {
            if (is_object($device)) {
                // 如果指定了设备ID，只发送给指定设备
                if ($deviceId && $device->getDeviceId() !== $deviceId) {
                    continue;
                }

                // 检查设备是否有推送令牌
                if (!$device->getPushToken()) {
                    continue;
                }

                // 创建通知记录
                $notification = new AiMobileNotification();
                $notification->setData(AiMobileNotification::fields_USER_ID, $userId)
                            ->setData(AiMobileNotification::fields_DEVICE_ID, $device->getDeviceId())
                            ->setData(AiMobileNotification::fields_NOTIFICATION_TYPE, $type)
                            ->setData(AiMobileNotification::fields_TITLE, $title)
                            ->setData(AiMobileNotification::fields_CONTENT, $content)
                            ->setNotificationData($data)
                            ->save();

                // 发送推送通知
                if ($this->sendToPushService($device, $title, $content, $data)) {
                    $notification->markAsSent();
                    $notification->save();
                    $successCount++;
                } else {
                    $notification->markAsFailed();
                    $notification->save();
                }
            }
        }

        return $successCount > 0;
    }

    /**
     * 发送到推送服务
     * 
     * @param AiMobileDevice $device 设备
     * @param string $title 标题
     * @param string $content 内容
     * @param array $data 数据
     * @return bool
     */
    private function sendToPushService(AiMobileDevice $device, string $title, string $content, array $data): bool
    {
        // 这里应该集成实际的推送服务（如Firebase、APNs等）
        // 目前返回模拟成功
        
        $deviceType = $device->getDeviceType();
        $pushToken = $device->getPushToken();
        
        // 模拟推送服务调用
        if ($deviceType === AiMobileDevice::TYPE_IOS) {
            return $this->sendIOSPush($pushToken, $title, $content, $data);
        } elseif ($deviceType === AiMobileDevice::TYPE_ANDROID) {
            return $this->sendAndroidPush($pushToken, $title, $content, $data);
        }

        return false;
    }

    /**
     * 发送iOS推送
     * 
     * @param string $token 推送令牌
     * @param string $title 标题
     * @param string $content 内容
     * @param array $data 数据
     * @return bool
     */
    private function sendIOSPush(string $token, string $title, string $content, array $data): bool
    {
        // 模拟iOS推送服务调用
        // 实际实现需要集成APNs
        return !empty($token);
    }

    /**
     * 发送Android推送
     * 
     * @param string $token 推送令牌
     * @param string $title 标题
     * @param string $content 内容
     * @param array $data 数据
     * @return bool
     */
    private function sendAndroidPush(string $token, string $title, string $content, array $data): bool
    {
        // 模拟Android推送服务调用
        // 实际实现需要集成FCM
        return !empty($token);
    }

    /**
     * 获取用户通知历史
     * 
     * @param int $userId 用户ID
     * @param string $type 通知类型过滤
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public function getUserNotifications(int $userId, string $type = '', int $limit = 20, int $offset = 0): array
    {
        $query = $this->notificationModel->reset()
            ->where(AiMobileNotification::fields_USER_ID, $userId);

        if ($type) {
            $query->where(AiMobileNotification::fields_NOTIFICATION_TYPE, $type);
        }

        return $query->orderBy(AiMobileNotification::fields_CREATED_TIME, 'DESC')
                    ->limit($limit, $offset)
                    ->select()
                    ->fetch();
    }

    /**
     * 标记通知为已读
     * 
     * @param int $notificationId 通知ID
     * @param int $userId 用户ID
     * @return bool
     */
    public function markNotificationAsRead(int $notificationId, int $userId): bool
    {
        $notification = $this->notificationModel->reset()
            ->where(AiMobileNotification::fields_ID, $notificationId)
            ->where(AiMobileNotification::fields_USER_ID, $userId)
            ->find()
            ->fetch();

        if (!$notification->getId()) {
            return false;
        }

        $notification->markAsRead()->save();
        return true;
    }

    /**
     * 获取设备统计信息
     * 
     * @param int $userId 用户ID
     * @return array
     */
    public function getDeviceStats(int $userId): array
    {
        $devices = $this->getUserDevices($userId, '', false);
        
        $stats = [
            'total' => 0,
            'active' => 0,
            'by_type' => [],
            'online' => 0,
            'offline' => 0
        ];

        if ($devices && is_iterable($devices)) {
            foreach ($devices as $device) {
                if (is_object($device)) {
                    $stats['total']++;
                    
                    if ($device->isActive()) {
                        $stats['active']++;
                    }

                    $deviceType = $device->getDeviceType();
                    $stats['by_type'][$deviceType] = ($stats['by_type'][$deviceType] ?? 0) + 1;

                    $status = $device->getStatus();
                    if ($status === 'online') {
                        $stats['online']++;
                    } else {
                        $stats['offline']++;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * 清理过期设备
     * 
     * @param int $days 过期天数
     * @return int 清理数量
     */
    public function cleanupExpiredDevices(int $days = 30): int
    {
        $expiredTime = time() - ($days * 24 * 3600);
        
        $devices = $this->deviceModel->reset()
            ->where(AiMobileDevice::fields_LAST_ACTIVE, '<', $expiredTime)
            ->where(AiMobileDevice::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        $cleanedCount = 0;
        
        if ($devices && is_iterable($devices)) {
            foreach ($devices as $device) {
                if (is_object($device)) {
                    $device->deactivate()->save();
                    $cleanedCount++;
                }
            }
        }

        return $cleanedCount;
    }

    /**
     * 获取移动端API配置
     * 
     * @return array
     */
    public function getMobileApiConfig(): array
    {
        return [
            'version' => '1.0.0',
            'endpoints' => [
                'auth' => '/api/mobile/auth',
                'devices' => '/api/mobile/devices',
                'notifications' => '/api/mobile/notifications',
                'ai' => '/api/mobile/ai'
            ],
            'features' => [
                'push_notifications' => true,
                'offline_sync' => true,
                'real_time_updates' => true,
                'caching' => true
            ],
            'limits' => [
                'max_devices_per_user' => 5,
                'max_notifications_per_hour' => 100,
                'max_file_size' => '10MB'
            ]
        ];
    }
}
