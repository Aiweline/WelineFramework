<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 助手评分模型
 */
class AiAssistantRating extends \Weline\Framework\Database\Model
{
    // 字段常量
    public const fields_ID = 'id';
    public const fields_ASSISTANT_ID = 'assistant_id';
    public const fields_USER_ID = 'user_id';
    public const fields_RENTAL_ID = 'rental_id';
    public const fields_RATING = 'rating';
    public const fields_COMMENT = 'comment';
    public const fields_ACCURACY_RATING = 'accuracy_rating';
    public const fields_SPEED_RATING = 'speed_rating';
    public const fields_USEFULNESS_RATING = 'usefulness_rating';
    public const fields_STATUS = 'status';
    public const fields_IS_VERIFIED = 'is_verified';
    public const fields_HELPFUL_COUNT = 'helpful_count';
    public const fields_REPORT_COUNT = 'report_count';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';
    
    // 状态常量
    public const STATUS_VISIBLE = 'visible';
    public const STATUS_HIDDEN = 'hidden';
    public const STATUS_REPORTED = 'reported';
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 主表安装在install方法中
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        $this->_unit_primary_keys = [self::fields_ID];
        $this->_index_sort_keys = [
            [self::fields_ASSISTANT_ID],
            [self::fields_USER_ID],
            [self::fields_RATING],
        ];
        
        if (!$setup->tableExist()) {
            $setup->createTable('助手评分表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '主键'
                )
                ->addColumn(
                    self::fields_ASSISTANT_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '助手ID'
                )
                ->addColumn(
                    self::fields_USER_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '评分用户ID'
                )
                ->addColumn(
                    self::fields_RENTAL_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'null',
                    '租赁记录ID'
                )
                ->addColumn(
                    self::fields_RATING,
                    TableInterface::column_type_TINYINT,
                    4,
                    'not null',
                    '评分（1-5星）'
                )
                ->addColumn(
                    self::fields_COMMENT,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '评论内容'
                )
                ->addColumn(
                    self::fields_ACCURACY_RATING,
                    TableInterface::column_type_TINYINT,
                    4,
                    'null',
                    '准确度评分'
                )
                ->addColumn(
                    self::fields_SPEED_RATING,
                    TableInterface::column_type_TINYINT,
                    4,
                    'null',
                    '速度评分'
                )
                ->addColumn(
                    self::fields_USEFULNESS_RATING,
                    TableInterface::column_type_TINYINT,
                    4,
                    'null',
                    '实用性评分'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default "visible"',
                    '状态'
                )
                ->addColumn(
                    self::fields_IS_VERIFIED,
                    TableInterface::column_type_TINYINT . '(1)',
                    0,
                    'default 0',
                    '是否已验证'
                )
                ->addColumn(
                    self::fields_HELPFUL_COUNT,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '有帮助数'
                )
                ->addColumn(
                    self::fields_REPORT_COUNT,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '举报数'
                )
                ->addColumn(
                    self::fields_CREATED_TIME,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_TIME,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp on update current_timestamp',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    'idx_assistant',
                    self::fields_ASSISTANT_ID
                )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    'idx_user',
                    self::fields_USER_ID
                )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    'idx_rating',
                    self::fields_RATING
                )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    'idx_created',
                    self::fields_CREATED_TIME
                )
                ->create();
        }
    }
    
    /**
     * 初始化方法
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
}

