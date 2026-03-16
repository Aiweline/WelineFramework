<?php
declare(strict_types=1);

namespace Weline\Bot\Skill;

use Weline\Bot\Interface\SkillInterface;
use Weline\Bot\Service\SkillContext;
use Weline\Bot\Service\SkillResult;
use Weline\Framework\Database\Connection\AdapterFactory;

/**
 * 数据库查询技能
 *
 * 安全地执行数据库查询（仅支持 SELECT）
 */
class DatabaseSkill implements SkillInterface
{
    public function __construct() {}

    public function getCode(): string
    {
        return 'database.query';
    }

    public function getName(): string
    {
        return __('数据库查询');
    }

    public function getDescription(): string
    {
        return __('执行数据库查询（仅限 SELECT）');
    }

    public function getCategory(): string
    {
        return 'database';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => __('SQL 查询语句（仅支持 SELECT）'),
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 100,
                    'description' => __('返回行数限制'),
                ],
                'offset' => [
                    'type' => 'integer',
                    'default' => 0,
                    'description' => __('偏移量'),
                ],
            ],
            'required' => ['sql'],
        ];
    }

    public function getPermissionRequired(): array
    {
        return ['db.read'];
    }

    public function execute(array $params, SkillContext $context): SkillResult
    {
        $sql = $params['sql'] ?? '';
        $limit = min($params['limit'] ?? 100, 1000); // 最大 1000 条
        $offset = max($params['offset'] ?? 0, 0);

        if (empty($sql)) {
            return SkillResult::error('SQL is required');
        }

        // 安全检查：只允许 SELECT
        $sql = trim($sql);
        if (!preg_match('/^SELECT\s/i', $sql)) {
            return SkillResult::error('Only SELECT statements are allowed');
        }

        // 检查危险操作
        $dangerousPatterns = [
            '/\bINSERT\b/i',
            '/\bUPDATE\b/i',
            '/\bDELETE\b/i',
            '/\bDROP\b/i',
            '/\bTRUNCATE\b/i',
            '/\bALTER\b/i',
            '/\bCREATE\b/i',
            '/\bGRANT\b/i',
            '/\bREVOKE\b/i',
            '/\bEXEC\b/i',
            '/\bEXECUTE\b/i',
            '/--/',
            '/\/\*/',
            '/;\s*\w/', // 分号后跟其他语句
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return SkillResult::error('SQL contains forbidden operations');
            }
        }

        try {
            // 添加 LIMIT（如果没有）
            if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
                $sql .= " LIMIT {$limit}";
            }
            if ($offset > 0 && !preg_match('/\bOFFSET\s+\d+/i', $sql)) {
                $sql .= " OFFSET {$offset}";
            }

            // 使用框架的数据库连接
            $adapter = AdapterFactory::getInstance();
            $results = $adapter->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

            return SkillResult::success([
                'sql' => $sql,
                'rows' => $results,
                'count' => count($results),
                'total' => count($results),
            ]);

        } catch (\Throwable $e) {
            return SkillResult::error("Query failed: {$e->getMessage()}");
        }
    }

    public function isDangerous(): bool
    {
        return false;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
