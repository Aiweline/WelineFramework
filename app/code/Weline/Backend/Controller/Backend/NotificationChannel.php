<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Enum\NotificationType;
use Weline\Backend\Model\NotificationChannel as ChannelModel;
use Weline\Backend\Service\ChannelAdapterCollector;
use Weline\Backend\Service\TopicCollector;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Backend::notification_channel', '渠道配置', 'mdi-tune', '配置通知渠道', 'Weline_Backend::notification_settings')]
class NotificationChannel extends BackendController
{
    private ChannelModel $channelModel;
    private TopicCollector $topicCollector;
    private ChannelAdapterCollector $adapterCollector;

    public function __construct()
    {
        $this->channelModel = ObjectManager::getInstance(ChannelModel::class);
        $this->topicCollector = ObjectManager::getInstance(TopicCollector::class);
        $this->adapterCollector = ObjectManager::getInstance(ChannelAdapterCollector::class);
    }

    #[Acl('Weline_Backend::notification_channel_index', '渠道列表', 'mdi-format-list-bulleted', '查看通知渠道')]
    public function index(): string
    {
        $channels = $this->channelModel->clearQuery()
            ->order(ChannelModel::schema_fields_ID)
            ->select()
            ->fetchArray();

        $adapters = $this->adapterCollector->getAdapters();
        $adapterMap = [];
        foreach ($adapters as $adapter) {
            $adapterMap[$adapter->getChannelCode()] = [
                'name' => $adapter->getChannelName(),
                'config_fields' => $adapter->getConfigFields(),
            ];
        }

        $this->assign('channels', $channels);
        $this->assign('adapter_map', $adapterMap);
        $this->assign('page_title', __('渠道配置'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::notification_channel_form', '编辑渠道', 'mdi-pencil', '编辑渠道配置')]
    public function form(): string
    {
        $channelId = (int) $this->request->getParam('id', 0);

        $channel = null;
        if ($channelId > 0) {
            $channel = clone $this->channelModel;
            $channel->clearQuery()->load($channelId);
            if (!$channel->getId()) {
                $channel = null;
            }
        }

        $adapters = $this->adapterCollector->getAdapters();
        $adapterOptions = [];
        $configFields = [];
        foreach ($adapters as $adapter) {
            $code = $adapter->getChannelCode();
            $adapterOptions[$code] = $adapter->getChannelName();
            $configFields[$code] = $adapter->getConfigFields();
        }

        $topics = $this->topicCollector->collect();
        $types = NotificationType::getTypeOptions();

        $this->assign('channel', $channel);
        $this->assign('adapter_options', $adapterOptions);
        $this->assign('config_fields', $configFields);
        $this->assign('topics', $topics);
        $this->assign('types', $types);
        $this->assign('page_title', $channel ? __('编辑渠道') : __('新建渠道'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::notification_channel_save', '保存渠道', 'mdi-content-save', '保存渠道配置')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $channelId = (int) $this->request->getPost('channel_id', 0);
        $channelCode = $this->request->getPost('channel_code', '');
        $channelName = $this->request->getPost('channel_name', '');
        $channelConfig = $this->request->getPost('channel_config', []);
        $subscribedTopics = $this->request->getPost('subscribed_topics', []);
        $minType = $this->request->getPost('min_type', 'info');
        $isEnabled = (bool) $this->request->getPost('is_enabled', true);

        if (empty($channelCode) || empty($channelName)) {
            return $this->jsonError(__('渠道标识和名称不能为空'));
        }

        if ($channelId > 0) {
            $channel = clone $this->channelModel;
            $channel->clearQuery()->load($channelId);
            if (!$channel->getId()) {
                return $this->jsonError(__('渠道不存在'));
            }
        } else {
            $existing = $this->channelModel->clearQuery()
                ->where(ChannelModel::schema_fields_channel_code, $channelCode)
                ->select()
                ->fetch();
            if ($existing && $existing->getId()) {
                return $this->jsonError(__('渠道标识已存在'));
            }
            $channel = clone $this->channelModel;
            $channel->clearQuery();
        }

        $channel->setChannelCode($channelCode)
            ->setChannelName($channelName)
            ->setChannelConfig(is_array($channelConfig) ? $channelConfig : [])
            ->setSubscribedTopics(is_array($subscribedTopics) ? $subscribedTopics : [])
            ->setMinType($minType)
            ->setIsEnabled($isEnabled)
            ->save();

        return $this->jsonSuccess(__('保存成功'), ['channel_id' => $channel->getId()]);
    }

    #[Acl('Weline_Backend::notification_channel_test', '测试渠道', 'mdi-send', '测试渠道连通性')]
    public function test(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $channelCode = $this->request->getPost('channel_code', '');
        $channelConfig = $this->request->getPost('channel_config', []);

        if (empty($channelCode)) {
            return $this->jsonError(__('请选择渠道类型'));
        }

        $adapters = $this->adapterCollector->getAdapters();
        $adapter = null;
        foreach ($adapters as $a) {
            if ($a->getChannelCode() === $channelCode) {
                $adapter = $a;
                break;
            }
        }

        if (!$adapter) {
            return $this->jsonError(__('渠道适配器不存在'));
        }

        $config = is_array($channelConfig) ? $channelConfig : [];

        try {
            $success = $adapter->test($config);
            if ($success) {
                return $this->jsonSuccess(__('测试成功，渠道配置正确'));
            }
            return $this->jsonError(__('测试失败，请检查配置'));
        } catch (\Exception $e) {
            return $this->jsonError(__('测试失败：%{1}', $e->getMessage()));
        }
    }

    #[Acl('Weline_Backend::notification_channel_delete', '删除渠道', 'mdi-delete', '删除渠道配置')]
    public function delete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $channelId = (int) $this->request->getPost('channel_id', 0);

        if ($channelId <= 0) {
            return $this->jsonError(__('参数错误'));
        }

        $channel = clone $this->channelModel;
        $channel->clearQuery()->load($channelId);

        if (!$channel->getId()) {
            return $this->jsonError(__('渠道不存在'));
        }

        $channel->delete()->fetch();

        return $this->jsonSuccess(__('删除成功'));
    }

    private function jsonSuccess(string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function jsonError(string $message): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => false,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
    }
}
