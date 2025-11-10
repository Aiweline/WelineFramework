<?php

namespace Weline\DataTable\Helper;

/**
 * 表格上下文管理助手类
 * 用于管理d-table标签的属性继承和字段配置
 */
class TableContext
{
    /**
     * 存储表格上下文的静态数组
     * @var array
     */
    private static array $tableContexts = [];

    /**
     * 当前渲染栈，用于跟踪标签的渲染顺序
     * @var array
     */
    private static array $renderStack = [];

    /**
     * 存储模板中定义的字段配置
     * @var array
     */
    private static array $templateFields = [];

    /**
     * 设置表格上下文
     * @param string $scope 表格作用域
     * @param array $attributes 表格属性
     */
    public static function setTableContext(string $scope, array $attributes): void
    {
        self::$tableContexts[$scope] = $attributes;
        
        // 将当前表格推入渲染栈
        self::$renderStack[] = [
            'type' => 'table',
            'scope' => $scope,
            'attributes' => $attributes
        ];
    }

    /**
     * 获取表格上下文
     * @param string $scope 表格作用域
     * @return array|null 表格属性
     */
    public static function getTableContext(string $scope): ?array
    {
        return self::$tableContexts[$scope] ?? null;
    }

    /**
     * 获取当前活跃的表格上下文
     * @return array|null 当前表格上下文
     */
    public static function getCurrentTableContext(): ?array
    {
        // 从渲染栈中查找最近的表格上下文
        for ($i = count(self::$renderStack) - 1; $i >= 0; $i--) {
            if (self::$renderStack[$i]['type'] === 'table') {
                return self::$renderStack[$i]['attributes'];
            }
        }
        return null;
    }

    /**
     * 推入子标签到渲染栈
     * @param string $tagType 标签类型
     * @param string $scope 作用域
     * @param array $attributes 属性
     */
    public static function pushChildTag(string $tagType, string $scope, array $attributes): void
    {
        self::$renderStack[] = [
            'type' => $tagType,
            'scope' => $scope,
            'attributes' => $attributes
        ];
    }

    /**
     * 弹出渲染栈顶部的标签
     */
    public static function popTag(): void
    {
        array_pop(self::$renderStack);
    }

    /**
     * 清除表格上下文
     * @param string $scope 表格作用域
     */
    public static function clearTableContext(string $scope): void
    {
        unset(self::$tableContexts[$scope]);
        
        // 从渲染栈中移除对应的表格
        foreach (self::$renderStack as $key => $item) {
            if ($item['type'] === 'table' && $item['scope'] === $scope) {
                unset(self::$renderStack[$key]);
            }
        }
        self::$renderStack = array_values(self::$renderStack);
    }

    /**
     * 获取所有表格上下文
     * @return array
     */
    public static function getAllTableContexts(): array
    {
        return self::$tableContexts;
    }

    /**
     * 查找父表格上下文
     * @param string $childScope 子标签的scope
     * @return array|null 父表格上下文
     */
    public static function findParentTableContext(string $childScope): ?array
    {
        // 首先尝试从当前渲染栈中查找
        $currentContext = self::getCurrentTableContext();
        if ($currentContext) {
            return $currentContext;
        }

        // 如果渲染栈中没有，则从全局上下文中查找
        foreach (self::$tableContexts as $tableScope => $tableContext) {
            // 检查子scope是否是表格scope的子scope
            if (str_starts_with($childScope, $tableScope) || empty($childScope)) {
                return $tableContext;
            }
        }
        return null;
    }

    /**
     * 继承表格属性
     * @param array $attributes 当前标签属性
     * @param string $childScope 子标签scope
     * @param array $inheritKeys 需要继承的属性键
     * @return array 合并后的属性
     */
    public static function inheritTableAttributes(array $attributes, string $childScope, array $inheritKeys = []): array
    {
        $parentContext = self::findParentTableContext($childScope);
        
        if (!$parentContext) {
            return $attributes;
        }

        // 默认继承的属性
        $defaultInheritKeys = ['model', 'scope', 'searchable', 'sortable', 'editable'];
        $inheritKeys = array_merge($defaultInheritKeys, $inheritKeys);

        foreach ($inheritKeys as $key) {
            // 如果当前属性中没有设置该键，则从父上下文继承
            if (!isset($attributes[$key]) && isset($parentContext[$key])) {
                $value = $parentContext[$key];
                
                // 修复model属性的转义问题
                if ($key === 'model' && strpos($value, '\\\\') !== false) {
                    $value = str_replace('\\\\', '\\', $value);
                }
                
                $attributes[$key] = $value;
            }
        }

        // 特殊处理scope：如果为空，则生成子scope
        if (empty($attributes['scope']) && !empty($parentContext['scope'])) {
            $attributes['scope'] = $parentContext['scope'] . '-' . self::getChildScopeSuffix($childScope);
        }

        return $attributes;
    }

    /**
     * 获取子scope后缀
     * @param string $childScope 子标签scope
     * @return string 后缀
     */
    private static function getChildScopeSuffix(string $childScope): string
    {
        // 根据标签类型返回不同的后缀
        if (str_contains($childScope, 'header')) {
            return 'header';
        }
        if (str_contains($childScope, 'filter')) {
            return 'filter';
        }
        return 'child';
    }

    /**
     * 验证必需的属性
     * @param array $attributes 属性数组
     * @param array $requiredKeys 必需的属性键
     * @param string $tagName 标签名称
     * @throws \Weline\Framework\App\Exception
     */
    public static function validateRequiredAttributes(array $attributes, array $requiredKeys, string $tagName): void
    {
        foreach ($requiredKeys as $key) {
            if (empty($attributes[$key])) {
                throw new \Weline\Framework\App\Exception(__('%{1}标签必须指定%{2}属性或位于d-table标签内！', [$tagName, $key]));
            }
        }
    }

    /**
     * 清理过期的上下文
     * 可以定期调用此方法来清理不再使用的上下文
     */
    public static function cleanupExpiredContexts(): void
    {
        // 这里可以实现清理逻辑，比如基于时间戳清理过期的上下文
        // 目前简单实现，可以根据需要扩展
    }

    /**
     * 获取当前渲染栈
     * @return array
     */
    public static function getRenderStack(string $belong=''): array
    {
        $stack = self::$renderStack;
        if ($belong) {
            $stack = array_filter($stack, function($item) use ($belong) {
                return $item['type'] === $belong;
            });
            $stack = array_values($stack);
            $lastItem = end($stack);
            $stack = $lastItem !== false ? $lastItem : [];
        }
        return $stack ?: [];
    }

    /**
     * 清空渲染栈
     */
    public static function clearRenderStack(): void
    {
        self::$renderStack = [];
    }

    /**
     * 记录模板中定义的字段
     * @param string $scope 表格作用域
     * @param string $belong 字段所属类型 (t-header、t-filter 或 d-form)
     * @param string $fieldName 字段名称
     * @param array $fieldAttributes 字段属性
     */
    public static function recordTemplateField(string $scope, string $belong, string $fieldName, array $fieldAttributes): void
    {
        if (!isset(self::$templateFields[$scope])) {
            self::$templateFields[$scope] = [
                't-header' => [],
                't-filter' => [],
                'd-form' => []
            ];
        }

        // 添加字段到对应的类型中
        self::$templateFields[$scope][$belong][$fieldName] = array_merge([
            'name' => $fieldName,
            'belong' => $belong,
            'template_defined' => true, // 标记为模板中定义的字段
            'visible' => true, // 模板中定义的字段默认可见
            'searchable' => true, // 模板中定义的字段默认可搜索
            'sortable' => false, // 默认不可排序
            'editable' => false, // 默认不可编辑
        ], $fieldAttributes);
    }

    /**
     * 获取模板中定义的字段
     * @param string $scope 表格作用域
     * @param string $belong 字段所属类型 (t-header、t-filter 或 d-form)
     * @return array 字段配置数组
     */
    public static function getTemplateFields(string $scope, string $belong = ''): array
    {
        if (!isset(self::$templateFields[$scope])) {
            return [];
        }

        if ($belong) {
            return self::$templateFields[$scope][$belong] ?? [];
        }

        return self::$templateFields[$scope];
    }

    /**
     * 获取所有可用字段（包括模板定义的和模型中的）
     * @param string $scope 表格作用域
     * @param string $modelClass 模型类名
     * @return array 所有字段配置
     */
    public static function getAllAvailableFields(string $scope, string $modelClass): array
    {
        $templateFields = self::getTemplateFields($scope);
        $modelFields = self::getModelFields($modelClass);

        $allFields = [
            't-header' => [],
            't-filter' => [],
            'd-form' => []
        ];

        // 处理表头字段
        foreach ($modelFields as $fieldName => $fieldConfig) {
            $isTemplateDefined = isset($templateFields['t-header'][$fieldName]);
            
            if ($isTemplateDefined) {
                // 使用模板中定义的配置
                $allFields['t-header'][$fieldName] = $templateFields['t-header'][$fieldName];
            } else {
                // 使用模型默认配置
                $allFields['t-header'][$fieldName] = array_merge($fieldConfig, [
                    'template_defined' => false,
                    'visible' => false, // 未在模板中定义的字段默认不可见
                    'searchable' => true,
                    'sortable' => true,
                    'editable' => $fieldConfig['editable'] ?? false,
                ]);
            }
        }

        // 处理过滤器字段
        foreach ($modelFields as $fieldName => $fieldConfig) {
            $isTemplateDefined = isset($templateFields['t-filter'][$fieldName]);
            
            if ($isTemplateDefined) {
                // 使用模板中定义的配置
                $allFields['t-filter'][$fieldName] = $templateFields['t-filter'][$fieldName];
            } else {
                // 使用模型默认配置
                $allFields['t-filter'][$fieldName] = array_merge($fieldConfig, [
                    'template_defined' => false,
                    'visible' => false, // 未在模板中定义的字段默认不可见
                    'searchable' => true,
                    'sortable' => false, // 过滤器字段默认不可排序
                    'editable' => false, // 过滤器字段不可编辑
                ]);
            }
        }

        // 处理表单字段
        foreach ($modelFields as $fieldName => $fieldConfig) {
            $isTemplateDefined = isset($templateFields['d-form'][$fieldName]);
            
            if ($isTemplateDefined) {
                // 使用模板中定义的配置
                $allFields['d-form'][$fieldName] = $templateFields['d-form'][$fieldName];
            } else {
                // 使用模型默认配置
                $allFields['d-form'][$fieldName] = array_merge($fieldConfig, [
                    'template_defined' => false,
                    'visible' => true, // 表单字段默认可见
                    'searchable' => false, // 表单字段不可搜索
                    'sortable' => false, // 表单字段不可排序
                    'editable' => $fieldConfig['editable'] ?? true, // 表单字段默认可编辑
                ]);
            }
        }

        return $allFields;
    }

    /**
     * 获取模型字段信息
     * @param string $modelClass 模型类名
     * @return array 字段配置数组
     */
    private static function getModelFields(string $modelClass): array
    {
        try {
            if (!class_exists($modelClass)) {
                return [];
            }

            $model = \Weline\Framework\Manager\ObjectManager::getInstance($modelClass);
            $columns = $model->columns();

            $fields = [];
            foreach ($columns as $column) {
                $fieldName = is_array($column) ? ($column['Field'] ?? $column['field'] ?? '') : $column;
                if (empty($fieldName)) {
                    continue;
                }
                $fields[$fieldName] = [
                    'name' => $fieldName,
                    'label' => (is_array($column) && isset($column['Comment'])) ? ($column['Comment'] ?: $fieldName) : $fieldName,
                    'type' => is_array($column) && isset($column['Type']) ? self::getFieldType($column['Type']) : 'string',
                    'db_type' => is_array($column) && isset($column['Type']) ? $column['Type'] : 'varchar',
                    'sortable' => true,
                    'searchable' => true,
                    'editable' => !(is_array($column) && isset($column['Key']) && $column['Key'] === 'PRI'), // 主键不可编辑
                    'visible' => true,
                    'width' => is_array($column) && isset($column['Type']) ? self::getDefaultWidth($column['Type']) : '150px',
                    'min_width' => '80px',
                    'max_width' => '300px',
                    'resizable' => true,
                    'formatter' => '',
                    'validator' => '',
                    'default' => is_array($column) && isset($column['Default']) ? $column['Default'] : '',
                    'nullable' => is_array($column) && isset($column['Null']) ? ($column['Null'] === 'YES') : true,
                    'key' => is_array($column) && isset($column['Key']) ? $column['Key'] : '',
                    'extra' => is_array($column) && isset($column['Extra']) ? $column['Extra'] : ''
                ];
            }

            return $fields;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取字段类型
     * @param string $dbType 数据库字段类型
     * @return string 字段类型
     */
    private static function getFieldType(string $dbType): string
    {
        $type = strtolower($dbType);
        
        if (strpos($type, 'int') !== false) return 'number';
        if (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false) return 'number';
        if (strpos($type, 'date') !== false) return 'date';
        if (strpos($type, 'time') !== false) return 'datetime';
        if (strpos($type, 'text') !== false) return 'textarea';
        
        return 'text';
    }

    /**
     * 获取默认宽度
     * @param string $dbType 数据库字段类型
     * @return string 默认宽度
     */
    private static function getDefaultWidth(string $dbType): string
    {
        $type = strtolower($dbType);
        
        if (strpos($type, 'int') !== false) return '80px';
        if (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false) return '100px';
        if (strpos($type, 'date') !== false) return '120px';
        if (strpos($type, 'time') !== false) return '150px';
        if (strpos($type, 'text') !== false) return '200px';
        
        return '150px';
    }

    /**
     * 清除模板字段配置
     * @param string $scope 表格作用域
     */
    public static function clearTemplateFields(string $scope): void
    {
        unset(self::$templateFields[$scope]);
    }

    /**
     * 清除所有模板字段配置
     */
    public static function clearAllTemplateFields(): void
    {
        self::$templateFields = [];
    }

    /**
     * 清除所有上下文（用于测试）
     */
    public static function clearAll(): void
    {
        self::$tableContexts = [];
        self::$renderStack = [];
        self::$templateFields = [];
    }
} 