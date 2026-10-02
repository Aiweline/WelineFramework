<?php
declare(strict_types=1);

namespace Weline\Theme\Test;

use PDO;
use Weline\Theme\Model\ThemeComponent;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;

/**
 * Weline_Theme 测试库夹具。
 *
 * 存在原因（2026-10-02 审查结论，见 dev/audit/theme-legacy-audit-20261002.md §19）：
 * 测试沙箱库 `app/etc/sandbox_db.sqlite`（`Env.php:178` 的 `sandbox_db.path`，`SANDBOX=true` 时启用）
 * 只有 7 张表、**不含任何 Theme 表**，导致全量套件中 66 次
 * `no such table: w_weline_theme` 之类失败（PDOException / RuntimeException 级联）。
 *
 * 本夹具按**模型自身声明的 `schema_fields_*` 常量**生成最小可用表结构（不手抄列名），
 * 仅在表缺失时创建，因此可安全地在每次测试运行前调用。
 */
final class ThemeTestSchema
{
    /** 已确保过的表（进程内去重，避免每个测试类重复建表） */
    private static array $ensured = [];

    /**
     * 确保指定模型对应的表存在。可传多个模型类。
     *
     * @param class-string ...$modelClasses
     */
    public static function ensure(string ...$modelClasses): void
    {
        foreach ($modelClasses as $class) {
            $table = self::resolveTableName($class);
            if ($table === '' || isset(self::$ensured[$table])) {
                continue;
            }
            self::$ensured[$table] = true;

            $pdo = self::pdo();
            if ($pdo === null) {
                return;
            }

            $exists = $pdo->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table)
            )->fetchColumn();
            if ($exists) {
                continue;
            }

            // 用框架自身的 schema 解析 + 方言适配建表，保证与生产同构
            // （此前由 schema_fields_* 常量手拼 DDL → 全列 INTEGER 的"浅表"，
            //   与生产列类型/默认值不一致，会让写入/读取行为偏离，2026-10-02 定位并修正）
            try {
                $parser = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Framework\Database\Schema\SchemaParser::class
                );
                $executor = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Framework\Database\Schema\SchemaMigrationExecutor::class
                );
                $schema = $parser->parse($class);
                if ($schema !== null) {
                    $executor->createBootstrapTable(self::connector(), $schema);
                }
            } catch (\Throwable) {
                // 保持 fail-safe：夹具失败不应让测试崩在基建上
            }        }
    }

    /** Weline_Theme 域测试最常用的表 */
    public static function ensureCoreTables(): void
    {
        self::ensure(WelineTheme::class, ThemeLayout::class, ThemeComponent::class);

        // 跨模块依赖（显式且带说明，不是隐式耦合）：
        // Widget 渲染路径会读 I18n 词典表，缺失时部件渲染成
        // `<div class="widget-preview-error">… no such table: w_i18n_locale_dictionary</div>`，
        // 导致 SiteBlockLibraryRuntimeTest 等运行时用例误判（2026-10-02 定位）。
        // 这里按同一规则（由模型 schema_fields_* 推导 DDL）确保其存在。
        self::ensure(
            \Weline\I18n\Model\Dictionary::class,
            \Weline\I18n\Model\Locale\Dictionary::class,
        );
    }

    /** 沙箱库连接（框架方言适配连接器） */
    private static function connector(): \Weline\Framework\Database\Connection\Adapter\Sqlite\Connector
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Database\Connection\Adapter\Sqlite\Connector::class
        );
    }
    private static function resolveTableName(string $modelClass): string
    {
        if (!class_exists($modelClass)) {
            return '';
        }
        try {
            /** @var object $model */
            $model = new $modelClass();
            if (method_exists($model, 'getTable')) {
                // 实测 getTable() 可能返回 '"public"."w_weline_theme"' 这类带 schema/双引号的形态，
                // 需解析出裸表名（SQLite 无 schema 概念）
                $raw = (string)$model->getTable();
                $table = self::normalizeTableName($raw);
                if ($table !== '') {
                    return $table;
                }
            }
        } catch (\Throwable) {
            // 落回约定
        }

        return self::conventionTableName($modelClass);
    }

    /** 从 '"public"."w_weline_theme"' / 'w_weline_theme' / '`db`.`tbl`' 解析出裸表名 */
    private static function normalizeTableName(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // 取最后一段（schema.table → table）
        $parts = preg_split('/\s*\.\s*/', $raw) ?: [];
        $last = trim((string)end($parts));
        $last = trim($last, "\"`'[] \t\n\r\0\x0B");
        if ($last === '' || !preg_match('/^[A-Za-z0-9_]+$/', $last)) {
            return '';
        }

        return $last;
    }

    /** 约定：Weline\Theme\Model\WelineTheme → w_weline_theme */
    private static function conventionTableName(string $modelClass): string
    {
        $short = substr($modelClass, (int)strrpos($modelClass, '\\') + 1);
        $snake = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $short));

        return 'w_' . $snake;
    }

    /**
     * 从模型 `schema_fields_*` 常量按声明顺序取出列名。
     *
     * @return list<string>
     */
    private static function columnsFromSchemaConstants(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            return [];
        }
        $ref = new \ReflectionClass($modelClass);
        $columns = [];
        foreach ($ref->getConstants() as $name => $value) {
            if (!str_starts_with($name, 'schema_fields_') || !is_string($value) || $value === '') {
                continue;
            }
            $columns[] = $value;
        }

        return array_values(array_unique($columns));
    }

    private static function pdo(): ?PDO
    {
        $path = APP_PATH . 'etc/sandbox_db.sqlite';
        if (!is_file($path)) {
            return null;
        }
        try {
            return new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
