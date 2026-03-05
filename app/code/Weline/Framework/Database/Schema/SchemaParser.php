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

        $tableName = $this->resolveTableName($modelClass);
        $comment = '';
        $tableAttrs = $ref->getAttributes(Table::class);
        if ($tableAttrs !== []) {
            $a = $tableAttrs[0]->newInstance();
            $comment = $a->comment;
        }

        $columns = $this->parseColumns($ref);
        if ($columns === []) {
            return null;
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
        $byName = [];
        $chain = $this->getClassChain($ref);
        $customPk = null;
        $skipParentId = false;
        try {
            if ($ref->hasConstant('schema_primary_key')) {
                $pk = $ref->getConstant('schema_primary_key');
                if (is_string($pk) && $pk !== '' && $pk !== 'id') {
                    $customPk = $pk;
                }
            }
            // 若模型主键字段名不是 id（如 user_id、currency_id），则跳过基类的 id 列，避免双主键
            if ($customPk === null && $ref->hasConstant('schema_fields_ID')) {
                $idField = $ref->getConstant('schema_fields_ID');
                if (is_string($idField) && $idField !== '' && $idField !== 'id') {
                    $customPk = $idField;
                }
            }
            if ($ref->hasConstant('schema_primary_keys')) {
                $pks = $ref->getConstant('schema_primary_keys');
                if (is_array($pks) && $pks !== []) {
                    $skipParentId = true;
                }
            }
        } catch (\Throwable) {
        }
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
                try {
                    $col = $attrs[0]->newInstance();
                    $name = $c->getValue();
                    if (!is_string($name)) {
                        continue;
                    }
                    if (($customPk !== null || $skipParentId) && $name === 'id') {
                        continue;
                    }
                    $byName[$name] = new ColumnDefinition(
                        name: $name,
                        type: $col->type,
                        length: $col->length,
                        nullable: $col->nullable,
                        primaryKey: $col->primaryKey,
                        autoIncrement: $col->autoIncrement,
                        default: $col->default,
                        comment: $col->comment ?? '',
                        unique: $col->unique
                    );
                } catch (\Throwable) {
                    // 忽略无效常量
                }
            }
        }
        return array_values($byName);
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
