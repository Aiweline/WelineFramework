<?php
/**
 * 依赖关系管理器
 */

namespace Weline\DataTable\Helper;

class DependencyManager
{
    /**
     * 解析依赖关系配置
     * 
     * @param string $dependencies 依赖关系字符串，格式：主表.字段->从表.字段,主表.字段->从表.字段
     * @return array 解析后的依赖关系数组
     */
    public static function parseDependencies(string $dependencies): array
    {
        if (empty($dependencies)) {
            return [];
        }

        $result = [];
        $dependencyPairs = explode(',', $dependencies);

        foreach ($dependencyPairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }

            if (strpos($pair, '->') === false) {
                throw new \InvalidArgumentException("Invalid dependency format: {$pair}. Expected format: 'parent_table.field->child_table.field'");
            }

            [$parent, $child] = explode('->', $pair, 2);
            $parent = trim($parent);
            $child = trim($child);

            // 解析父表和字段
            if (strpos($parent, '.') !== false) {
                [$parentTable, $parentField] = explode('.', $parent, 2);
            } else {
                throw new \InvalidArgumentException("Invalid parent format: {$parent}. Expected format: 'table.field'");
            }

            // 解析子表和字段
            if (strpos($child, '.') !== false) {
                [$childTable, $childField] = explode('.', $child, 2);
            } else {
                throw new \InvalidArgumentException("Invalid child format: {$child}. Expected format: 'table.field'");
            }

            $result[] = [
                'parent_table' => trim($parentTable),
                'parent_field' => trim($parentField),
                'child_table' => trim($childTable),
                'child_field' => trim($childField)
            ];
        }

        return $result;
    }

    /**
     * 根据依赖关系计算保存顺序
     * 
     * @param array $dependencies 依赖关系数组
     * @param array $tables 表列表
     * @return array 排序后的表列表
     */
    public static function calculateSaveOrder(array $dependencies, array $tables): array
    {
        if (empty($dependencies)) {
            return $tables;
        }

        // 构建依赖图
        $graph = [];
        $inDegree = [];

        // 初始化
        foreach ($tables as $table) {
            $graph[$table] = [];
            $inDegree[$table] = 0;
        }

        // 构建依赖关系
        foreach ($dependencies as $dep) {
            $parent = $dep['parent_table'];
            $child = $dep['child_table'];

            if (in_array($parent, $tables) && in_array($child, $tables)) {
                $graph[$parent][] = $child;
                $inDegree[$child]++;
            }
        }

        // 拓扑排序
        $result = [];
        $queue = [];

        // 找到所有入度为0的节点
        foreach ($inDegree as $table => $degree) {
            if ($degree === 0) {
                $queue[] = $table;
            }
        }

        while (!empty($queue)) {
            $current = array_shift($queue);
            $result[] = $current;

            // 处理当前节点的所有邻接节点
            foreach ($graph[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        // 检查是否有循环依赖
        if (count($result) !== count($tables)) {
            throw new \RuntimeException('Circular dependency detected in table relationships');
        }

        return $result;
    }

    /**
     * 获取默认依赖关系
     * 
     * @param array $models 模型配置数组
     * @param string $joinConfig JOIN配置
     * @return array 默认依赖关系
     */
    public static function getDefaultDependencies(array $models, string $joinConfig): array
    {
        if (empty($joinConfig)) {
            return [];
        }

        $dependencies = [];
        $joins = explode(',', $joinConfig);

        foreach ($joins as $join) {
            $join = trim($join);
            if (empty($join)) {
                continue;
            }

            // 解析JOIN条件，例如：left u.id = o.user_id
            if (preg_match('/(\w+)\s+(\w+)\.(\w+)\s*=\s*(\w+)\.(\w+)/', $join, $matches)) {
                $joinType = $matches[1]; // left, right, inner
                $leftTable = $matches[2];
                $leftField = $matches[3];
                $rightTable = $matches[4];
                $rightField = $matches[5];

                // 默认情况下，左表是父表，右表是子表
                $dependencies[] = [
                    'parent_table' => $leftTable,
                    'parent_field' => $leftField,
                    'child_table' => $rightTable,
                    'child_field' => $rightField
                ];
            }
        }

        return $dependencies;
    }

    /**
     * 验证依赖关系的有效性
     * 
     * @param array $dependencies 依赖关系数组
     * @param array $models 模型配置数组
     * @return bool 是否有效
     * @throws \InvalidArgumentException
     */
    public static function validateDependencies(array $dependencies, array $models): bool
    {
        $tableAliases = array_keys($models);

        foreach ($dependencies as $dep) {
            $parentTable = $dep['parent_table'];
            $childTable = $dep['child_table'];

            // 检查表别名是否存在
            if (!in_array($parentTable, $tableAliases)) {
                throw new \InvalidArgumentException("Parent table alias '{$parentTable}' not found in models");
            }

            if (!in_array($childTable, $tableAliases)) {
                throw new \InvalidArgumentException("Child table alias '{$childTable}' not found in models");
            }

            // 检查字段是否存在（这里可以进一步扩展，检查模型的实际字段）
            $parentField = $dep['parent_field'];
            $childField = $dep['child_field'];

            if (empty($parentField) || empty($childField)) {
                throw new \InvalidArgumentException("Field names cannot be empty in dependency relationship");
            }
        }

        return true;
    }

    /**
     * 应用依赖关系到数据
     * 
     * @param array $data 要保存的数据，按表别名分组
     * @param array $dependencies 依赖关系数组
     * @param array $savedResults 已保存的结果，包含生成的ID
     * @return array 应用依赖关系后的数据
     */
    public static function applyDependencies(array $data, array $dependencies, array $savedResults): array
    {
        foreach ($dependencies as $dep) {
            $parentTable = $dep['parent_table'];
            $parentField = $dep['parent_field'];
            $childTable = $dep['child_table'];
            $childField = $dep['child_field'];

            // 如果父表已经保存并且有ID，将其应用到子表
            if (isset($savedResults[$parentTable]) && isset($savedResults[$parentTable]['id'])) {
                $parentId = $savedResults[$parentTable]['id'];
                
                if (isset($data[$childTable])) {
                    $data[$childTable][$childField] = $parentId;
                }
            }
        }

        return $data;
    }

    /**
     * 获取表的依赖信息
     * 
     * @param string $table 表别名
     * @param array $dependencies 依赖关系数组
     * @return array 依赖信息
     */
    public static function getTableDependencies(string $table, array $dependencies): array
    {
        $result = [
            'parents' => [], // 此表依赖的父表
            'children' => [] // 依赖此表的子表
        ];

        foreach ($dependencies as $dep) {
            if ($dep['child_table'] === $table) {
                $result['parents'][] = $dep;
            }
            
            if ($dep['parent_table'] === $table) {
                $result['children'][] = $dep;
            }
        }

        return $result;
    }

    /**
     * 检查是否可以删除记录
     * 
     * @param string $table 表别名
     * @param mixed $recordId 记录ID
     * @param array $dependencies 依赖关系数组
     * @param array $models 模型配置数组
     * @return array 检查结果
     */
    public static function checkDeleteConstraints(string $table, $recordId, array $dependencies, array $models): array
    {
        $result = [
            'can_delete' => true,
            'constraints' => [],
            'related_records' => []
        ];

        $tableDeps = self::getTableDependencies($table, $dependencies);

        // 检查子表中是否有相关记录
        foreach ($tableDeps['children'] as $dep) {
            $childTable = $dep['child_table'];
            $childField = $dep['child_field'];

            if (isset($models[$childTable])) {
                $childModel = $models[$childTable];
                
                // 这里需要实际查询数据库来检查相关记录
                // 为了示例，我们假设有一个方法来检查
                $relatedCount = self::countRelatedRecords($childModel, $childField, $recordId);
                
                if ($relatedCount > 0) {
                    $result['can_delete'] = false;
                    $result['constraints'][] = [
                        'table' => $childTable,
                        'field' => $childField,
                        'count' => $relatedCount
                    ];
                    $result['related_records'][$childTable] = $relatedCount;
                }
            }
        }

        return $result;
    }

    /**
     * 计算相关记录数量（示例方法）
     * 
     * @param string $model 模型类名
     * @param string $field 字段名
     * @param mixed $value 字段值
     * @return int 记录数量
     */
    private static function countRelatedRecords(string $model, string $field, $value): int
    {
        try {
            // 这里应该实际查询数据库
            // 为了示例，返回一个模拟值
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
