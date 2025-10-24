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
 * 助手会话记录模型
 * 
 * 功能：
 * - 记录助手对话历史
 * - 支持会话上下文管理
 * - 提供对话分析数据
 */
class AiAssistantConversation extends \Weline\Framework\Database\Model
{
    public const table = 'ai_assistant_conversation';
    public const fields_ID = 'id';
    public const fields_ASSISTANT_ID = 'assistant_id';
    public const fields_USER_ID = 'user_id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_SESSION_ID = 'session_id';
    public const fields_MESSAGE_ROLE = 'message_role'; // user, assistant, system
    public const fields_MESSAGE_CONTENT = 'message_content';
    public const fields_MESSAGE_TOKENS = 'message_tokens';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_COST = 'cost';
    public const fields_RESPONSE_TIME = 'response_time';
    public const fields_CONTEXT_TOKENS = 'context_tokens';
    public const fields_IS_BOOKMARKED = 'is_bookmarked';
    public const fields_RATING = 'rating';
    public const fields_CREATED_TIME = 'created_time';

    /**
     * 消息角色常量
     */
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

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
                ->addColumn(self::fields_ASSISTANT_ID, TableInterface::column_type_INTEGER, null, 'not null', '助手ID')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, null, 'null', '用户ID')
                ->addColumn(self::fields_TENANT_ID, TableInterface::column_type_INTEGER, null, 'null', '租户ID')
                ->addColumn(self::fields_SESSION_ID, TableInterface::column_type_VARCHAR, 100, 'not null', '会话ID')
                ->addColumn(self::fields_MESSAGE_ROLE, TableInterface::column_type_VARCHAR, 50, 'not null', '消息角色')
                ->addColumn(self::fields_MESSAGE_CONTENT, TableInterface::column_type_TEXT, null, 'not null', '消息内容')
                ->addColumn(self::fields_MESSAGE_TOKENS, TableInterface::column_type_INTEGER, null, 'default 0', '消息Token数')
                ->addColumn(self::fields_MODEL_CODE, TableInterface::column_type_VARCHAR, 255, 'null', '模型代码')
                ->addColumn(self::fields_COST, TableInterface::column_type_DECIMAL, '10,6', 'default 0.000000', '成本')
                ->addColumn(self::fields_RESPONSE_TIME, TableInterface::column_type_DECIMAL, '10,3', 'default 0.000', '响应时间（秒）')
                ->addColumn(self::fields_CONTEXT_TOKENS, TableInterface::column_type_INTEGER, null, 'default 0', '上下文Token数')
                ->addColumn(self::fields_IS_BOOKMARKED, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否收藏')
                ->addColumn(self::fields_RATING, TableInterface::column_type_SMALLINT, null, 'null', '评分（1-5）')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_assistant_id', self::fields_ASSISTANT_ID, '助手索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID, '用户索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_session_id', self::fields_SESSION_ID, '会话索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_tenant_id', self::fields_TENANT_ID, '租户索引')
                ->create();
        }
    }

    /**
     * 检查是否用户消息
     * 
     * @return bool
     */
    public function isUserMessage(): bool
    {
        return $this->getData(self::fields_MESSAGE_ROLE) === self::ROLE_USER;
    }

    /**
     * 检查是否助手消息
     * 
     * @return bool
     */
    public function isAssistantMessage(): bool
    {
        return $this->getData(self::fields_MESSAGE_ROLE) === self::ROLE_ASSISTANT;
    }

    /**
     * 检查是否系统消息
     * 
     * @return bool
     */
    public function isSystemMessage(): bool
    {
        return $this->getData(self::fields_MESSAGE_ROLE) === self::ROLE_SYSTEM;
    }

    /**
     * 获取格式化成本
     * 
     * @return string
     */
    public function getFormattedCost(): string
    {
        $cost = (float)$this->getData(self::fields_COST);
        return '¥' . number_format($cost, 6);
    }

    /**
     * 切换收藏状态
     * 
     * @return $this
     */
    public function toggleBookmark(): self
    {
        $current = (bool)$this->getData(self::fields_IS_BOOKMARKED);
        $this->setData(self::fields_IS_BOOKMARKED, !$current);
        return $this;
    }

    /**
     * 设置评分
     * 
     * @param int $rating
     * @return $this
     */
    public function setRating(int $rating): self
    {
        if ($rating < 1) {
            $rating = 1;
        } elseif ($rating > 5) {
            $rating = 5;
        }
        
        $this->setData(self::fields_RATING, $rating);
        return $this;
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

