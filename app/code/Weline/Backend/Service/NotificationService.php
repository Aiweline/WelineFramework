<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Enum\NotificationType;
use Weline\Backend\Model\NotificationTopic;
use Weline\Backend\Model\SystemNotification;
use Weline\Backend\Model\UserNotificationStatus;
use Weline\Backend\Model\UserNotificationSubscription;
use Weline\Backend\Model\NotificationChannel;

class NotificationService
{
    private SystemNotification $notificationModel;
    private UserNotificationStatus $statusModel;
    private UserNotificationSubscription $subscriptionModel;
    private NotificationTopic $topicModel;
    private NotificationChannel $channelModel;
    private TopicCollector $topicCollector;

    public function __construct(
        SystemNotification $notificationModel,
        UserNotificationStatus $statusModel,
        UserNotificationSubscription $subscriptionModel,
        NotificationTopic $topicModel,
        NotificationChannel $channelModel,
        TopicCollector $topicCollector
    ) {
        $this->notificationModel = $notificationModel;
        $this->statusModel = $statusModel;
        $this->subscriptionModel = $subscriptionModel;
        $this->topicModel = $topicModel;
        $this->channelModel = $channelModel;
        $this->topicCollector = $topicCollector;
    }

    /**
     * 获取用户的通知列表
     */
    public function getUserNotifications(int $userId, int $page = 1, int $limit = 20, bool $unreadOnly = false): array
    {
        $query = $this->statusModel->clearQuery()
            ->joinModel(
                SystemNotification::class,
                'n',
                'main_table.notification_id = n.notification_id',
                'inner',
                'n.topic_code, n.type, n.title, n.content, n.priority, n.avatar, n.is_icon, n.is_img, n.external_channels, n.create_time as notification_time'
            )
            ->where(UserNotificationStatus::fields_user_id, $userId);

        if ($unreadOnly) {
            $query->where(UserNotificationStatus::fields_is_read, 0);
        }

        $query->order('n.create_time', 'DESC')
            ->pagination($page, $limit)
            ->select()
            ->fetch();

        $items = $this->statusModel->getItems();
        $pagination = $this->statusModel->getPagination();

        $notifications = [];
        foreach ($items as $item) {
            $data = $item->getData();
            $type = NotificationType::fromString($data['type'] ?? 'info');
            $topicInfo = $this->topicCollector->getTopicByCode($data['topic_code'] ?? '');

            $notifications[] = [
                'status_id'         => (int) $data['status_id'],
                'notification_id'   => (int) $data['notification_id'],
                'topic_code'        => $data['topic_code'] ?? '',
                'topic_name'        => $topicInfo['topic_name'] ?? $data['topic_code'],
                'topic_color'       => $topicInfo['color'] ?? '#50a5f1',
                'topic_icon'        => $topicInfo['icon'] ?? 'ri-notification-line',
                'type'              => $data['type'] ?? 'info',
                'type_label'        => $type->getLabel(),
                'type_color'        => $type->getHexColor(),
                'title'             => $data['title'] ?? '',
                'content'           => $data['content'] ?? '',
                'priority'          => (int) ($data['priority'] ?? 5),
                'avatar'            => $data['avatar'] ?? 'ri-notification-line',
                'is_icon'           => (bool) ($data['is_icon'] ?? true),
                'is_img'            => (bool) ($data['is_img'] ?? false),
                'is_read'           => (bool) ($data['is_read'] ?? false),
                'read_at'           => $data['read_at'] ?? null,
                'notification_time' => $data['notification_time'] ?? '',
                'external_channels' => json_decode($data['external_channels'] ?? '[]', true) ?: [],
            ];
        }

        return [
            'items'      => $notifications,
            'total'      => (int) ($pagination['totalSize'] ?? 0),
            'page'       => (int) ($pagination['page'] ?? $page),
            'limit'      => (int) ($pagination['pageSize'] ?? $limit),
            'pages'      => (int) ($pagination['lastPage'] ?? 1),
        ];
    }

    /**
     * 获取用户未读通知数量
     */
    public function getUnreadCount(int $userId): int
    {
        return $this->statusModel->clearQuery()
            ->where(UserNotificationStatus::fields_user_id, $userId)
            ->where(UserNotificationStatus::fields_is_read, 0)
            ->total();
    }

    /**
     * 标记通知为已读（通过 notification_id）
     */
    public function markAsRead(int $userId, int $notificationId): bool
    {
        $status = clone $this->statusModel;
        $status->clearQuery()
            ->where(UserNotificationStatus::fields_notification_id, $notificationId)
            ->where(UserNotificationStatus::fields_user_id, $userId)
            ->find()
            ->fetch();

        if (!$status->getId()) {
            return false;
        }

        $status->markAsRead()->save();
        return true;
    }

    /**
     * 标记所有通知为已读
     */
    public function markAllAsRead(int $userId): int
    {
        $unreadStatuses = $this->statusModel->clearQuery()
            ->where(UserNotificationStatus::fields_user_id, $userId)
            ->where(UserNotificationStatus::fields_is_read, 0)
            ->select()
            ->fetchArray();

        $count = 0;
        foreach ($unreadStatuses as $statusData) {
            $status = clone $this->statusModel;
            $status->clearQuery()->load((int) $statusData['status_id']);
            if ($status->getId()) {
                $status->markAsRead()->save();
                $count++;
            }
        }

        return $count;
    }

    /**
     * 获取用户的订阅设置
     */
    public function getUserSubscriptions(int $userId): array
    {
        $subscriptions = $this->subscriptionModel->clearQuery()
            ->where(UserNotificationSubscription::fields_user_id, $userId)
            ->select()
            ->fetchArray();

        $result = [];
        foreach ($subscriptions as $sub) {
            $topicCode = $sub['topic_code'] ?? '';
            if (!isset($result[$topicCode])) {
                $result[$topicCode] = [];
            }
            $result[$topicCode][$sub['channel']] = [
                'subscription_id' => (int) $sub['subscription_id'],
                'min_type'        => $sub['min_type'] ?? 'info',
                'is_enabled'      => (bool) ($sub['is_enabled'] ?? true),
                'channel_config'  => json_decode($sub['channel_config'] ?? '[]', true) ?: [],
            ];
        }

        return $result;
    }

    /**
     * 保存用户订阅设置
     */
    public function saveUserSubscription(
        int $userId,
        string $topicCode,
        string $channel,
        string $minType = 'info',
        bool $enabled = true,
        array $channelConfig = []
    ): bool {
        $subscription = clone $this->subscriptionModel;
        $subscription->clearQuery()
            ->where(UserNotificationSubscription::fields_user_id, $userId)
            ->where(UserNotificationSubscription::fields_topic_code, $topicCode)
            ->where(UserNotificationSubscription::fields_channel, $channel)
            ->find()
            ->fetch();

        $subscription->setUserId($userId)
            ->setTopicCode($topicCode)
            ->setChannel($channel)
            ->setMinType($minType)
            ->setIsEnabled($enabled)
            ->setChannelConfig($channelConfig);

        $subscription->save();
        return true;
    }

    /**
     * 获取所有可用主题（按分组）
     */
    public function getTopicsGrouped(): array
    {
        return $this->topicCollector->getTopicsGrouped();
    }

    /**
     * 获取所有渠道配置
     */
    public function getChannels(): array
    {
        return $this->channelModel->clearQuery()
            ->order(NotificationChannel::fields_channel_code)
            ->select()
            ->fetchArray();
    }

    /**
     * 保存渠道配置
     */
    public function saveChannel(array $data): bool
    {
        $channelId = (int) ($data['channel_id'] ?? 0);
        $channel = clone $this->channelModel;

        if ($channelId > 0) {
            $channel->clearQuery()->load($channelId);
        }

        $channel->setChannelCode($data['channel_code'] ?? '')
            ->setChannelName($data['channel_name'] ?? '')
            ->setChannelConfig($data['channel_config'] ?? [])
            ->setSubscribedTopics($data['subscribed_topics'] ?? [])
            ->setMinType($data['min_type'] ?? 'warning')
            ->setIsEnabled((bool) ($data['is_enabled'] ?? true));

        $channel->save();
        return true;
    }

    /**
     * 删除渠道配置
     */
    public function deleteChannel(int $channelId): bool
    {
        $channel = clone $this->channelModel;
        $channel->clearQuery()->load($channelId);

        if ($channel->getId()) {
            $channel->delete()->fetch();
            return true;
        }

        return false;
    }

    /**
     * 获取单条通知详情
     */
    public function getNotificationDetail(int $userId, int $notificationId): ?array
    {
        $status = clone $this->statusModel;
        $status->clearQuery()
            ->joinModel(
                SystemNotification::class,
                'n',
                'main_table.notification_id = n.notification_id',
                'inner',
                'n.topic_code, n.type, n.title, n.content, n.priority, n.avatar, n.is_icon, n.is_img, n.external_channels, n.create_time as notification_time, n.metadata'
            )
            ->where(UserNotificationStatus::fields_notification_id, $notificationId)
            ->where(UserNotificationStatus::fields_user_id, $userId)
            ->find()
            ->fetch();

        if (!$status->getId()) {
            return null;
        }

        $data = $status->getData();
        $type = NotificationType::fromString($data['type'] ?? 'info');
        $topicInfo = $this->topicCollector->getTopicByCode($data['topic_code'] ?? '');

        return [
            'status_id'         => (int) $data['status_id'],
            'notification_id'   => (int) $data['notification_id'],
            'topic_code'        => $data['topic_code'] ?? '',
            'topic_name'        => $topicInfo['topic_name'] ?? $data['topic_code'],
            'topic_color'       => $topicInfo['color'] ?? '#50a5f1',
            'topic_icon'        => $topicInfo['icon'] ?? 'ri-notification-line',
            'type'              => $data['type'] ?? 'info',
            'type_label'        => $type->getLabel(),
            'type_color'        => $type->getHexColor(),
            'title'             => $data['title'] ?? '',
            'content'           => $data['content'] ?? '',
            'priority'          => (int) ($data['priority'] ?? 5),
            'avatar'            => $data['avatar'] ?? 'ri-notification-line',
            'is_icon'           => (bool) ($data['is_icon'] ?? true),
            'is_img'            => (bool) ($data['is_img'] ?? false),
            'is_read'           => (bool) ($data['is_read'] ?? false),
            'read_at'           => $data['read_at'] ?? null,
            'notification_time' => $data['notification_time'] ?? '',
            'external_channels' => json_decode($data['external_channels'] ?? '[]', true) ?: [],
            'metadata'          => json_decode($data['metadata'] ?? '[]', true) ?: [],
        ];
    }
}
