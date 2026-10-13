<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\ForeignKey;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use ReflectionClass;

/**
 * 解析 Model 类上的 #[Table]/#[Col]/#[Index]/#[ForeignKey] 注解，输出 TableSchema。
 * 表名通过 Model::getTable() 获取（含前缀）。
 * 继承父类但无自身 #[Col] 的 Model 会跳过（不当作独立表）。
 *
 * LocalModel（Weline\I18n\Api\Localization\LocalModel）且存在 local_code 列时：
 * 声明主键必须含 local_code（与 entity 键组成复合 PK），避免 SchemaDiff
 * 「physical primary key differs from declared primary key; refuse silent rewrite」。
 */
final class SchemaParser
{
    /**
     * 解析单个 Model 类，返回声明式表结构；无注解或非 Model 返回 null。
     */
    public function parse(string $modelClass): ?TableSchema
    {
        if (!class_exists($modelClass)) {
            return null;
        }

        $ref = new ReflectionClass($modelClass);
        if (!$ref->isSubclassOf(AbstractModel::class)) {
            return null;
        }

        $columns = $this->parseColumns($ref);
        if ($columns === []) {
            return null;
        }

        $tableName = $this->resolveTableName($modelClass);
        $comment = '';
        $tableAttrs = $ref->getAttributes(Table::class);
        if ($tableAttrs !== []) {
            $a = $tableAttrs[0]->newInstance();
            $comment = $a->comment;
        }

        $indexes = $this->parseIndexes($ref);
        $foreignKeys = $this->parseForeignKeys($ref);

        return new TableSchema(
            tableName: $tableName,
            comment: $comment,
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            modelClass: $modelClass
        );
    }

    /**
     * 解析多个 Model 类，返回 TableSchema 列表（跳过无 #[Col] 的类）。
     *
     * @param list<string> $modelClasses
     * @return list<TableSchema>
     */
    public function parseMany(array $modelClasses): array
    {
        $result = [];
        foreach ($modelClasses as $class) {
            $schema = $this->parse($class);
            if ($schema !== null) {
                $result[] = $schema;
            }
        }
        return $result;
    }

    private function resolveTableName(string $modelClass): string
    {
        try {
            $model = ObjectManager::getInstance($modelClass);
            if ($model instanceof AbstractModel) {
                return $model->getTable();
            }
        } catch (\Throwable) {
            $short = (new ReflectionClass($modelClass))->getShortName();
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
            return str_replace('_model', '', $snake);
        }
        return '';
    }

    /**
     * 从当前类及父类继承的 schema_fields_* 常量解析列，子类覆盖父类同名列。
     *
     * @return list<ColumnDefinition>
     */
    private function parseColumns(ReflectionClass $ref): array
    {
        if (!$this->hasOwnColumnDeclaration($ref)) {
            return [];
        }

        $byName = [];
        $chain = $this->getClassChain($ref);
        $materializedConstants = [];
        $customPk = null;
        $declaredPrimaryKeys = [];
        $skipParentId = false;
        try {
            if ($ref->hasConstant('schema_primary_key')) {
                $pk = $ref->getConstant('schema_primary_key');
                if (is_string($pk) && $pk !== '' && $pk !== 'id') {
                    $customPk = $pk;
                    $declaredPrimaryKeys = [$pk];
                }
            }
            if ($ref->hasConstant('schema_primary_keys')) {
                $pks = $ref->getConstant('schema_primary_keys');
                if (is_array($pks) && $pks !== []) {
                    $skipParentId = true;
                    $declaredPrimaryKeys = [];
                    foreach ($pks as $pkCol) {
                        if (is_string($pkCol) && $pkCol !== '') {
                            $declaredPrimaryKeys[] = $pkCol;
                        }
                    }
                    // Composite/natural keys win over a single schema_primary_key / schema_fields_ID hint.
                    $customPk = null;
                }
            }
            // 若模型主键字段名不是 id（如 user_id、currency_id），则跳过基类的 id 列，避免双主键。
            // Only treat schema_fields_ID as the sole PK when schema_primary_keys is absent.
            if ($declaredPrimaryKeys === [] && $customPk === null && $ref->hasConstant('schema_fields_ID')) {
                $idField = $ref->getConstant('schema_fields_ID');
                if (is_string($idField) && $idField !== '' && $idField !== 'id') {
                    $customPk = $idField;
                    $declaredPrimaryKeys = [$idField];
                }
            } elseif ($customPk !== null && $declaredPrimaryKeys === []) {
                $declaredPrimaryKeys = [$customPk];
            }
            // LocalModel: only fold local_code into declared PK when that column's
            // #[Col] marks primaryKey=true (physical composite). Do NOT append for
            // every LocalModel — some tables (e.g. currency_local_description) keep
            // a surrogate PRIMARY(id) + UNIQUE(entity, local_code). Blind append
            // causes SchemaDiff "refuse silent rewrite" the other way.
            // Do NOT disable refuse silent rewrite; do NOT swallow setup:upgrade.
            $localePk = $this->resolveLocalModelLocalePrimaryColumn($ref);
            if ($localePk !== null) {
                if ($declaredPrimaryKeys === []) {
                    $entityKey = null;
                    if ($ref->hasConstant('schema_fields_ID')) {
                        $idField = $ref->getConstant('schema_fields_ID');
                        if (is_string($idField) && $idField !== '' && $idField !== $localePk) {
                            $entityKey = $idField;
                        }
                    }
                    if ($entityKey !== null) {
                        $declaredPrimaryKeys[] = $entityKey;
                        if ($entityKey !== 'id') {
                            $customPk = $entityKey;
                        }
                    }
                }
                if (!\in_array($localePk, $declaredPrimaryKeys, true)) {
                    $declaredPrimaryKeys[] = $localePk;
                }
            }
            if ($declaredPrimaryKeys !== []) {
                $skipParentId = true;
            }
        } catch (\Throwable) {
        }
        $pkSet = array_fill_keys($declaredPrimaryKeys, true);
        foreach ($chain as $class) {
            foreach ($class->getReflectionConstants() as $c) {
                if ($c->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                $constName = $c->getName();
                if (!str_starts_with($constName, 'schema_fields_') || !$c->isPublic()) {
                    continue;
                }
                $attrs = $c->getAttributes(Col::class);
                if ($attrs === []) {
                    continue;
                }
                if (isset($materializedConstants[$constName])) {
                    continue;
                }
                try {
                    $col = $attrs[0]->newInstance();
                    $name = $this->resolveColumnName($ref, $class, $constName, $c);
                    if (!is_string($name)) {
                        continue;
                    }
                    // Skip inherited Model::id when a custom/composite PK is declared.
                    // Own-declared `id` columns stay (non-PK) so Diff does not DROP them.
                    if (($customPk !== null || $skipParentId)
                        && $name === 'id'
                        && $c->getDeclaringClass()->getName() !== $ref->getName()) {
                        continue;
                    }
                    if (isset($byName[$name])) {
                        continue;
                    }
                    $isDeclaredPrimary = $declaredPrimaryKeys !== []
                        ? isset($pkSet[$name])
                        : ($col->primaryKey || isset($pkSet[$name]));
                    $byName[$name] = new ColumnDefinition(
                        name: $name,
                        type: $col->type,
                        length: $col->length,
                        nullable: $col->nullable,
                        primaryKey: $isDeclaredPrimary,
                        autoIncrement: $col->autoIncrement,
                        default: $col->default,
                        comment: $col->comment ?? '',
                        unique: $col->unique
                    );
                    $materializedConstants[$constName] = true;
                } catch (\Throwable) {
                    // 忽略无效常量
                }
            }
        }
        return array_values($byName);
    }

    private function hasOwnColumnDeclaration(ReflectionClass $ref): bool
    {
        foreach ($ref->getReflectionConstants() as $c) {
            if ($c->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }
            if (!str_starts_with($c->getName(), 'schema_fields_') || !$c->isPublic()) {
                continue;
            }
            if ($c->getAttributes(Col::class) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * LocalModel + local_code #[Col(primaryKey: true)] → return locale PK column name.
     * Soft-coupled via FQCN string so Framework Schema does not hard-require I18n at compile time.
     */
    private function resolveLocalModelLocalePrimaryColumn(ReflectionClass $ref): ?string
    {
        $localModelFqcn = 'Weline\\I18n\\Api\\Localization\\LocalModel';
        if (!class_exists($localModelFqcn) || !$ref->isSubclassOf($localModelFqcn)) {
            return null;
        }

        $candidates = [];
        try {
            if ($ref->hasConstant('schema_fields_local_code')) {
                $v = $ref->getConstant('schema_fields_local_code');
                if (is_string($v) && $v !== '') {
                    $candidates[] = $v;
                }
            }
        } catch (\Throwable) {
        }
        $candidates[] = 'local_code';
        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $name) {
            if ($this->hasColDeclaredPrimaryColumnNamed($ref, $name)) {
                return $name;
            }
        }

        return null;
    }

    /** Inheritance chain has #[Col(primaryKey: true)] materializing the given column name. */
    private function hasColDeclaredPrimaryColumnNamed(ReflectionClass $ref, string $columnName): bool
    {
        foreach ($this->getClassChain($ref) as $class) {
            foreach ($class->getReflectionConstants() as $c) {
                if ($c->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                if (!str_starts_with($c->getName(), 'schema_fields_') || !$c->isPublic()) {
                    continue;
                }
                $attrs = $c->getAttributes(Col::class);
                if ($attrs === []) {
                    continue;
                }
                try {
                    $col = $attrs[0]->newInstance();
                    if (!$col->primaryKey) {
                        continue;
                    }
                    $name = $this->resolveColumnName($ref, $class, $c->getName(), $c);
                } catch (\Throwable) {
                    continue;
                }
                if (is_string($name) && $name === $columnName) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolveColumnName(
        ReflectionClass $ref,
        ReflectionClass $sourceClass,
        string $constName,
        \ReflectionClassConstant $sourceConstant
    ): mixed {
        foreach ($this->getClassChain($ref) as $class) {
            $declared = $this->getDeclaredConstant($class, $constName);
            if ($declared !== null) {
                return $declared->getValue();
            }
            if ($class->getName() === $sourceClass->getName()) {
                break;
            }
        }
        return $sourceConstant->getValue();
    }

    private function getDeclaredConstant(ReflectionClass $class, string $constName): ?\ReflectionClassConstant
    {
        foreach ($class->getReflectionConstants() as $candidate) {
            if ($candidate->getName() === $constName && $candidate->getDeclaringClass()->getName() === $class->getName()) {
                return $candidate;
            }
        }
        return null;
    }

    /** @return list<ReflectionClass> 从当前类到 AbstractModel 的继承链（子类在前，父类在后） */
    private function getClassChain(ReflectionClass $ref): array
    {
        $chain = [];
        $current = $ref;
        while ($current !== false && $current->getName() !== AbstractModel::class) {
            $chain[] = $current;
            $current = $current->getParentClass();
        }
        if ($current !== false) {
            $chain[] = $current;
        }
        return $chain;
    }

    /** @return list<IndexDefinition> */
    private function parseIndexes(ReflectionClass $ref): array
    {
        $list = [];
        foreach ($ref->getAttributes(Index::class) as $attr) {
            $idx = $attr->newInstance();
            $columns = is_array($idx->columns) ? $idx->columns : [$idx->columns];
            $list[] = new IndexDefinition(
                name: $idx->name,
                columns: array_values($columns),
                type: $idx->type,
                comment: $idx->comment,
                method: $idx->method
            );
        }
        return $list;
    }

    /** @return list<ForeignKeyDefinition> */
    private function parseForeignKeys(ReflectionClass $ref): array
    {
        $list = [];
        foreach ($ref->getAttributes(ForeignKey::class) as $attr) {
            $fk = $attr->newInstance();
            $cols = is_array($fk->columns) ? $fk->columns : [$fk->columns];
            $refCols = is_array($fk->referencesColumns) ? $fk->referencesColumns : [$fk->referencesColumns];
            $list[] = new ForeignKeyDefinition(
                name: $fk->name,
                columns: array_values($cols),
                referencesTable: $fk->referencesTable,
                referencesColumns: array_values($refCols),
                onDeleteCascade: $fk->onDeleteCascade,
                onUpdateCascade: $fk->onUpdateCascade
            );
        }
        return $list;
    }
}
