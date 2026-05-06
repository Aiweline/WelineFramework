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
        $existingId = $this->resolveExistingId($existing);

        if ($existingId > 0) {
            $this->clear()
                ->setId($existingId)
                ->setData(self::schema_fields_KEYWORD, $keyword)
                ->setData(self::schema_fields_SEARCH_COUNT, $this->resolveExistingSearchCount($existing) + 1)
                ->setData(self::schema_fields_RESULT_COUNT, $resultCount)
                ->setData(self::schema_fields_USER_ID, $userId ?? 0)
                ->setData(self::schema_fields_IP_ADDRESS, \Weline\Framework\Env\WelineEnv::server('REMOTE_ADDR', ''))
                ->setData(self::schema_fields_USER_AGENT, \Weline\Framework\Env\WelineEnv::server('HTTP_USER_AGENT', ''))
                ->save();
        } else {
            $this->clear()
                ->setData(self::schema_fields_KEYWORD, $keyword)
                ->setData(self::schema_fields_SEARCH_COUNT, 1)
                ->setData(self::schema_fields_RESULT_COUNT, $resultCount)
                ->setData(self::schema_fields_USER_ID, $userId ?? 0)
                ->setData(self::schema_fields_IP_ADDRESS, \Weline\Framework\Env\WelineEnv::server('REMOTE_ADDR', ''))
                ->setData(self::schema_fields_USER_AGENT, \Weline\Framework\Env\WelineEnv::server('HTTP_USER_AGENT', ''))
                ->save();
        }
        return true;
    }

    private function resolveExistingId(mixed $existing): int
    {
        if (is_object($existing) && method_exists($existing, 'getId')) {
            return (int) $existing->getId();
        }

        if (is_array($existing)) {
            return (int) ($existing[self::schema_fields_ID] ?? 0);
        }

        return 0;
    }

    private function resolveExistingSearchCount(mixed $existing): int
    {
        if (is_object($existing) && method_exists($existing, 'getData')) {
            return (int) $existing->getData(self::schema_fields_SEARCH_COUNT);
        }

        if (is_array($existing)) {
            return (int) ($existing[self::schema_fields_SEARCH_COUNT] ?? 0);
        }

        return 0;
    }

    public function getPopularKeywords(int $limit = 10, int $days = 30): array
    {
        $this->clear();
        $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $this->where(self::schema_fields_CREATED_AT, $dateThreshold, '>=')
            ->order(self::schema_fields_SEARCH_COUNT, 'DESC')
            ->limit($limit);
        $results = $this->select()->fetchArray();

        return $this->normalizePopularKeywords($results, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array{keyword:string,count:int}>
     */
    private function normalizePopularKeywords(array $results, int $limit): array
    {
        $grouped = [];

        foreach ($results as $item) {
            $keyword = trim((string) ($item[self::schema_fields_KEYWORD] ?? ''));
            if ($keyword === '') {
                continue;
            }

            $count = (int) ($item[self::schema_fields_SEARCH_COUNT] ?? 0);
            if (!isset($grouped[$keyword])) {
                $grouped[$keyword] = [
                    'keyword' => $keyword,
                    'count' => 0,
                ];
            }

            $grouped[$keyword]['count'] += $count;
        }

        $keywords = array_values($grouped);
        usort($keywords, static function (array $left, array $right): int {
            return $right['count'] <=> $left['count'];
        });

        return array_slice($keywords, 0, $limit);
    }
}

