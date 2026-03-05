<?php

declare(strict_types=1);

namespace WeShop\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 搜索历史模型
 * 用于记录用户搜索历史和统计热门搜索词
 */
#[Table(comment: '搜索历史表')]
#[Index(name: 'idx_keyword', columns: ['keyword'], type: 'KEY', comment: '关键词索引')]
#[Index(name: 'idx_search_count', columns: ['search_count'], type: 'KEY', comment: '搜索次数索引')]
#[Index(name: 'idx_user_id', columns: ['user_id'], type: 'KEY', comment: '用户ID索引')]
#[Index(name: 'idx_created_at', columns: ['created_at'], type: 'KEY', comment: '创建时间索引')]
class SearchHistory extends Model
{
    public const schema_table = 'weshop_search_history';
    public const schema_primary_key = 'history_id';
    public string $indexer = 'search_history_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '历史ID')]
    public const schema_fields_ID = 'history_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '搜索关键词')]
    public const schema_fields_KEYWORD = 'keyword';
    #[Col(type: 'int', nullable: false, default: 1, comment: '搜索次数')]
    public const schema_fields_SEARCH_COUNT = 'search_count';
    #[Col(type: 'int', nullable: false, default: 0, comment: '结果数量')]
    public const schema_fields_RESULT_COUNT = 'result_count';
    #[Col(type: 'int', nullable: true, default: 0, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 45, nullable: true, comment: 'IP地址')]
    public const schema_fields_IP_ADDRESS = 'ip_address';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: 'User-Agent')]
    public const schema_fields_USER_AGENT = 'user_agent';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['history_id'];
    public array $_index_sort_keys = ['keyword', 'search_count', 'created_at'];


    public function recordSearch(string $keyword, int $resultCount = 0, ?int $userId = null): bool
    {
        if (empty(trim($keyword))) return false;
        $keyword = trim($keyword);
        $this->clear();
        $existing = $this->where(self::schema_fields_KEYWORD, $keyword)->find()->fetch();
        if ($existing) {
            $this->clear()
                ->setId($existing[self::schema_fields_ID])
                ->setData(self::schema_fields_SEARCH_COUNT, (int)$existing[self::schema_fields_SEARCH_COUNT] + 1)
                ->setData(self::schema_fields_RESULT_COUNT, $resultCount)
                ->setData(self::schema_fields_USER_ID, $userId ?? 0)
                ->setData(self::schema_fields_IP_ADDRESS, $_SERVER['REMOTE_ADDR'] ?? '')
                ->setData(self::schema_fields_USER_AGENT, $_SERVER['HTTP_USER_AGENT'] ?? '')
                ->save();
        } else {
            $this->clear()
                ->setData(self::schema_fields_KEYWORD, $keyword)
                ->setData(self::schema_fields_SEARCH_COUNT, 1)
                ->setData(self::schema_fields_RESULT_COUNT, $resultCount)
                ->setData(self::schema_fields_USER_ID, $userId ?? 0)
                ->setData(self::schema_fields_IP_ADDRESS, $_SERVER['REMOTE_ADDR'] ?? '')
                ->setData(self::schema_fields_USER_AGENT, $_SERVER['HTTP_USER_AGENT'] ?? '')
                ->save();
        }
        return true;
    }

    public function getPopularKeywords(int $limit = 10, int $days = 30): array
    {
        $this->clear();
        $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $this->where(self::schema_fields_CREATED_AT, ['>=', $dateThreshold])
            ->order(self::schema_fields_SEARCH_COUNT, 'DESC')
            ->limit($limit);
        $results = $this->select()->fetchArray();
        return array_map(function ($item) {
            return ['keyword' => $item[self::schema_fields_KEYWORD], 'count' => (int)$item[self::schema_fields_SEARCH_COUNT]];
        }, $results);
    }
}

