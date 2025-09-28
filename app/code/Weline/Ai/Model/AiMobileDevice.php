<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI移动端设备数据模型
 * 
 * 功能：
 * - 管理移动端设备注册
 * - 推送通知令牌管理
 * - 设备状态跟踪
 * - 移动端API访问控制
 */
class AiMobileDevice extends Model
{
    public const table = 'ai_mobile_device';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_DEVICE_ID = 'device_id';
    public const fields_DEVICE_TYPE = 'device_type';
    public const fields_PUSH_TOKEN = 'push_token';
    public const fields_DEVICE_INFO = 'device_info';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_LAST_ACTIVE = 'last_active';
    public const fields_CREATED_TIME = 'created_time';

    // 设备类型常量
    public const TYPE_IOS = 'ios';
    public const TYPE_ANDROID = 'android';
    public const TYPE_WEB = 'web';
    public const TYPE_DESKTOP = 'desktop';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, 11, 'not null', '用户ID')
                ->addColumn(self::fields_DEVICE_ID, TableInterface::column_type_VARCHAR, 255, 'not null', '设备ID')
                ->addColumn(self::fields_DEVICE_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '设备类型')
                ->addColumn(self::fields_PUSH_TOKEN, TableInterface::column_type_VARCHAR, 500, 'null', '推送令牌')
                ->addColumn(self::fields_DEVICE_INFO, TableInterface::column_type_TEXT, null, 'null', '设备信息JSON')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_INTEGER, 1, 'not null default 1', '是否激活')
                ->addColumn(self::fields_LAST_ACTIVE, TableInterface::column_type_INTEGER, 11, 'null', '最后活跃时间')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_device_id', self::fields_DEVICE_ID, '设备ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_device_type', self::fields_DEVICE_TYPE, '设备类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '激活状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_last_active', self::fields_LAST_ACTIVE, '最后活跃时间索引')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_user_device', [self::fields_USER_ID, self::fields_DEVICE_ID], '用户设备唯一索引')
                ->create();
        }
    }

    /**
     * 获取用户ID
     * 
     * @return int
     */
    public function getUserId(): int
    {
        return (int)$this->getData(self::fields_USER_ID);
    }

    /**
     * 获取设备ID
     * 
     * @return string
     */
    public function getDeviceId(): string
    {
        return $this->getData(self::fields_DEVICE_ID) ?? '';
    }

    /**
     * 获取设备类型
     * 
     * @return string
     */
    public function getDeviceType(): string
    {
        return $this->getData(self::fields_DEVICE_TYPE) ?? '';
    }

    /**
     * 获取推送令牌
     * 
     * @return string
     */
    public function getPushToken(): string
    {
        return $this->getData(self::fields_PUSH_TOKEN) ?? '';
    }

    /**
     * 设置推送令牌
     * 
     * @param string $token
     * @return $this
     */
    public function setPushToken(string $token): self
    {
        $this->setData(self::fields_PUSH_TOKEN, $token);
        return $this;
    }

    /**
     * 获取设备信息
     * 
     * @return array
     */
    public function getDeviceInfo(): array
    {
        $info = $this->getData(self::fields_DEVICE_INFO);
        return $info ? json_decode($info, true) : [];
    }

    /**
     * 设置设备信息
     * 
     * @param array $info
     * @return $this
     */
    public function setDeviceInfo(array $info): self
    {
        $this->setData(self::fields_DEVICE_INFO, json_encode($info));
        return $this;
    }

    /**
     * 检查是否激活
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 获取最后活跃时间
     * 
     * @return int
     */
    public function getLastActive(): int
    {
        return (int)$this->getData(self::fields_LAST_ACTIVE);
    }

    /**
     * 更新最后活跃时间
     * 
     * @return $this
     */
    public function updateLastActive(): self
    {
        $this->setData(self::fields_LAST_ACTIVE, time());
        return $this;
    }

    /**
     * 获取设备类型显示名称
     * 
     * @return string
     */
    public function getDeviceTypeDisplayName(): string
    {
        $typeNames = [
            self::TYPE_IOS => 'iOS',
            self::TYPE_ANDROID => 'Android',
            self::TYPE_WEB => 'Web',
            self::TYPE_DESKTOP => 'Desktop'
        ];

        return $typeNames[$this->getDeviceType()] ?? $this->getDeviceType();
    }

    /**
     * 检查是否为移动设备
     * 
     * @return bool
     */
    public function isMobileDevice(): bool
    {
        return in_array($this->getDeviceType(), [self::TYPE_IOS, self::TYPE_ANDROID]);
    }

    /**
     * 检查是否为iOS设备
     * 
     * @return bool
     */
    public function isIOSDevice(): bool
    {
        return $this->getDeviceType() === self::TYPE_IOS;
    }

    /**
     * 检查是否为Android设备
     * 
     * @return bool
     */
    public function isAndroidDevice(): bool
    {
        return $this->getDeviceType() === self::TYPE_ANDROID;
    }

    /**
     * 检查是否为Web设备
     * 
     * @return bool
     */
    public function isWebDevice(): bool
    {
        return $this->getDeviceType() === self::TYPE_WEB;
    }

    /**
     * 检查是否为桌面设备
     * 
     * @return bool
     */
    public function isDesktopDevice(): bool
    {
        return $this->getDeviceType() === self::TYPE_DESKTOP;
    }

    /**
     * 激活设备
     * 
     * @return $this
     */
    public function activate(): self
    {
        $this->setData(self::fields_IS_ACTIVE, 1);
        $this->updateLastActive();
        return $this;
    }

    /**
     * 停用设备
     * 
     * @return $this
     */
    public function deactivate(): self
    {
        $this->setData(self::fields_IS_ACTIVE, 0);
        return $this;
    }

    /**
     * 获取设备状态
     * 
     * @return string
     */
    public function getStatus(): string
    {
        if (!$this->isActive()) {
            return 'inactive';
        }

        $lastActive = $this->getLastActive();
        $now = time();
        $inactiveTime = $now - $lastActive;

        if ($inactiveTime < 300) { // 5分钟内
            return 'online';
        } elseif ($inactiveTime < 3600) { // 1小时内
            return 'away';
        } else {
            return 'offline';
        }
    }

    /**
     * 获取设备状态显示名称
     * 
     * @return string
     */
    public function getStatusDisplayName(): string
    {
        $statusNames = [
            'online' => '在线',
            'away' => '离开',
            'offline' => '离线',
            'inactive' => '未激活'
        ];

        return $statusNames[$this->getStatus()] ?? $this->getStatus();
    }

    /**
     * 保存前的数据处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        $currentTime = time();
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, $currentTime);
        }
        
        return $this;
    }
}
