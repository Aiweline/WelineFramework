<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Model\Contact;

class ContactService
{
    private Contact $contactModel;

    public function __construct(Contact $contactModel)
    {
        $this->contactModel = $contactModel;
    }

    /**
     * 创建联系人（仅名称，渠道配置后续通过 addChannelConfig 添加）
     */
    public function createContact(int $userId, string $contactName): int|false
    {
        $contact = clone $this->contactModel;
        $contact->clearQuery()
            ->setUserId($userId)
            ->setContactName($contactName)
            ->setChannelConfig([])
            ->setChannels('')
            ->setIsEnabled(true);
        $contact->save();
        return $contact->getId() ? (int) $contact->getId() : false;
    }

    /**
     * 更新联系人（名称、启用状态等）
     */
    public function updateContact(int $contactId, array $data): bool
    {
        $contact = clone $this->contactModel;
        $contact->clearQuery()->load($contactId);
        if (!$contact->getId()) {
            return false;
        }
        if (isset($data['contact_name'])) {
            $contact->setContactName((string) $data['contact_name']);
        }
        if (isset($data['is_enabled'])) {
            $contact->setIsEnabled((bool) $data['is_enabled']);
        }
        $contact->save();
        return true;
    }

    /**
     * 删除联系人
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
     * 为联系人添加/覆盖某渠道配置，并更新 channels 逗号列表
     */
    public function addChannelConfig(int $contactId, string $channelCode, array $config): bool
    {
        $contact = clone $this->contactModel;
        $contact->clearQuery()->load($contactId);
        if (!$contact->getId()) {
            return false;
        }
        $channelCode = strtolower(trim($channelCode));
        if ($channelCode === '') {
            return false;
        }
        $all = $contact->getChannelConfig();
        $all[$channelCode] = $config;
        $contact->setChannelConfig($all);
        $channelsList = array_keys($all);
        $contact->setChannels(implode(',', $channelsList));
        $contact->save();
        return true;
    }

    /**
     * 移除联系人的某渠道配置
     */
    public function removeChannelConfig(int $contactId, string $channelCode): bool
    {
        $contact = clone $this->contactModel;
        $contact->clearQuery()->load($contactId);
        if (!$contact->getId()) {
            return false;
        }
        $channelCode = strtolower(trim($channelCode));
        $all = $contact->getChannelConfig();
        unset($all[$channelCode]);
        $contact->setChannelConfig($all);
        $contact->setChannels(implode(',', array_keys($all)));
        $contact->save();
        return true;
    }

    /**
     * 获取用户下所有联系人
     */
    public function getContactsByUser(int $userId): array
    {
        return $this->contactModel->clearQuery()
            ->where(Contact::schema_fields_user_id, $userId)
            ->where(Contact::schema_fields_is_enabled, 1)
            ->order(Contact::schema_fields_ID)
            ->select()
            ->fetchArray();
    }

    /**
     * 根据渠道获取用于发送通知的联系人列表
     * 返回该用户下「在该渠道有配置且启用」的联系人；每条含 contact_id、contact_name 及该渠道的 config，供 adapter->send($notification, $config) 使用。
     *
     * @return array 元素为 ['contact_id' => int, 'contact_name' => string, 'channel_config' => array] 其中 channel_config 即该联系人在该渠道的配置
     */
    public function getContactsForNotification(int $userId, string $channelCode): array
    {
        $channelCode = strtolower(trim($channelCode));
        $rows = $this->contactModel->clearQuery()
            ->where(Contact::schema_fields_user_id, $userId)
            ->where(Contact::schema_fields_is_enabled, 1)
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            $config = $row[Contact::schema_fields_channel_config] ?? '';
            $decoded = is_string($config) ? json_decode($config, true) : $config;
            $channelConfig = is_array($decoded) ? $decoded : [];
            if (!isset($channelConfig[$channelCode]) || !is_array($channelConfig[$channelCode])) {
                continue;
            }
            $result[] = [
                'contact_id' => (int) ($row['contact_id'] ?? $row[Contact::schema_fields_ID]),
                'contact_name' => (string) ($row[Contact::schema_fields_contact_name] ?? ''),
                'channel_config' => $channelConfig[$channelCode],
            ];
        }
        return $result;
    }
}
