<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Pgsql;

/**
 * PostgreSQL Schema 配置管理器
 *
 * 统一管理 PostgreSQL schema 配置，避免硬编码
 */
class SchemaConfig
{
    private static ?string $currentSchema = null;
    private static ?\PDO $pdo = null;

    /**
     * 设置 PDO 连接
     */
    public static function setPdo(\PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$currentSchema = null; // 重置缓存
    }

    /**
     * 获取当前 schema
     *
     * 优先级：
     * 1. 从数据库查询 current_schema()
     * 2. 回退到 'public'
     */
    public static function getCurrentSchema(): string
    {
        // 如果已缓存，直接返回
        if (self::$currentSchema !== null) {
            return self::$currentSchema;
        }

        // 如果有 PDO 连接，查询当前 schema
        if (self::$pdo !== null) {
            try {
                $schema = self::$pdo->query('SELECT current_schema()')->fetchColumn();
                self::$currentSchema = $schema ?: 'public';
                return self::$currentSchema;
            } catch (\Throwable $e) {
                // 查询失败，使用默认值
            }
        }

        // 回退到 public
        return 'public';
    }

    /**
     * 重置缓存（用于测试或连接切换）
     */
    public static function reset(): void
    {
        self::$currentSchema = null;
        self::$pdo = null;
    }

    /**
     * 检查是否是系统 schema
     */
    public static function isSystemSchema(string $schema): bool
    {
        return in_array(strtolower($schema), [
            'public',
            'information_schema',
            'pg_catalog',
            'pg_toast',
            'pg_temp_1',
            'pg_toast_temp_1'
        ]);
    }

    /**
     * 格式化 schema.table 名称
     */
    public static function formatSchemaTable(string $table, ?string $schema = null): string
    {
        $schema = $schema ?? self::getCurrentSchema();
        return "\"{$schema}\".\"{$table}\"";
    }
}
