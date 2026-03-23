<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Enum\NotificationType;
use Weline\Backend\Model\UserNotificationSubscription;
use Weline\Backend\Service\ChannelAdapterCollector;
use Weline\Backend\Service\ContactService;
use Weline\Backend\Service\TopicCollector;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Backend::notification_subscription', '消息订阅', 'mdi-bell-cog', '管理消息订阅', 'Weline_Backend::notification_settings')]
class NotificationSubscription extends BackendController
{
    private TopicCollector $topicCollector;
    private UserNotificationSubscription $subscriptionModel;
    private ContactService $contactService;
    private ChannelAdapterCollector $adapterCollector;

    public function __construct()
    {
        $this->topicCollector = ObjectManager::getInstance(TopicCollector::class);
        $this->subscriptionModel = ObjectManager::getInstance(UserNotificationSubscription::class);
        $this->contactService = ObjectManager::getInstance(ContactService::class);
        $this->adapterCollector = ObjectManager::getInstance(ChannelAdapterCollector::class);
    }

    #[Acl('Weline_Backend::notification_subscription_index', '我的订阅', 'mdi-bell', '查看我的消息订阅')]
    public function index(): string
    {
        $userId = (int) $this->session->getLoginUserId();

        $this->topicCollector->collect();
        $topicsGrouped = $this->topicCollector->getTopicsGrouped();

        $subscriptions = $this->subscriptionModel->clearQuery()
            ->where(UserNotificationSubscription::schema_fields_user_id, $userId)
            ->select()
            ->fetchArray();

        $subscriptionMap = [];
        foreach ($subscriptions as $sub) {
            $key = $sub['topic_code'] . '_' . $sub['channel'];
            $subscriptionMap[$key] = $sub;
        }

        $this->assign('topics_grouped', $topicsGrouped);
        $this->assign('subscription_map', $subscriptionMap);
        $this->assign('channels', $this->getAvailableChannels());
        $this->assign('types', NotificationType::getTypeOptions());
        $this->assign('user_contacts', $this->contactService->getUserContactsGrouped($userId));
        $this->assign('page_title', __('消息订阅'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::notification_subscription_save', '保存订阅', 'mdi-content-save', '保存订阅设置')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $topicCode = (string) $this->request->getPost('topic_code', '');
        $channel = (string) $this->request->getPost('channel', '');
        $minType = (string) $this->request->getPost('min_type', 'info');
        $isEnabled = (bool) $this->request->getPost('is_enabled', true);

        if ($topicCode === '' || $channel === '') {
            return $this->jsonError(__('主题和渠道不能为空'));
        }

        $existing = $this->subscriptionModel->clearQuery()
            ->where(UserNotificationSubscription::schema_fields_user_id, $userId)
            ->where(UserNotificationSubscription::schema_fields_topic_code, $topicCode)
            ->where(UserNotificationSubscription::schema_fields_channel, $channel)
            ->select()
            ->fetch();

        if ($existing && $existing->getId()) {
            $existing->setMinType($minType)
                ->setIsEnabled($isEnabled)
                ->save();
        } else {
            $sub = clone $this->subscriptionModel;
            $sub->clearQuery()
                ->setUserId($userId)
                ->setTopicCode($topicCode)
                ->setChannel($channel)
                ->setMinType($minType)
                ->setIsEnabled($isEnabled)
                ->save();
        }

        return $this->jsonSuccess(__('保存成功'));
    }

    #[Acl('Weline_Backend::notification_subscription_toggle', '切换订阅', 'mdi-toggle-switch', '切换订阅状态')]
    public function toggle(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $topicCode = (string) $this->request->getPost('topic_code', '');
        $channel = (string) $this->request->getPost('channel', '');

        if ($topicCode === '' || $channel === '') {
            return $this->jsonError(__('参数错误'));
        }

        $existing = $this->subscriptionModel->clearQuery()
            ->where(UserNotificationSubscription::schema_fields_user_id, $userId)
            ->where(UserNotificationSubscription::schema_fields_topic_code, $topicCode)
            ->where(UserNotificationSubscription::schema_fields_channel, $channel)
            ->select()
            ->fetch();

        if ($existing && $existing->getId()) {
            $newStatus = !$existing->isEnabled();
            $existing->setIsEnabled($newStatus)->save();
            $message = $newStatus ? __('已启用') : __('已禁用');
        } else {
            $sub = clone $this->subscriptionModel;
            $sub->clearQuery()
                ->setUserId($userId)
                ->setTopicCode($topicCode)
                ->setChannel($channel)
                ->setMinType('info')
                ->setIsEnabled(true)
                ->save();
            $message = __('已启用');
        }

        return $this->jsonSuccess($message);
    }

    private function getAvailableChannels(): array
    {
        $channels = [
            'backend' => ['name' => __('后台通知'), 'icon' => 'mdi-desktop-mac'],
        ];

        foreach ($this->adapterCollector->getAdapters() as $adapter) {
            $code = $adapter->getChannelCode();
            $channels[$code] = [
                'name' => $adapter->getChannelName(),
                'icon' => $this->getChannelIcon($code),
            ];
        }

        return $channels;
    }

    private function getChannelIcon(string $channelCode): string
    {
        return match ($channelCode) {
            'email' => 'mdi-email',
            'feishu' => 'mdi-message',
            'dingtalk' => 'mdi-message-text',
            'webhook' => 'mdi-webhook',
            'telegram' => 'mdi-telegram',
            default => 'mdi-bell-outline',
        };
    }

    private function jsonSuccess(string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return (string) json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function jsonError(string $message): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return (string) json_encode([
            'success' => false,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
    }
}
