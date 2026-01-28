<?php

declare(strict_types=1);

namespace WeShop\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 搜索历史模型
 * 用于记录用户搜索历史和统计热门搜索词
 */
class SearchHistory extends Model
{
    public const table = 'weshop_search_history';
    public const primary_key = 'history_id';
    public string $indexer = 'search_history_indexer';
    
    public const fields_ID = 'history_id';
    public const fields_KEYWORD = 'keyword';
    public const fields_SEARCH_COUNT = 'search_count';
    public const fields_RESULT_COUNT = 'result_count';
    public const fields_USER_ID = 'user_id';
    public const fields_IP_ADDRESS = 'ip_address';
    public const fields_USER_AGENT = 'user_agent';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['history_id'];
    public array $_index_sort_keys = ['keyword', 'search_count', 'created_at'];
    
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
        // 如果表不存在，执行安装
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('搜索历史表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '搜索历史ID')
                ->addColumn(self::fields_KEYWORD, TableInterface::column_type_VARCHAR, 255, 'not null', '搜索关键词')
                ->addColumn(self::fields_SEARCH_COUNT, TableInterface::column_type_INTEGER, 0, 'not null default 1', '搜索次数')
                ->addColumn(self::fields_RESULT_COUNT, TableInterface::column_type_INTEGER, 0, 'not null default 0', '结果数量')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, 0, 'default 0', '用户ID')
                ->addColumn(self::fields_IP_ADDRESS, TableInterface::column_type_VARCHAR, 45, '', 'IP地址')
                ->addColumn(self::fields_USER_AGENT, TableInterface::column_type_VARCHAR, 500, '', '用户代理')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_keyword', self::fields_KEYWORD, '关键词索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_search_count', self::fields_SEARCH_COUNT, '搜索次数索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_created_at', self::fields_CREATED_AT, '创建时间索引')
                ->create();
        }
    }
    
    /**
     * 记录搜索历史
     * 
     * @param string $keyword 搜索关键词
     * @param int $resultCount 搜索结果数量
     * @param int|null $userId 用户ID
     * @return bool
     */
    public function recordSearch(string $keyword, int $resultCount = 0, ?int $userId = null): bool
    {
        if (empty(trim($keyword))) {
            return false;
        }
        
        $keyword = trim($keyword);
        $this->clear();
        
        // 查找是否已存在该关键词的记录
        $existing = $this->where(self::fields_KEYWORD, $keyword)->find()->fetch();
        
        if ($existing) {
            // 更新搜索次数和结果数量
            $this->clear()
                ->setId($existing[self::fields_ID])
                ->setData(self::fields_SEARCH_COUNT, (int)$existing[self::fields_SEARCH_COUNT] + 1)
                ->setData(self::fields_RESULT_COUNT, $resultCount)
                ->setData(self::fields_USER_ID, $userId ?? 0)
                ->setData(self::fields_IP_ADDRESS, $_SERVER['REMOTE_ADDR'] ?? '')
                ->setData(self::fields_USER_AGENT, $_SERVER['HTTP_USER_AGENT'] ?? '')
                ->save();
        } else {
            // 创建新记录
            $this->clear()
                ->setData(self::fields_KEYWORD, $keyword)
                ->setData(self::fields_SEARCH_COUNT, 1)
                ->setData(self::fields_RESULT_COUNT, $resultCount)
                ->setData(self::fields_USER_ID, $userId ?? 0)
                ->setData(self::fields_IP_ADDRESS, $_SERVER['REMOTE_ADDR'] ?? '')
                ->setData(self::fields_USER_AGENT, $_SERVER['HTTP_USER_AGENT'] ?? '')
                ->save();
        }
        
        return true;
    }
    
    /**
     * 获取热门搜索词
     * 
     * @param int $limit 返回数量
     * @param int $days 最近天数
     * @return array
     */
    public function getPopularKeywords(int $limit = 10, int $days = 30): array
    {
        $this->clear();
        
        // 获取最近N天的热门搜索词
        $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $this->where(self::fields_CREATED_AT, ['>=', $dateThreshold])
            ->order(self::fields_SEARCH_COUNT, 'DESC')
            ->limit($limit);
        
        $results = $this->select()->fetchArray();
        
        return array_map(function ($item) {
            return [
                'keyword' => $item[self::fields_KEYWORD],
                'count' => (int)$item[self::fields_SEARCH_COUNT],
            ];
        }, $results);
    }
}
