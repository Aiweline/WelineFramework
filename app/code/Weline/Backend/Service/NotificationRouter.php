<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Enum\NotificationType;
use Weline\Backend\Model\NotificationChannel;
use Weline\Backend\Model\NotificationTopic;
use Weline\Backend\Model\SystemNotification;
use Weline\Backend\Model\UserNotificationStatus;
use Weline\Backend\Model\UserNotificationSubscription;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;

class NotificationRouter
{
    private UserNotificationSubscription $subscriptionModel;
    private UserNotificationStatus $statusModel;
    private NotificationChannel $channelModel;
    private NotificationTopic $topicModel;
    private SystemNotification $notificationModel;
    private BackendUser $userModel;
    private UserContactService $contactService;

    public function __construct(
        UserNotificationSubscription $subscriptionModel,
        UserNotificationStatus $statusModel,
        NotificationChannel $channelModel,
        NotificationTopic $topicModel,
        SystemNotification $notificationModel,
        BackendUser $userModel,
        UserContactService $contactService
    ) {
        $this->subscriptionModel = $subscriptionModel;
        $this->statusModel = $statusModel;
        $this->channelModel = $channelModel;
        $this->topicModel = $topicModel;
        $this->notificationModel = $notificationModel;
        $this->userModel = $userModel;
        $this->contactService = $contactService;
    }

    /**
     * 路由通知到各个渠道
     */
    public function route(array $notification): void
    {
        $topicCode = $notification['topic_code'] ?? '';
        $type = $notification['type'] ?? 'info';
        $notificationId = (int) ($notification['notification_id'] ?? 0);
        $specifiedUsers = $notification['notify_users'] ?? [];

        $this->createUserStatuses($notificationId, $specifiedUsers);

        $this->routeToExternalChannels($notification);
    }

    /**
     * 为用户创建通知状态记录
     */
    private function createUserStatuses(int $notificationId, array $specifiedUsers = []): void
    {
        if ($notificationId <= 0) {
            return;
        }

        $users = $this->getTargetUsers($specifiedUsers);

        foreach ($users as $user) {
            $userId = (int) ($user['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $status = clone $this->statusModel;
            $status->clearQuery()
                ->setUserId($userId)
                ->setNotificationId($notificationId)
                ->setIsRead(false);

            try {
                $status->save();
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * 获取目标用户列表
     */
    private function getTargetUsers(array $specifiedUsers = []): array
    {
        $query = $this->userModel->clearQuery()
            ->where(BackendUser::fields_is_enabled, 1)
            ->where(BackendUser::fields_is_deleted, 0);

        if (!empty($specifiedUsers)) {
            $query->where(BackendUser::fields_ID, $specifiedUsers, 'IN');
        }

        return $query->select()->fetchArray();
    }

    /**
     * 路由到外部渠道
     */
    private function routeToExternalChannels(array $notification): void
    {
        $topicCode = $notification['topic_code'] ?? '';
        $type = $notification['type'] ?? 'info';
        $notificationId = $notification['notification_id'] ?? 0;
        $specifiedUsers = $notification['notify_users'] ?? [];

        $channels = $this->getEnabledChannels();
        $notifiedChannels = [];

        foreach ($channels as $channel) {
            $channelCode = $channel['channel_code'] ?? '';
            $minType = $channel['min_type'] ?? 'info';
            $subscribedTopics = json_decode($channel['subscribed_topics'] ?? '[]', true) ?: [];

            if (!empty($subscribedTopics) && !in_array($topicCode, $subscribedTopics, true)) {
                continue;
            }

            if (!NotificationType::meetsMinimumType($type, $minType)) {
                continue;
            }

            $adapter = $this->getChannelAdapter($channelCode);
            if (!$adapter) {
                continue;
            }

            $channelConfig = json_decode($channel['channel_config'] ?? '[]', true) ?: [];

            $users = $this->getTargetUsers($specifiedUsers);
            foreach ($users as $user) {
                $userId = (int) ($user['user_id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }

                if (!$this->isUserSubscribed($userId, $topicCode, $channelCode, $type)) {
                    continue;
                }

                $contact = $this->contactService->getContactForNotification($userId, $channelCode);
                if (!$contact) {
                    continue;
                }

                $notificationWithContact = $notification;
                $notificationWithContact['contact'] = $contact;
                $notificationWithContact['recipient_user_id'] = $userId;
                $notificationWithContact['recipient_name'] = $user['username'] ?? '';

                try {
                    $success = $adapter->send($notificationWithContact, $channelConfig);
                    if ($success && !in_array($channelCode, $notifiedChannels, true)) {
                        $notifiedChannels[] = $channelCode;
                    }
                } catch (\Exception $e) {
                }
            }
        }

        if (!empty($notifiedChannels) && $notificationId > 0) {
            $this->updateExternalNotificationStatus($notificationId, $notifiedChannels);
        }
    }

    /**
     * 获取已启用的外部渠道
     */
    private function getEnabledChannels(): array
    {
        return $this->channelModel->clearQuery()
            ->where(NotificationChannel::fields_is_enabled, 1)
            ->select()
            ->fetchArray();
    }

    /**
     * 获取渠道适配器
     */
    private function getChannelAdapter(string $channelCode): ?ChannelAdapterInterface
    {
        $adapters = ObjectManager::getInstances(ChannelAdapterInterface::class);

        foreach ($adapters as $adapter) {
            if ($adapter->getChannelCode() === $channelCode) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * 更新通知的外部渠道状态
     */
    private function updateExternalNotificationStatus(int $notificationId, array $channels): void
    {
        $notification = clone $this->notificationModel;
        $notification->clearQuery()->load($notificationId);

        if ($notification->getId()) {
            $existingChannels = $notification->getExternalChannels();
            $allChannels = array_unique(array_merge($existingChannels, $channels));

            $notification->setExternalNotified(true)
                ->setExternalChannels($allChannels)
                ->save();
        }
    }

    /**
     * 检查用户是否订阅了某个主题的某个渠道
     */
    public function isUserSubscribed(int $userId, string $topicCode, string $channel, string $messageType): bool
    {
        $subscription = $this->subscriptionModel->clearQuery()
            ->where(UserNotificationSubscription::fields_user_id, $userId)
            ->where(UserNotificationSubscription::fields_topic_code, $topicCode)
            ->where(UserNotificationSubscription::fields_channel, $channel)
            ->where(UserNotificationSubscription::fields_is_enabled, 1)
            ->select()
            ->fetch();

        if (!$subscription || !$subscription->getId()) {
            return false;
        }

        $minType = $subscription->getMinType();
        return NotificationType::meetsMinimumType($messageType, $minType);
    }

    /**
     * 获取用户订阅的渠道列表
     */
    public function getUserSubscribedChannels(int $userId, string $topicCode, string $messageType): array
    {
        $subscriptions = $this->subscriptionModel->clearQuery()
            ->where(UserNotificationSubscription::fields_user_id, $userId)
            ->where(UserNotificationSubscription::fields_topic_code, $topicCode)
            ->where(UserNotificationSubscription::fields_is_enabled, 1)
            ->select()
            ->fetchArray();

        $channels = [];
        foreach ($subscriptions as $sub) {
            $minType = $sub['min_type'] ?? 'info';
            if (NotificationType::meetsMinimumType($messageType, $minType)) {
                $channels[] = $sub['channel'];
            }
        }

        return $channels;
    }
}
