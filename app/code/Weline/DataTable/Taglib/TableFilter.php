<?php

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\TableContext;
use Weline\Framework\App\Exception;
use Weline\Taglib\TaglibInterface;

class TableFilter implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 't-filter';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return [
            'scope' => false,
            'model' => false,
            'searchable' => false,
            'advanced' => false,
            'collapsible' => false
        ];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return false;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return 'd-table';
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 属性继承与校验
            $scope = $attributes['scope'] ?? '';
            $model = $attributes['model'] ?? '';
            $searchable = filter_var($attributes['searchable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $advanced = filter_var($attributes['advanced'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $collapsible = filter_var($attributes['collapsible'] ?? true, FILTER_VALIDATE_BOOLEAN);

            // 从父表格继承属性
            $inheritedAttributes = TableContext::inheritTableAttributes(
                $attributes,
                $scope,
                ['model', 'scope', 'searchable', 'editable']
            );

            $model = $inheritedAttributes['model'] ?? $model;
            $scope = $inheritedAttributes['scope'] ?? $scope;

            if (isset($inheritedAttributes['searchable'])) {
                $searchable = filter_var($inheritedAttributes['searchable'], FILTER_VALIDATE_BOOLEAN);
            }

            // 验证必需的属性
            TableContext::validateRequiredAttributes(
                ['model' => $model, 'scope' => $scope],
                ['model', 'scope'],
                't-filter'
            );

            // 生成子scope
            $filterScope = $scope . '-filter';

            // 设置过滤器上下文
            $filterContext = array_merge($inheritedAttributes, [
                'type' => 't-filter',
                'scope' => $filterScope,
                'searchable' => $searchable,
                'advanced' => $advanced,
                'collapsible' => $collapsible
            ]);

            TableContext::pushChildTag('t-filter', $filterScope, $filterContext);

            $content = $tag_data[2] ?? '';

            // 如果没有手动配置字段，尝试自动生成
            if (empty(trim($content))) {
                $content = self::generateDefaultFilterFields($model, $filterScope, $inheritedAttributes);
            }

            // 生成过滤器HTML
            $result = self::generateFilterHtml($model, $filterScope, $content, $filterContext);

            TableContext::popTag();
            return $result;
        };
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 生成默认的过滤器字段
     * @param string $model 模型类名
     * @param string $scope 作用域
     * @param array $context 上下文
     * @return string 字段HTML
     */
    private static function generateDefaultFilterFields(string $model, string $scope, array $context): string
    {
        try {
            // 尝试实例化模型获取字段信息
            $modelInstance = w_obj($model);
            $fields = [];

            // 尝试多种方式获取字段信息
            if (method_exists($modelInstance, 'getColumns')) {
                $columns = $modelInstance->getColumns();
                foreach ($columns as $column) {
                    $fieldName = $column['Field'];
                    // 只为主要字段生成过滤器
                    if (in_array($fieldName, ['name', 'title', 'username', 'email', 'status', 'type'])) {
                        $label = $column['Comment'] ?: $fieldName;
                        $fields[] = [
                            'name' => $fieldName,
                            'label' => $label,
                            'type' => self::getFilterType($fieldName, $column['Type']),
                            'placeholder' => "搜索{$label}"
                        ];
                    }
                }
            } else {
                // 使用默认字段
                $defaultFields = [
                    ['name' => 'name', 'type' => 'text', 'placeholder' => '搜索名称'],
                    ['name' => 'status', 'type' => 'select', 'placeholder' => '选择状态']
                ];
                $fields = $defaultFields;
            }

            // 限制字段数量
            $fields = array_slice($fields, 0, 3);

            // 生成字段HTML
            $fieldsHtml = '';
            foreach ($fields as $field) {
                $typeAttr = isset($field['type']) ? "type=\"{$field['type']}\"" : '';
                $placeholderAttr = isset($field['placeholder']) ? "placeholder=\"{$field['placeholder']}\"" : '';
                $optionsAttr = '';

                // 为状态字段添加选项
                if ($field['name'] === 'status' && $field['type'] === 'select') {
                    $optionsAttr = 'options="1:启用,0:禁用"';
                }

                $fieldsHtml .= "<w:field belong=\"t-filter\" name=\"{$field['name']}\" {$typeAttr} {$placeholderAttr} {$optionsAttr}></w:field>\n";
            }

            return $fieldsHtml;

        } catch (\Exception $e) {
            error_log("TableFilter: 自动生成字段失败: " . $e->getMessage());
            return '<w:field belong="t-filter" name="name" type="text" placeholder="搜索名称"></w:field>';
        }
    }

    /**
     * 生成过滤器HTML
     * @param string $model 模型类名
     * @param string $scope 作用域
     * @param string $content 内容
     * @param array $context 上下文
     * @return string HTML
     */
    private static function generateFilterHtml(string $model, string $scope, string $content, array $context): string
    {
        $searchableJs = $context['searchable'] ? 'true' : 'false';
        $advancedJs = $context['advanced'] ? 'true' : 'false';
        $collapsibleJs = $context['collapsible'] ? 'true' : 'false';

        $collapsibleClass = $context['collapsible'] ? 'datatable-filter-collapsible' : '';

        return <<<HTML
<div class="datatable-filter-container {$collapsibleClass}"
     data-model="{$model}"
     data-scope="{$scope}"
     data-searchable="{$searchableJs}"
     data-advanced="{$advancedJs}"
     data-collapsible="{$collapsibleJs}">
    <div class="datatable-filter-toolbar">
        {$content}
    </div>
</div>
HTML;
    }

    /**
     * 获取过滤器类型
     * @param string $fieldName 字段名
     * @param string $dbType 数据库类型
     * @return string 过滤器类型
     */
    private static function getFilterType(string $fieldName, string $dbType = ''): string
    {
        // 根据字段名判断
        $typeMap = [
            'status' => 'select',
            'type' => 'select',
            'state' => 'select',
            'created_at' => 'date',
            'updated_at' => 'date',
            'email' => 'email',
            'phone' => 'tel',
            'url' => 'url',
            'website' => 'url'
        ];

        if (isset($typeMap[$fieldName])) {
            return $typeMap[$fieldName];
        }

        // 根据数据库类型判断
        if (strpos($dbType, 'int') !== false) {
            return 'number';
        } elseif (strpos($dbType, 'date') !== false || strpos($dbType, 'time') !== false) {
            return 'date';
        } elseif (strpos($dbType, 'text') !== false) {
            return 'textarea';
        }

        return 'text';
    }

    public static function document(): string
    {
        return <<<DOC
表格过滤器组件使用方式：

基础用法（从父标签继承model和scope）：
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-filter>
        <w:field name="name" type="text" placeholder="搜索名称"></w:field>
        <w:field name="status" type="select" options="1:启用,0:禁用"></w:field>
    </w:t-filter>
</w:d-table>

高级用法（手动指定model和scope）：
<w:t-filter scope="custom-filter-scope" model="Weline\Demo\Model\Demo" searchable="true" advanced="true">
    <w:field name="name" type="text" placeholder="搜索名称"></w:field>
    <w:field name="status" type="select" options="1:启用,0:禁用"></w:field>
</w:t-filter>

属性说明：
scope: 可选，指定数据作用域（自动生成：{table-scope}-filter）
model: 可选，指定数据模型类名（可从父标签继承）
searchable: 可选，是否启用搜索功能，默认true
advanced: 可选，是否启用高级筛选，默认false
collapsible: 可选，是否可折叠，默认true

注意：
1. 当在w:d-table内部使用时，model和scope属性会自动从父标签继承
2. scope会自动生成为"{table-scope}-filter"格式
3. 无需手动指定model和scope，除非需要覆盖继承的值
DOC;
    }
}