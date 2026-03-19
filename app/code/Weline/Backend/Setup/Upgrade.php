<?php

declare(strict_types=1);

namespace Weline\Backend\Setup;

use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\Contact;
use Weline\Backend\Model\UserContact;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

class Upgrade implements UpgradeInterface
{
    /**
     * 升级时确保存在默认管理员用户，并为 user_id=1 补全角色记录（修复历史上 Install 未正确写入 user_id 的问题）
     * 每次升级都执行补全逻辑，避免首次安装时因阶段顺序导致 Install 未写入而缺数据。
     */
    public function setup(Setup $setup, Context $context): void
    {
        $version = $context->getVersion();

        $this->ensureDefaultAdminUser();
        if (version_compare($version, '1.2.1', '<')) {
            $this->ensureUser1HasRole1();
        }
        if (version_compare($version, '1.3.0', '<')) {
            $this->createContactTable($setup);
            $this->migrateUserContactToContact();
        }
    }

    /**
     * 创建联系人表 weline_backend_contact（联系人多渠道配置改造）
     */
    private function createContactTable(Setup $setup): void
    {
        $db = $setup->getDb();
        if ($db->tableExist(Contact::schema_table)) {
            return;
        }
        $db->createTable(Contact::schema_table, '联系人表（实体，可绑定多渠道配置）')
            ->addColumn(Contact::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主键')
            ->addColumn(Contact::schema_fields_user_id, TableInterface::column_type_INTEGER, null, 'not null', '后台用户 ID')
            ->addColumn(Contact::schema_fields_contact_name, TableInterface::column_type_VARCHAR, 100, 'not null', '联系人显示名称')
            ->addColumn(Contact::schema_fields_channel_config, TableInterface::column_type_TEXT, null, '', '按渠道的配置 JSON')
            ->addColumn(Contact::schema_fields_channels, TableInterface::column_type_VARCHAR, 255, "default ''", '已配置渠道逗号分隔')
            ->addColumn(Contact::schema_fields_is_enabled, TableInterface::column_type_SMALLINT, 1, 'default 1', '是否启用')
            ->addIndex('INDEX', 'idx_user_id', [Contact::schema_fields_user_id], '用户索引')
            ->addIndex('INDEX', 'idx_is_enabled', [Contact::schema_fields_is_enabled], '启用索引')
            ->create();
    }

    /**
     * 将旧表 weline_backend_user_contact 数据迁移到 weline_backend_contact（方案 A：每条旧记录生成一个新联系人）
     */
    private function migrateUserContactToContact(): void
    {
        $userContactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel = ObjectManager::getInstance(Contact::class);
        try {
            $rows = $userContactModel->clearQuery()->select()->fetchArray();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $channelCode = strtolower(trim((string) ($row['channel_code'] ?? '')));
            $contactValue = trim((string) ($row['contact_value'] ?? ''));
            $contactName = trim((string) ($row['contact_name'] ?? ''));
            if ($userId <= 0 || $channelCode === '' || $contactValue === '') {
                continue;
            }
            $displayName = $contactName !== '' ? $contactName : mb_substr($contactValue, 0, 50);
            $channelConfig = $this->buildChannelConfigFromLegacy($channelCode, $contactValue);
            $contact = clone $contactModel;
            $contact->setUserId($userId)
                ->setContactName($displayName)
                ->setChannelConfig($channelConfig)
                ->setChannels($channelCode)
                ->setIsEnabled((bool) ($row['is_enabled'] ?? true));
            $contact->save();
        }
    }

    private function buildChannelConfigFromLegacy(string $channelCode, string $contactValue): array
    {
        $config = [];
        switch ($channelCode) {
            case 'email':
                $config['email'] = ['to_email' => $contactValue];
                break;
            case 'webhook':
                $config['webhook'] = ['webhook_url' => $contactValue, 'secret' => '', 'custom_headers' => ''];
                break;
            case 'feishu':
                $config['feishu'] = ['webhook_url' => $contactValue, 'secret' => ''];
                break;
            case 'dingtalk':
                $config['dingtalk'] = ['webhook_url' => $contactValue, 'secret' => ''];
                break;
            case 'sms':
                $config['sms'] = ['phone' => $contactValue];
                break;
            default:
                $config[$channelCode] = ['value' => $contactValue];
        }
        return $config;
    }

    /** 为管理员 ID=1 补全默认角色 role_id=1（升级修复） */
    private function ensureUser1HasRole1(): void
    {
        $backendUser = ObjectManager::getInstance(BackendUser::class);
        $backendUser->reset()->load(1);
        if (!$backendUser->getId()) {
            return;
        }
        $userRole = ObjectManager::getInstance(UserRole::class);
        $exist = $userRole->reset()
            ->where(UserRole::schema_fields_USER_ID, 1)
            ->find()
            ->fetch();
        if ($exist && $exist->getData(UserRole::schema_fields_ROLE_ID)) {
            return;
        }
        $userRole->clear()
            ->setUserId(1)
            ->setRoleId(1)
            ->save(true);
    }
    
    /**
     * 确保默认管理员用户存在
     */
    private function ensureDefaultAdminUser(): void
    {
        /** @var BackendUser $userModel */
        $userModel = ObjectManager::getInstance(BackendUser::class);
        
        $existingUser = $userModel->reset()
            ->where('username', 'admin')
            ->find()
            ->fetch();
        
        if ($existingUser && $existingUser->getId()) {
            return;
        }
        
        $userModel->reset()
            ->setUsername('admin')
            ->setEmail('admin@example.com')
            ->setPassword('admin')
            ->save();
    }
}
