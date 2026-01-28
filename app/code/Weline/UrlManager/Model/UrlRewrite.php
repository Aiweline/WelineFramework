<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\UrlManager\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class UrlRewrite extends \Weline\Framework\Database\Model
{
    public const fields_ID           = 'rewrite_id';
    public const fields_URL_ID       = 'url_id';
    public const fields_URL_IDENTIFY = 'url_identify';
    public const fields_PATH         = 'path';
    public const fields_REWRITE      = 'rewrite';
    public const fields_WEBSITE_ID   = 'website_id';

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
        if (!$setup->tableExist()) {
            return;
        }
        
        // 添加 website_id 字段（如果不存在）
        if (!$setup->hasField(self::fields_WEBSITE_ID)) {
            try {
                $setup->alterTable()->addColumn(
                    self::fields_WEBSITE_ID,
                    self::fields_URL_IDENTIFY,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null default 0',
                    '网站ID（0表示默认/全局）'
                )->alter();
            } catch (\Exception $e) {
                // 如果 ALTER 失败，尝试直接执行 SQL
                if (!$setup->hasField(self::fields_WEBSITE_ID)) {
                    $tableName = $setup->getTable();
                    try {
                        $setup->query("ALTER TABLE {$tableName} ADD COLUMN `" . self::fields_WEBSITE_ID . "` INT(11) NOT NULL DEFAULT 0 COMMENT '网站ID（0表示默认/全局）' AFTER `" . self::fields_URL_IDENTIFY . "`");
                    } catch (\Exception $e2) {
                        // 静默失败，可能字段已存在
                    }
                }
            }
            
            // 添加 website_id 普通索引
            try {
                $tableName = $setup->getTable();
                $setup->query("CREATE INDEX idx_website_id ON {$tableName} (" . self::fields_WEBSITE_ID . ")");
            } catch (\Exception $e) {
                // 索引可能已存在
            }
        }
        
        // 将 rewrite 字段从 TEXT 改为 VARCHAR(255)，以支持唯一索引
        // 注意：这可能会截断超过255字符的数据，生产环境需要先检查
        try {
            $tableName = $setup->getTable();
            $setup->query("ALTER TABLE {$tableName} MODIFY COLUMN `" . self::fields_REWRITE . "` VARCHAR(255) NOT NULL COMMENT 'URL重写路径'");
        } catch (\Exception $e) {
            // 可能已经是 VARCHAR 或其他原因失败
        }
        
        // 删除旧的全局唯一索引 URL_IDENTIFY_UNIQUE（如果存在）
        try {
            $tableName = $setup->getTable();
            $setup->query("DROP INDEX URL_IDENTIFY_UNIQUE ON {$tableName}");
        } catch (\Exception $e) {
            // 索引可能不存在
        }
        
        // 添加新的组合唯一索引：(website_id, url_identify)
        try {
            $tableName = $setup->getTable();
            $setup->query("CREATE UNIQUE INDEX UNQ_WEBSITE_URL_IDENTIFY ON {$tableName} (" . self::fields_WEBSITE_ID . ", " . self::fields_URL_IDENTIFY . ")");
        } catch (\Exception $e) {
            // 索引可能已存在
        }
        
        // 添加新的组合唯一索引：(website_id, rewrite)
        try {
            $tableName = $setup->getTable();
            $setup->query("CREATE UNIQUE INDEX UNQ_WEBSITE_REWRITE ON {$tableName} (" . self::fields_WEBSITE_ID . ", " . self::fields_REWRITE . ")");
        } catch (\Exception $e) {
            // 索引可能已存在
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '重写ID')
                  ->addColumn(self::fields_URL_ID, TableInterface::column_type_VARCHAR, 255, '', 'URL ID')
                  ->addColumn(self::fields_URL_IDENTIFY, TableInterface::column_type_VARCHAR, 255, '', 'URL 指纹')
                  ->addColumn(self::fields_WEBSITE_ID, TableInterface::column_type_INTEGER, 11, 'not null default 0', '网站ID（0表示默认/全局）')
                  ->addColumn(self::fields_PATH, TableInterface::column_type_TEXT, null, 'not null', 'URL路径')
                  ->addColumn(self::fields_REWRITE, TableInterface::column_type_VARCHAR, 255, 'not null', 'URL重写路径')
                  ->addIndex(TableInterface::index_type_KEY, 'idx_website_id', self::fields_WEBSITE_ID, '网站ID索引')
                  ->addIndex(TableInterface::index_type_UNIQUE, 'UNQ_WEBSITE_URL_IDENTIFY', [self::fields_WEBSITE_ID, self::fields_URL_IDENTIFY], '网站+URL指纹唯一')
                  ->addIndex(TableInterface::index_type_UNIQUE, 'UNQ_WEBSITE_REWRITE', [self::fields_WEBSITE_ID, self::fields_REWRITE], '网站+重写路径唯一')
                  ->create();
        }
    }
    
    /**
     * 获取当前请求的网站ID
     * 
     * @return int 网站ID，默认为0
     */
    public static function getCurrentWebsiteId(): int
    {
        $websiteId = $_SERVER['WELINE_WEBSITE_ID'] ?? '';
        if ($websiteId === '' || $websiteId === null) {
            return 0;
        }
        return (int)$websiteId;
    }
}
