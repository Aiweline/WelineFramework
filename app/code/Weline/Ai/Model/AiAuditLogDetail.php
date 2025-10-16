<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/11
 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 审计日志详情模型
 * 
 * 功能：
 * - 记录操作详细信息
 * - 支持数据前后对比
 * - 提供审计追踪
 */
class AiAuditLogDetail extends \Weline\Framework\Database\Model
{
    public const table = 'ai_audit_log_detail';
    public const fields_ID = 'id';
    public const fields_USAGE_LOG_ID = 'usage_log_id';
    public const fields_FIELD_NAME = 'field_name';
    public const fields_OLD_VALUE = 'old_value';
    public const fields_NEW_VALUE = 'new_value';
    public const fields_CHANGE_TYPE = 'change_type'; // create, update, delete
    public const fields_IP_ADDRESS = 'ip_address';
    public const fields_USER_AGENT = 'user_agent';
    public const fields_REQUEST_ID = 'request_id';
    public const fields_SESSION_ID = 'session_id';
    public const fields_CREATED_TIME = 'created_time';

    /**
     * 变更类型常量
     */
    public const CHANGE_TYPE_CREATE = 'create';
    public const CHANGE_TYPE_UPDATE = 'update';
    public const CHANGE_TYPE_DELETE = 'delete';
    public const CHANGE_TYPE_READ = 'read';

    /**
     * 设置模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: 实现升级逻辑
    }

    /**
     * 安装数据表
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主键ID')
                ->addColumn(self::fields_USAGE_LOG_ID, TableInterface::column_type_INTEGER, null, 'not null', '使用日志ID')
                ->addColumn(self::fields_FIELD_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '字段名称')
                ->addColumn(self::fields_OLD_VALUE, TableInterface::column_type_TEXT, null, 'null', '原值')
                ->addColumn(self::fields_NEW_VALUE, TableInterface::column_type_TEXT, null, 'null', '新值')
                ->addColumn(self::fields_CHANGE_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '变更类型')
                ->addColumn(self::fields_IP_ADDRESS, TableInterface::column_type_VARCHAR, 45, 'null', 'IP地址')
                ->addColumn(self::fields_USER_AGENT, TableInterface::column_type_TEXT, null, 'null', '用户代理')
                ->addColumn(self::fields_REQUEST_ID, TableInterface::column_type_VARCHAR, 100, 'null', '请求ID')
                ->addColumn(self::fields_SESSION_ID, TableInterface::column_type_VARCHAR, 100, 'null', '会话ID')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_usage_log_id', self::fields_USAGE_LOG_ID, '使用日志索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_request_id', self::fields_REQUEST_ID, '请求ID索引')
                ->create();
        }
    }

    /**
     * 获取变更摘要
     * 
     * @return string
     */
    public function getChangeSummary(): string
    {
        $fieldName = $this->getData(self::fields_FIELD_NAME);
        $changeType = $this->getData(self::fields_CHANGE_TYPE);
        $oldValue = $this->getData(self::fields_OLD_VALUE);
        $newValue = $this->getData(self::fields_NEW_VALUE);

        switch ($changeType) {
            case self::CHANGE_TYPE_CREATE:
                return "{$fieldName}: 创建 = {$newValue}";
            case self::CHANGE_TYPE_UPDATE:
                return "{$fieldName}: {$oldValue} → {$newValue}";
            case self::CHANGE_TYPE_DELETE:
                return "{$fieldName}: 删除 = {$oldValue}";
            case self::CHANGE_TYPE_READ:
                return "{$fieldName}: 读取";
            default:
                return "{$fieldName}: 未知操作";
        }
    }

    /**
     * 保存前处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, time());
        }
        
        return $this;
    }
}

