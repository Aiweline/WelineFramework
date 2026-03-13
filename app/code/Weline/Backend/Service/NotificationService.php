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
     * 获取用户的通知列表（支持关键词、类型、已读状态过滤）
     *
     * @param array{keyword?: string, type?: string, read?: string} $filters 可选：keyword 标题/内容模糊搜索，type 类型(info/success/...)，read 已读状态(all/read/unread)
     */
    public function getUserNotifications(
        int $userId,
        int $page = 1,
        int $limit = 20,
        bool $unreadOnly = false,
        array $filters = []
    ): array {
        $query = $this->statusModel->clearQuery()
            ->joinModel(
                SystemNotification::class,
                'n',
                'main_table.notification_id = n.notification_id',
                'inner',
                'n.topic_code, n.type, n.title, n.content, n.priority, n.avatar, n.is_icon, n.is_img, n.external_channels, n.create_time as notification_time'
            )
            ->where(UserNotificationStatus::schema_fields_user_id, $userId);

        if ($unreadOnly) {
            $query->where(UserNotificationStatus::schema_fields_is_read, 0);
        }

        $keyword = isset($filters['keyword']) ? trim((string) $filters['keyword']) : '';
        if ($keyword !== '') {
            $pattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where('n.title', $pattern, 'like', 'OR')
                ->where('n.content', $pattern, 'like');
        }

        $type = isset($filters['type']) ? trim((string) $filters['type']) : '';
        if ($type !== '' && in_array($type, NotificationType::getAllTypes(), true)) {
            $query->where('n.type', $type);
        }

        $readFilter = isset($filters['read']) ? trim((string) $filters['read']) : 'all';
        if ($readFilter === 'read') {
            $query->where(UserNotificationStatus::schema_fields_is_read, 1);
        } elseif ($readFilter === 'unread') {
            $query->where(UserNotificationStatus::schema_fields_is_read, 0);
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
            ->where(UserNotificationStatus::schema_fields_user_id, $userId)
            ->where(UserNotificationStatus::schema_fields_is_read, 0)
            ->total();
    }

    /**
     * 标记通知为已读（通过 notification_id）
     */
    public function markAsRead(int $userId, int $notificationId): bool
    {
        $status = clone $this->statusModel;
        $status->clearQuery()
            ->where(UserNotificationStatus::schema_fields_notification_id, $notificationId)
            ->where(UserNotificationStatus::schema_fields_user_id, $userId)
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
            ->where(UserNotificationStatus::schema_fields_user_id, $userId)
            ->where(UserNotificationStatus::schema_fields_is_read, 0)
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
     * 按主题（topic_code）将该类通知全部标记为已读
     *
     * @param int    $userId    用户 ID
     * @param string $topicCode 主题码（如 ai_translation、system_info）
     * @return int 标记成功的条数
     */
    public function markByTopicAsRead(int $userId, string $topicCode): int
    {
        $topicCode = trim($topicCode);
        if ($topicCode === '') {
            return 0;
        }

        $unreadByTopic = $this->statusModel->clearQuery()
            ->joinModel(
                SystemNotification::class,
                'n',
                'main_table.notification_id = n.notification_id',
                'inner',
                'main_table.status_id'
            )
            ->where('main_table.' . UserNotificationStatus::schema_fields_user_id, $userId)
            ->where('main_table.' . UserNotificationStatus::schema_fields_is_read, 0)
            ->where('n.' . SystemNotification::schema_fields_topic_code, $topicCode)
            ->select()
            ->fetchArray();

        $count = 0;
        foreach ($unreadByTopic as $row) {
            $status = clone $this->statusModel;
            $status->clearQuery()->load((int) $row['status_id']);
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
            ->where(UserNotificationSubscription::schema_fields_user_id, $userId)
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
            ->where(UserNotificationSubscription::schema_fields_user_id, $userId)
            ->where(UserNotificationSubscription::schema_fields_topic_code, $topicCode)
            ->where(UserNotificationSubscription::schema_fields_channel, $channel)
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
            ->order(NotificationChannel::schema_fields_channel_code)
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
            ->where('main_table.' . UserNotificationStatus::schema_fields_notification_id, $notificationId)
            ->where('main_table.' . UserNotificationStatus::schema_fields_user_id, $userId)
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

    /**
     * 获取当前通知的上下条（用于详情页翻页）
     * 列表顺序为 create_time DESC，故：上一条 = 时间更新的一条，下一条 = 时间更早的一条
     *
     * @return array{prev_id: int|null, prev_title: string|null, next_id: int|null, next_title: string|null}
     */
    public function getAdjacentNotifications(int $userId, int $notificationId): array
    {
        $status = clone $this->statusModel;
        $status->clearQuery()
            ->joinModel(
                SystemNotification::class,
                'n',
                'main_table.notification_id = n.notification_id',
                'inner',
                'n.notification_id, n.title, n.create_time'
            )
            ->where('main_table.' . UserNotificationStatus::schema_fields_notification_id, $notificationId)
            ->where('main_table.' . UserNotificationStatus::schema_fields_user_id, $userId)
            ->find()
            ->fetch();

        if (!$status->getId()) {
            return ['prev_id' => null, 'prev_title' => null, 'next_id' => null, 'next_title' => null];
        }

        $currentTime = $status->getData('create_time') ?? '';

        $prevId = null;
        $prevTitle = null;
        $nextId = null;
        $nextTitle = null;

        if ((string) $currentTime !== '') {
            $baseJoin = 'main_table.notification_id = n.notification_id';
            $baseFields = 'n.notification_id, n.title';
            $userField = 'main_table.' . UserNotificationStatus::schema_fields_user_id;

            $prev = clone $this->statusModel;
            $prev->clearQuery()
                ->joinModel(SystemNotification::class, 'n', $baseJoin, 'inner', $baseFields)
                ->where($userField, $userId)
                ->where('n.create_time', $currentTime, '>')
                ->order('n.create_time', 'ASC')
                ->limit(1)
                ->select()
                ->fetch();
            $prevItems = $prev->getItems();
            if (!empty($prevItems)) {
                $d = $prevItems[0]->getData();
                $prevId = (int) ($d['notification_id'] ?? 0);
                $prevTitle = $d['title'] ?? '';
            }

            $next = clone $this->statusModel;
            $next->clearQuery()
                ->joinModel(SystemNotification::class, 'n', $baseJoin, 'inner', $baseFields)
                ->where($userField, $userId)
                ->where('n.create_time', $currentTime, '<')
                ->order('n.create_time', 'DESC')
                ->limit(1)
                ->select()
                ->fetch();
            $nextItems = $next->getItems();
            if (!empty($nextItems)) {
                $d = $nextItems[0]->getData();
                $nextId = (int) ($d['notification_id'] ?? 0);
                $nextTitle = $d['title'] ?? '';
            }
        }

        return [
            'prev_id'    => $prevId,
            'prev_title' => $prevTitle,
            'next_id'    => $nextId,
            'next_title' => $nextTitle,
        ];
    }
}
