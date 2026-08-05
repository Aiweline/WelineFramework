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
    private ContactService $contactService;
    private ChannelAdapterCollector $adapterCollector;

    public function __construct(
        UserNotificationSubscription $subscriptionModel,
        UserNotificationStatus $statusModel,
        NotificationChannel $channelModel,
        NotificationTopic $topicModel,
        SystemNotification $notificationModel,
        BackendUser $userModel,
        ContactService $contactService,
        ChannelAdapterCollector $adapterCollector
    ) {
        $this->subscriptionModel = $subscriptionModel;
        $this->statusModel = $statusModel;
        $this->channelModel = $channelModel;
        $this->topicModel = $topicModel;
        $this->notificationModel = $notificationModel;
        $this->userModel = $userModel;
        $this->contactService = $contactService;
        $this->adapterCollector = $adapterCollector;
    }

    /**
     * 路由通知到各个渠道。
     *
     * @param array{
     *   notification_id?:int,
     *   topic_code?:string,
     *   type?:string,
     *   title?:string,
     *   content?:string,
     *   priority?:int,
     *   metadata?:array,
     *   notify_users?:list<int>,
     *   require_explicit_recipients?:bool
     * } $notification
     */
    public function route(array $notification): void
    {
        $notificationId = (int) ($notification['notification_id'] ?? 0);
        $specifiedUsers = $notification['notify_users'] ?? [];
        $requireExplicit = !empty($notification['require_explicit_recipients']);

        // Scoped urgent：必须显式授权用户；空列表零广播（审计行由 Observer 保留）。
        if ($requireExplicit && $this->normalizeUserIds($specifiedUsers) === []) {
            return;
        }

        $this->createUserStatuses($notificationId, $specifiedUsers, $requireExplicit);

        $this->routeToExternalChannels($notification, $requireExplicit);
    }

    /**
     * 为用户创建通知状态记录
     *
     * @param list<int>|array<mixed> $specifiedUsers
     */
    private function createUserStatuses(int $notificationId, array $specifiedUsers = [], bool $requireExplicit = false): void
    {
        if ($notificationId <= 0) {
            return;
        }

        $users = $this->getTargetUsers($specifiedUsers, $requireExplicit);

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
     * 获取目标用户列表。
     *
     * @param list<int>|array<mixed> $specifiedUsers
     * @return list<array<string,mixed>>
     */
    private function getTargetUsers(array $specifiedUsers = [], bool $requireExplicit = false): array
    {
        $ids = $this->normalizeUserIds($specifiedUsers);
        if ($requireExplicit && $ids === []) {
            return [];
        }

        $query = $this->userModel->clearQuery()
            ->where(BackendUser::schema_fields_is_enabled, 1)
            ->where(BackendUser::schema_fields_is_deleted, 0);

        if ($ids !== []) {
            $query->where(BackendUser::schema_fields_ID, $ids, 'IN');
        } elseif ($requireExplicit) {
            return [];
        }

        return $query->select()->fetchArray();
    }

    /**
     * 路由到外部渠道
     *
     * @param array<string, mixed> $notification
     */
    private function routeToExternalChannels(array $notification, bool $requireExplicit = false): void
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
            $channelConfig = $this->normalizeConfig($channel['channel_config'] ?? []);

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

            $users = $this->getTargetUsers($specifiedUsers, $requireExplicit);
            foreach ($users as $user) {
                $userId = (int) ($user['user_id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }

                if (!$this->isUserSubscribed($userId, $topicCode, $channelCode, $type)) {
                    continue;
                }

                $contacts = $this->contactService->getContactsForNotification($userId, $channelCode);
                if (empty($contacts)) {
                    continue;
                }

                foreach ($contacts as $contactItem) {
                    $notificationWithContact = $notification;
                    $notificationWithContact['contact'] = [
                        'contact_id' => $contactItem['contact_id'],
                        'contact_name' => $contactItem['contact_name'],
                    ];
                    $notificationWithContact['recipient_user_id'] = $userId;
                    $notificationWithContact['recipient_name'] = $user['username'] ?? '';

                    try {
                        $mergedConfig = array_replace($channelConfig, $contactItem['channel_config']);
                        $success = $adapter->send($notificationWithContact, $mergedConfig);
                        if ($success && !in_array($channelCode, $notifiedChannels, true)) {
                            $notifiedChannels[] = $channelCode;
                        }
                    } catch (\Exception $e) {
                    }
                }
            }
        }

        if (!empty($notifiedChannels) && $notificationId > 0) {
            $this->updateExternalNotificationStatus($notificationId, $notifiedChannels);
        }
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function normalizeUserIds(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $userId = (int)$id;
            if ($userId > 0) {
                $out[] = $userId;
            }
        }

        return \array_values(\array_unique($out));
    }

    /**
     * 获取已启用的外部渠道
     */
    private function getEnabledChannels(): array
    {
        return $this->channelModel->clearQuery()
            ->where(NotificationChannel::schema_fields_is_enabled, 1)
            ->select()
            ->fetchArray();
    }

    /**
     * 获取渠道适配器
     */
    private function getChannelAdapter(string $channelCode): ?ChannelAdapterInterface
    {
        return $this->adapterCollector->getAdapterByCode($channelCode);
    }

    private function normalizeConfig(array|string $config): array
    {
        if (is_array($config)) {
            return $config;
        }

        $decoded = json_decode((string) $config, true);
        return is_array($decoded) ? $decoded : [];
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
            ->where(UserNotificationSubscription::schema_fields_user_id, $userId)
            ->where(UserNotificationSubscription::schema_fields_topic_code, $topicCode)
            ->where(UserNotificationSubscription::schema_fields_channel, $channel)
            ->where(UserNotificationSubscription::schema_fields_is_enabled, 1)
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
            ->where(UserNotificationSubscription::schema_fields_user_id, $userId)
            ->where(UserNotificationSubscription::schema_fields_topic_code, $topicCode)
            ->where(UserNotificationSubscription::schema_fields_is_enabled, 1)
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
