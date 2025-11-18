<?php

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\TableContext;
use Weline\Framework\App\Exception;
use Weline\Taglib\TaglibInterface;

class TableHeader implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 't-header';
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
            'sortable' => false,
            'draggable' => false,
            'configurable' => false,
            'resizable' => false
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
            // 检查是否为后端请求
            /** @var \Weline\Framework\Http\Request $request */
            $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            if (!$request->isBackend() && !$request->isApiBackend()) {
                // 前端请求直接返回空（开发环境返回注释说明）
                if (defined('DEV') && DEV) {
                    return '<!-- DataTable 表头标签只能在后端使用，当前为前端请求 -->';
                }
                return '';
            }
            
            // 属性继承与校验
            $scope = $attributes['scope'] ?? '';
            $model = $attributes['model'] ?? '';
            $sortable = filter_var($attributes['sortable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $draggable = filter_var($attributes['draggable'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $configurable = filter_var($attributes['configurable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $resizable = filter_var($attributes['resizable'] ?? true, FILTER_VALIDATE_BOOLEAN);

            // 从父表格继承属性
            $inheritedAttributes = TableContext::inheritTableAttributes(
                $attributes,
                $scope,
                ['model', 'scope', 'sortable', 'editable', 'searchable']
            );

            $model = $inheritedAttributes['model'] ?? $model;
            $scope = $inheritedAttributes['scope'] ?? $scope;

            if (isset($inheritedAttributes['sortable'])) {
                $sortable = filter_var($inheritedAttributes['sortable'], FILTER_VALIDATE_BOOLEAN);
            }

            // 验证必需的属性
            TableContext::validateRequiredAttributes(
                ['model' => $model, 'scope' => $scope],
                ['model', 'scope'],
                't-header'
            );

            // 生成子scope
            $headerScope = $scope . '-header';

            // 设置表头上下文
            $headerContext = array_merge($inheritedAttributes, [
                'type' => 't-header',
                'scope' => $headerScope,
                'sortable' => $sortable,
                'draggable' => $draggable,
                'configurable' => $configurable,
                'resizable' => $resizable
            ]);

            TableContext::pushChildTag('t-header', $headerScope, $headerContext);

            $content = $tag_data[2] ?? '';

            // 如果没有手动配置字段，尝试自动生成
            if (empty(trim($content))) {
                $content = self::generateDefaultHeaderFields($model, $headerScope, $inheritedAttributes);
            }

            // 生成表头HTML
            $result = self::generateHeaderHtml($model, $headerScope, $content, $headerContext);

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
     * 生成默认的表头字段
     * @param string $model 模型类名
     * @param string $scope 作用域
     * @param array $context 上下文
     * @return string 字段HTML
     */
    private static function generateDefaultHeaderFields(string $model, string $scope, array $context): string
    {
        try {
            // 尝试实例化模型获取字段信息
            $modelInstance = w_obj($model);
            $fields = [];

            // 获取字段信息
            $columns = $modelInstance->columns();
            if (!empty($columns) && is_array($columns)) {
                foreach ($columns as $column) {
                    $fieldName = is_array($column) ? ($column['Field'] ?? $column['field'] ?? '') : $column;
                    if (empty($fieldName)) {
                        continue;
                    }
                    $fields[] = [
                        'name' => $fieldName,
                        'label' => (is_array($column) && isset($column['Comment'])) ? $column['Comment'] : $fieldName,
                        'type' => (is_array($column) && isset($column['Type'])) ? $column['Type'] : 'string',
                        'sortable' => true,
                        'width' => self::getDefaultWidth($fieldName)
                    ];
                }
            } else {
                // 使用默认字段
                $defaultFields = ['id', 'name', 'status', 'created_at'];
                foreach ($defaultFields as $fieldName) {
                    $fields[] = [
                        'name' => $fieldName,
                        'label' => ucfirst($fieldName),
                        'sortable' => true,
                        'width' => self::getDefaultWidth($fieldName)
                    ];
                }
            }

            // 限制字段数量
            $fields = array_slice($fields, 0, 6);

            // 生成字段HTML
            $fieldsHtml = '';
            foreach ($fields as $field) {
                $sortableAttr = $field['sortable'] ? 'sortable="true"' : '';
                $fieldsHtml .= "<w:field belong=\"t-header\" name=\"{$field['name']}\" {$sortableAttr} width=\"{$field['width']}\">{$field['label']}</w:field>\n";
            }

            return $fieldsHtml;

        } catch (\Exception $e) {
            error_log("TableHeader: 自动生成字段失败: " . $e->getMessage());
            return '<w:field belong="t-header" name="id" sortable="true" width="80">ID</w:field>';
        }
    }

    /**
     * 生成表头HTML
     * @param string $model 模型类名
     * @param string $scope 作用域
     * @param string $content 内容
     * @param array $context 上下文
     * @return string HTML
     */
    private static function generateHeaderHtml(string $model, string $scope, string $content, array $context): string
    {
        $sortableJs = $context['sortable'] ? 'true' : 'false';
        $draggableJs = $context['draggable'] ? 'true' : 'false';
        $configurableJs = $context['configurable'] ? 'true' : 'false';
        $resizableJs = $context['resizable'] ? 'true' : 'false';
        
        // HTML 属性转义
        $modelHtml = htmlspecialchars($model ?? '', ENT_QUOTES, 'UTF-8');
        $scopeHtml = htmlspecialchars($scope ?? '', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<thead class="datatable-header"
       data-model="{$modelHtml}"
       data-scope="{$scopeHtml}"
       data-sortable="{$sortableJs}"
       data-draggable="{$draggableJs}"
       data-configurable="{$configurableJs}"
       data-resizable="{$resizableJs}">
    <tr class="datatable-header-row">
        {$content}
    </tr>
</thead>
HTML;
    }

    /**
     * 获取默认字段宽度
     * @param string $fieldName 字段名
     * @return string 宽度
     */
    private static function getDefaultWidth(string $fieldName): string
    {
        $widthMap = [
            'id' => '80',
            'status' => '100',
            'created_at' => '150',
            'updated_at' => '150',
            'name' => '200',
            'title' => '200',
            'email' => '180',
            'phone' => '120'
        ];

        return $widthMap[$fieldName] ?? '150';
    }

    public static function document(): string
    {
        return <<<DOC
表格头部组件使用方式：

基础用法（从父标签继承model和scope）：
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-header>
        <w:field name="id" sortable="true" width="80">ID</w:field>
        <w:field name="name" sortable="true" width="200">名称</w:field>
        <w:field name="status" sortable="true" width="100">状态</w:field>
    </w:t-header>
</w:d-table>

高级用法（手动指定model和scope）：
<w:t-header scope="custom-header-scope" model="Weline\Demo\Model\Demo" sortable="true" draggable="true">
    <w:field name="id" sortable="true" width="80">ID</w:field>
    <w:field name="name" sortable="true" width="200">名称</w:field>
    <w:field name="status" sortable="true" width="100">状态</w:field>
</w:t-header>

属性说明：
scope: 可选，指定数据作用域（自动生成：{table-scope}-header）
model: 可选，指定数据模型类名（可从父标签继承）
sortable: 可选，是否启用排序，默认true
draggable: 可选，是否启用拖拽，默认true
configurable: 可选，是否启用配置，默认true
resizable: 可选，是否启用列宽调整，默认true

注意：
1. 当在w:d-table内部使用时，model和scope属性会自动从父标签继承
2. scope会自动生成为"{table-scope}-header"格式
3. 无需手动指定model和scope，除非需要覆盖继承的值
DOC;
    }
}