<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Model\UserContact as UserContactModel;
use Weline\Backend\Service\UserContactService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Backend::user_contact', '联系人管理', 'mdi-account-box', '管理用户联系人', 'Weline_Backend::notification_settings')]
class UserContact extends BackendController
{
    private UserContactService $contactService;

    public function __construct()
    {
        $this->contactService = ObjectManager::getInstance(UserContactService::class);
    }

    #[Acl('Weline_Backend::user_contact_index', '我的联系人', 'mdi-contacts', '查看我的联系人')]
    public function index(): string
    {
        $userId = (int) $this->session->getLoginUserId();

        $contactsGrouped = $this->contactService->getUserContactsGrouped($userId);

        $channels = [
            'email' => ['name' => __('邮件'), 'icon' => 'mdi-email'],
            'sms' => ['name' => __('短信'), 'icon' => 'mdi-cellphone'],
            'feishu' => ['name' => __('飞书'), 'icon' => 'mdi-message'],
            'dingtalk' => ['name' => __('钉钉'), 'icon' => 'mdi-message-text'],
            'webhook' => ['name' => __('Webhook'), 'icon' => 'mdi-webhook'],
        ];

        $this->assign('contacts_grouped', $contactsGrouped);
        $this->assign('channels', $channels);
        $this->assign('page_title', __('我的联系人'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::user_contact_save', '保存联系人', 'mdi-content-save', '保存联系人')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $channelCode = $this->request->getPost('channel_code', '');
        $contactValue = $this->request->getPost('contact_value', '');
        $contactName = $this->request->getPost('contact_name', '');
        $isDefault = (bool) $this->request->getPost('is_default', false);

        if (empty($channelCode) || empty($contactValue)) {
            return $this->jsonError(__('渠道和联系方式不能为空'));
        }

        try {
            $contactId = $this->contactService->createContact($userId, $channelCode, $contactValue, [
                'contact_name' => $contactName,
                'is_default' => $isDefault,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError(__('保存失败：%{1}', [$e->getMessage()]));
        }

        if ($contactId) {
            return $this->jsonSuccess(__('保存成功'), ['contact_id' => $contactId]);
        }

        return $this->jsonError(__('保存失败，请稍后重试或联系管理员'));
    }

    #[Acl('Weline_Backend::user_contact_update', '更新联系人', 'mdi-pencil', '更新联系人')]
    public function update(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $contactId = (int) $this->request->getPost('contact_id', 0);

        $contactModel = ObjectManager::getInstance(UserContactModel::class);
        $contactModel->clearQuery()->load($contactId);

        if (!$contactModel->getId() || $contactModel->getUserId() !== $userId) {
            return $this->jsonError(__('联系人不存在'));
        }

        $data = [];
        if ($this->request->getPost('contact_name') !== null) {
            $data['contact_name'] = $this->request->getPost('contact_name');
        }
        if ($this->request->getPost('contact_value') !== null) {
            $data['contact_value'] = $this->request->getPost('contact_value');
        }
        if ($this->request->getPost('is_enabled') !== null) {
            $data['is_enabled'] = (bool) $this->request->getPost('is_enabled');
        }

        $success = $this->contactService->updateContact($contactId, $data);

        if ($success) {
            return $this->jsonSuccess(__('更新成功'));
        }

        return $this->jsonError(__('更新失败'));
    }

    #[Acl('Weline_Backend::user_contact_set_default', '设为默认', 'mdi-star', '设为默认联系人')]
    public function setDefault(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $contactId = (int) $this->request->getPost('contact_id', 0);

        $contactModel = ObjectManager::getInstance(UserContactModel::class);
        $contactModel->clearQuery()->load($contactId);

        if (!$contactModel->getId() || $contactModel->getUserId() !== $userId) {
            return $this->jsonError(__('联系人不存在'));
        }

        $success = $this->contactService->setDefaultContact(
            $userId,
            $contactModel->getChannelCode(),
            $contactId
        );

        if ($success) {
            return $this->jsonSuccess(__('已设为默认'));
        }

        return $this->jsonError(__('设置失败'));
    }

    #[Acl('Weline_Backend::user_contact_delete', '删除联系人', 'mdi-delete', '删除联系人')]
    public function delete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }

        $userId = (int) $this->session->getLoginUserId();
        $contactId = (int) $this->request->getPost('contact_id', 0);

        $contactModel = ObjectManager::getInstance(UserContactModel::class);
        $contactModel->clearQuery()->load($contactId);

        if (!$contactModel->getId() || $contactModel->getUserId() !== $userId) {
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
