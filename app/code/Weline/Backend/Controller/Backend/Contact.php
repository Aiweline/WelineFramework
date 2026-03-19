<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Model\Contact as ContactModel;
use Weline\Backend\Service\ChannelAdapterCollector;
use Weline\Backend\Service\ContactService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Backend::contact', '联系人', 'mdi-account-box', '管理联系人（多渠道配置）', 'Weline_Backend::notification_settings')]
class Contact extends BackendController
{
    private ContactService $contactService;
    private ChannelAdapterCollector $adapterCollector;

    public function __construct()
    {
        $this->contactService = ObjectManager::getInstance(ContactService::class);
        $this->adapterCollector = ObjectManager::getInstance(ChannelAdapterCollector::class);
    }

    #[Acl('Weline_Backend::contact_index', '联系人列表', 'mdi-contacts', '查看联系人')]
    public function index(): string
    {
        $userId = (int) $this->session->getLoginUserId();
        $contacts = $this->contactService->getContactsByUser($userId);
        if (empty($contacts)) {
            $this->contactService->createContact($userId, __('默认联系人'));
            $contacts = $this->contactService->getContactsByUser($userId);
        }

        $adapters = $this->adapterCollector->getAdapters();
        $adapterMap = [];
        foreach ($adapters as $adapter) {
            $code = $adapter->getChannelCode();
            $adapterMap[$code] = [
                'name' => $adapter->getChannelName(),
                'config_fields' => $adapter->getConfigFields(),
            ];
            if ($code === 'email') {
                $res = w_query('smtp', 'isAvailable', []);
                $adapterMap[$code]['available'] = (bool) ($res['available'] ?? false);
            }
        }

        $this->assign('contacts', $contacts);
        $this->assign('adapter_map', $adapterMap);
        $this->assign('page_title', __('联系人'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::contact_save', '保存联系人', 'mdi-content-save', '保存联系人')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $contactId = (int) $this->request->getPost('contact_id', 0);
        $contactName = trim((string) $this->request->getPost('contact_name', ''));

        if ($contactName === '') {
            return $this->jsonError(__('联系人名称不能为空'));
        }

        if ($contactId > 0) {
            $contact = ObjectManager::getInstance(ContactModel::class);
            $contact->clearQuery()->load($contactId);
            if (!$contact->getId() || $contact->getUserId() !== $userId) {
                return $this->jsonError(__('联系人不存在'));
            }
            $this->contactService->updateContact($contactId, ['contact_name' => $contactName]);
            return $this->jsonSuccess(__('更新成功'), ['contact_id' => $contactId]);
        }

        $newId = $this->contactService->createContact($userId, $contactName);
        if ($newId) {
            return $this->jsonSuccess(__('创建成功'), ['contact_id' => $newId]);
        }

        return $this->jsonError(__('保存失败'));
    }

    #[Acl('Weline_Backend::contact_add_channel_config', '添加渠道配置', 'mdi-plus', '为联系人添加渠道配置')]
    public function addChannelConfig(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $contactId = (int) $this->request->getPost('contact_id', 0);
        $channelCode = trim((string) $this->request->getPost('channel_code', ''));
        $config = $this->request->getPost('config', []);

        if ($contactId <= 0 || $channelCode === '') {
            return $this->jsonError(__('联系人和渠道不能为空'));
        }

        $contact = ObjectManager::getInstance(ContactModel::class);
        $contact->clearQuery()->load($contactId);
        if (!$contact->getId() || $contact->getUserId() !== $userId) {
            return $this->jsonError(__('联系人不存在'));
        }

        if (!is_array($config)) {
            $config = [];
        }

        $success = $this->contactService->addChannelConfig($contactId, $channelCode, $config);
        if ($success) {
            return $this->jsonSuccess(__('保存成功'));
        }
        return $this->jsonError(__('保存失败'));
    }

    #[Acl('Weline_Backend::contact_remove_channel_config', '移除渠道配置', 'mdi-minus', '移除联系人的某渠道配置')]
    public function removeChannelConfig(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $contactId = (int) $this->request->getPost('contact_id', 0);
        $channelCode = trim((string) $this->request->getPost('channel_code', ''));

        if ($contactId <= 0 || $channelCode === '') {
            return $this->jsonError(__('联系人和渠道不能为空'));
        }

        $contact = ObjectManager::getInstance(ContactModel::class);
        $contact->clearQuery()->load($contactId);
        if (!$contact->getId() || $contact->getUserId() !== $userId) {
            return $this->jsonError(__('联系人不存在'));
        }

        $success = $this->contactService->removeChannelConfig($contactId, $channelCode);
        if ($success) {
            return $this->jsonSuccess(__('已移除'));
        }
        return $this->jsonError(__('操作失败'));
    }

    #[Acl('Weline_Backend::contact_delete', '删除联系人', 'mdi-delete', '删除联系人')]
    public function delete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $contactId = (int) $this->request->getPost('contact_id', 0);

        $contact = ObjectManager::getInstance(ContactModel::class);
        $contact->clearQuery()->load($contactId);
        if (!$contact->getId() || $contact->getUserId() !== $userId) {
            return $this->jsonError(__('联系人不存在'));
        }

        $success = $this->contactService->deleteContact($contactId);
        if ($success) {
            return $this->jsonSuccess(__('删除成功'));
        }
        return $this->jsonError(__('删除失败'));
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
