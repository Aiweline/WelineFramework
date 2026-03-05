<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Model\UserContact;
use Weline\Backend\Model\BackendUser;

class UserContactService
{
    private UserContact $contactModel;
    private BackendUser $userModel;

    public function __construct(
        UserContact $contactModel,
        BackendUser $userModel
    ) {
        $this->contactModel = $contactModel;
        $this->userModel = $userModel;
    }

    /**
     * 获取用户指定渠道的默认联系人
     *
     * @param int $userId
     * @param string $channelCode
     * @return array|null
     */
    public function getDefaultContactByChannel(int $userId, string $channelCode): ?array
    {
        $contact = $this->contactModel->clearQuery()
            ->where(UserContact::schema_fields_user_id, $userId)
            ->where(UserContact::schema_fields_channel_code, $channelCode)
            ->where(UserContact::schema_fields_is_enabled, 1)
            ->where(UserContact::schema_fields_is_default, 1)
            ->select()
            ->fetch();

        if ($contact && $contact->getId()) {
            return $contact->getData();
        }

        $contact = $this->contactModel->clearQuery()
            ->where(UserContact::schema_fields_user_id, $userId)
            ->where(UserContact::schema_fields_channel_code, $channelCode)
            ->where(UserContact::schema_fields_is_enabled, 1)
            ->order(UserContact::schema_fields_ID)
            ->select()
            ->fetch();

        if ($contact && $contact->getId()) {
            return $contact->getData();
        }

        return null;
    }

    /**
     * 获取用户指定渠道的所有联系人
     *
     * @param int $userId
     * @param string $channelCode
     * @return array
     */
    public function getContactsByChannel(int $userId, string $channelCode): array
    {
        return $this->contactModel->clearQuery()
            ->where(UserContact::schema_fields_user_id, $userId)
            ->where(UserContact::schema_fields_channel_code, $channelCode)
            ->where(UserContact::schema_fields_is_enabled, 1)
            ->order(UserContact::schema_fields_is_default, 'DESC')
            ->order(UserContact::schema_fields_ID)
            ->select()
            ->fetchArray();
    }

    /**
     * 获取用户所有联系人
     *
     * @param int $userId
     * @return array
     */
    public function getUserContacts(int $userId): array
    {
        return $this->contactModel->clearQuery()
            ->where(UserContact::schema_fields_user_id, $userId)
            ->where(UserContact::schema_fields_is_enabled, 1)
            ->order(UserContact::schema_fields_channel_code)
            ->order(UserContact::schema_fields_is_default, 'DESC')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取用户联系人（按渠道分组）
     *
     * @param int $userId
     * @return array
     */
    public function getUserContactsGrouped(int $userId): array
    {
        $contacts = $this->getUserContacts($userId);
        $grouped = [];

        foreach ($contacts as $contact) {
            $channel = $contact['channel_code'] ?? $contact['channelCode'] ?? '';
            $channel = strtolower(trim((string) $channel));
            if ($channel === '') {
                continue;
            }
            if (!isset($grouped[$channel])) {
                $grouped[$channel] = [];
            }
            $grouped[$channel][] = $contact;
        }

        return $grouped;
    }

    /**
     * 创建用户联系人
     *
     * @param int $userId
     * @param string $channelCode
     * @param string $contactValue
     * @param array $options
     * @return int|false 联系人 ID 或 false
     */
    public function createContact(int $userId, string $channelCode, string $contactValue, array $options = []): int|false
    {
        $existing = $this->contactModel->clearQuery()
            ->where(UserContact::schema_fields_user_id, $userId)
            ->where(UserContact::schema_fields_channel_code, $channelCode)
            ->where(UserContact::schema_fields_contact_value, $contactValue)
            ->select()
            ->fetch();

        if ($existing && $existing->getId()) {
            return (int) $existing->getId();
        }

        $contactName = $options['contact_name'] ?? '';
        if (empty($contactName)) {
            $user = clone $this->userModel;
            $user->clearQuery()->load($userId);
            $contactName = $user->getData('username') ?: $contactValue;
        }

        $isDefault = $options['is_default'] ?? false;
        if ($isDefault) {
            $this->clearDefaultContact($userId, $channelCode);
        } else {
            $existingContacts = $this->getContactsByChannel($userId, $channelCode);
            if (empty($existingContacts)) {
                $isDefault = true;
            }
        }

        $contact = clone $this->contactModel;
        $contact->clearQuery()
            ->setUserId($userId)
            ->setChannelCode($channelCode)
            ->setContactValue($contactValue)
            ->setContactName($contactName)
            ->setIsVerified((bool) ($options['is_verified'] ?? false))
            ->setIsDefault($isDefault)
            ->setIsEnabled(true)
            ->setExtraConfig($options['extra_config'] ?? []);

        $contact->save();

        return $contact->getId() ? (int) $contact->getId() : false;
    }

    /**
     * 更新联系人
     *
     * @param int $contactId
     * @param array $data
     * @return bool
     */
    public function updateContact(int $contactId, array $data): bool
    {
        $contact = clone $this->contactModel;
        $contact->clearQuery()->load($contactId);

        if (!$contact->getId()) {
            return false;
        }

        if (isset($data['contact_name'])) {
            $contact->setContactName($data['contact_name']);
        }
        if (isset($data['contact_value'])) {
            $contact->setContactValue($data['contact_value']);
        }
        if (isset($data['is_verified'])) {
            $contact->setIsVerified((bool) $data['is_verified']);
        }
        if (isset($data['is_enabled'])) {
            $contact->setIsEnabled((bool) $data['is_enabled']);
        }
        if (isset($data['extra_config'])) {
            $contact->setExtraConfig($data['extra_config']);
        }

        $contact->save();
        return true;
    }

    /**
     * 设置默认联系人
     *
     * @param int $userId
     * @param string $channelCode
     * @param int $contactId
     * @return bool
     */
    public function setDefaultContact(int $userId, string $channelCode, int $contactId): bool
    {
        $contact = clone $this->contactModel;
        $contact->clearQuery()->load($contactId);

        if (!$contact->getId()) {
            return false;
        }

        if ($contact->getUserId() !== $userId || $contact->getChannelCode() !== $channelCode) {
            return false;
        }

        $this->clearDefaultContact($userId, $channelCode);

        $contact->setIsDefault(true)->save();
        return true;
    }

    /**
     * 清除渠道的默认联系人标记
     *
     * @param int $userId
     * @param string $channelCode
     */
    private function clearDefaultContact(int $userId, string $channelCode): void
    {
        $contacts = $this->contactModel->clearQuery()
            ->where(UserContact::schema_fields_user_id, $userId)
            ->where(UserContact::schema_fields_channel_code, $channelCode)
            ->where(UserContact::schema_fields_is_default, 1)
            ->select()
            ->fetchArray();

        foreach ($contacts as $contactData) {
            $contact = clone $this->contactModel;
            $contact->clearQuery()->load((int) $contactData['contact_id']);
            if ($contact->getId()) {
                $contact->setIsDefault(false)->save();
            }
        }
    }

    /**
     * 删除联系人
     *
     * @param int $contactId
     * @return bool
     */
    public function deleteContact(int $contactId): bool
    {
        $contact = clone $this->contactModel;
        $contact->clearQuery()->load($contactId);

        if (!$contact->getId()) {
            return false;
        }

        $contact->delete()->fetch();
        return true;
    }

    /**
     * 为用户自动创建默认联系人（用于用户注册时）
     *
     * @param int $userId
     * @param string $email
     * @param string|null $phone
     * @return array 创建的联系人 ID 列表
     */
    public function createDefaultContactsForUser(int $userId, string $email, ?string $phone = null): array
    {
        $createdIds = [];

        if (!empty($email)) {
            $contactId = $this->createContact($userId, 'email', $email, [
                'is_default' => true,
                'is_verified' => true,
            ]);
            if ($contactId) {
                $createdIds['email'] = $contactId;
            }
        }

        if (!empty($phone)) {
            $contactId = $this->createContact($userId, 'sms', $phone, [
                'is_default' => true,
                'is_verified' => false,
            ]);
            if ($contactId) {
                $createdIds['sms'] = $contactId;
            }
        }

        return $createdIds;
    }

    /**
     * 根据渠道获取联系人用于发送通知
     *
     * @param int $userId
     * @param string $channelCode
     * @return array|null 包含 contact_value 和其他配置
     */
    public function getContactForNotification(int $userId, string $channelCode): ?array
    {
        $contact = $this->getDefaultContactByChannel($userId, $channelCode);

        if (!$contact) {
            return null;
        }

        return [
            'contact_id' => (int) $contact['contact_id'],
            'contact_value' => $contact['contact_value'],
            'contact_name' => $contact['contact_name'] ?: '',
            'extra_config' => json_decode($contact['extra_config'] ?? '[]', true) ?: [],
        ];
    }
}
