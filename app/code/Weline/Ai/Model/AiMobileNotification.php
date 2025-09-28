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
 * AI移动端通知数据模型
 * 
 * 功能：
 * - 管理移动端推送通知
 * - 通知状态跟踪
 * - 通知历史记录
 * - 通知模板管理
 */
class AiMobileNotification extends Model
{
    public const table = 'ai_mobile_notification';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_DEVICE_ID = 'device_id';
    public const fields_NOTIFICATION_TYPE = 'notification_type';
    public const fields_TITLE = 'title';
    public const fields_CONTENT = 'content';
    public const fields_DATA = 'data';
    public const fields_STATUS = 'status';
    public const fields_SENT_TIME = 'sent_time';
    public const fields_CREATED_TIME = 'created_time';

    // 通知类型常量
    public const TYPE_AI_RESPONSE = 'ai_response';
    public const TYPE_SYSTEM_ALERT = 'system_alert';
    public const TYPE_BILLING = 'billing';
    public const TYPE_SECURITY = 'security';
    public const TYPE_MARKETING = 'marketing';

    // 通知状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_READ = 'read';

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
                ->addColumn(self::fields_DEVICE_ID, TableInterface::column_type_VARCHAR, 255, 'null', '设备ID')
                ->addColumn(self::fields_NOTIFICATION_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '通知类型')
                ->addColumn(self::fields_TITLE, TableInterface::column_type_VARCHAR, 255, 'not null', '通知标题')
                ->addColumn(self::fields_CONTENT, TableInterface::column_type_TEXT, null, 'not null', '通知内容')
                ->addColumn(self::fields_DATA, TableInterface::column_type_TEXT, null, 'null', '通知数据JSON')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 50, 'not null default "pending"', '通知状态')
                ->addColumn(self::fields_SENT_TIME, TableInterface::column_type_INTEGER, 11, 'null', '发送时间')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_device_id', self::fields_DEVICE_ID, '设备ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_notification_type', self::fields_NOTIFICATION_TYPE, '通知类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_sent_time', self::fields_SENT_TIME, '发送时间索引')
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
     * 获取通知类型
     * 
     * @return string
     */
    public function getNotificationType(): string
    {
        return $this->getData(self::fields_NOTIFICATION_TYPE) ?? '';
    }

    /**
     * 获取通知标题
     * 
     * @return string
     */
    public function getTitle(): string
    {
        return $this->getData(self::fields_TITLE) ?? '';
    }

    /**
     * 获取通知内容
     * 
     * @return string
     */
    public function getContent(): string
    {
        return $this->getData(self::fields_CONTENT) ?? '';
    }

    /**
     * 获取通知数据
     * 
     * @return array
     */
    public function getNotificationData(): array
    {
        $data = $this->getData(self::fields_DATA);
        return $data ? json_decode($data, true) : [];
    }

    /**
     * 设置通知数据
     * 
     * @param array $data
     * @return $this
     */
    public function setNotificationData(array $data): self
    {
        $this->setData(self::fields_DATA, json_encode($data));
        return $this;
    }

    /**
     * 获取通知状态
     * 
     * @return string
     */
    public function getStatus(): string
    {
        return $this->getData(self::fields_STATUS) ?? self::STATUS_PENDING;
    }

    /**
     * 设置通知状态
     * 
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self
    {
        $this->setData(self::fields_STATUS, $status);
        return $this;
    }

    /**
     * 获取发送时间
     * 
     * @return int
     */
    public function getSentTime(): int
    {
        return (int)$this->getData(self::fields_SENT_TIME);
    }

    /**
     * 设置发送时间
     * 
     * @param int $time
     * @return $this
     */
    public function setSentTime(int $time): self
    {
        $this->setData(self::fields_SENT_TIME, $time);
        return $this;
    }

    /**
     * 获取通知类型显示名称
     * 
     * @return string
     */
    public function getNotificationTypeDisplayName(): string
    {
        $typeNames = [
            self::TYPE_AI_RESPONSE => 'AI响应',
            self::TYPE_SYSTEM_ALERT => '系统提醒',
            self::TYPE_BILLING => '计费通知',
            self::TYPE_SECURITY => '安全通知',
            self::TYPE_MARKETING => '营销通知'
        ];

        return $typeNames[$this->getNotificationType()] ?? $this->getNotificationType();
    }

    /**
     * 获取状态显示名称
     * 
     * @return string
     */
    public function getStatusDisplayName(): string
    {
        $statusNames = [
            self::STATUS_PENDING => '待发送',
            self::STATUS_SENT => '已发送',
            self::STATUS_DELIVERED => '已送达',
            self::STATUS_FAILED => '发送失败',
            self::STATUS_READ => '已读'
        ];

        return $statusNames[$this->getStatus()] ?? $this->getStatus();
    }

    /**
     * 检查是否为待发送状态
     * 
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->getStatus() === self::STATUS_PENDING;
    }

    /**
     * 检查是否已发送
     * 
     * @return bool
     */
    public function isSent(): bool
    {
        return in_array($this->getStatus(), [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_READ]);
    }

    /**
     * 检查是否发送失败
     * 
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->getStatus() === self::STATUS_FAILED;
    }

    /**
     * 检查是否已读
     * 
     * @return bool
     */
    public function isRead(): bool
    {
        return $this->getStatus() === self::STATUS_READ;
    }

    /**
     * 标记为已发送
     * 
     * @return $this
     */
    public function markAsSent(): self
    {
        $this->setStatus(self::STATUS_SENT)
             ->setSentTime(time());
        return $this;
    }

    /**
     * 标记为已送达
     * 
     * @return $this
     */
    public function markAsDelivered(): self
    {
        $this->setStatus(self::STATUS_DELIVERED);
        return $this;
    }

    /**
     * 标记为已读
     * 
     * @return $this
     */
    public function markAsRead(): self
    {
        $this->setStatus(self::STATUS_READ);
        return $this;
    }

    /**
     * 标记为发送失败
     * 
     * @return $this
     */
    public function markAsFailed(): self
    {
        $this->setStatus(self::STATUS_FAILED);
        return $this;
    }

    /**
     * 获取通知优先级
     * 
     * @return int
     */
    public function getPriority(): int
    {
        $priorities = [
            self::TYPE_SECURITY => 5,
            self::TYPE_SYSTEM_ALERT => 4,
            self::TYPE_BILLING => 3,
            self::TYPE_AI_RESPONSE => 2,
            self::TYPE_MARKETING => 1
        ];

        return $priorities[$this->getNotificationType()] ?? 1;
    }

    /**
     * 检查是否为高优先级通知
     * 
     * @return bool
     */
    public function isHighPriority(): bool
    {
        return $this->getPriority() >= 4;
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
