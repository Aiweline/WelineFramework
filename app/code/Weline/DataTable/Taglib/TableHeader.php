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
            $scope = $attributes['scope'] ?? '';
            $model = $attributes['model'] ?? '';
            $sortable = filter_var($attributes['sortable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $draggable = filter_var($attributes['draggable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $configurable = filter_var($attributes['configurable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $resizable = filter_var($attributes['resizable'] ?? true, FILTER_VALIDATE_BOOLEAN);

            // 使用TableContext助手类继承表格属性
            $inheritedAttributes = TableContext::inheritTableAttributes(
                $attributes, 
                $scope, 
                ['model', 'scope', 'sortable']
            );


            // 更新变量值
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

            $scope = $scope . '-header';

            // 推入表头标签到渲染栈
            TableContext::pushChildTag('t-header', $scope, $inheritedAttributes);

            $content = $tag_data[2] ?? '';

            // 转换为JavaScript布尔值
            $sortableJs = $sortable ? 'true' : 'false';
            $draggableJs = $draggable ? 'true' : 'false';
            $configurableJs = $configurable ? 'true' : 'false';
            $resizableJs = $resizable ? 'true' : 'false';

            // 修复模型名称的转义问题
            $modelJs = str_replace('\\', '\\\\', $model);

            $result = <<<HTML
<thead>
    <tr class="datatable-header-row" data-model="{$model}" data-scope="{$scope}">
        {$content}
    </tr>
</thead>
<script>
$(function() {
    // 初始化表头配置
    if (window.DataTableManager) {
        window.DataTableManager.initHeader('{$scope}', {
            model: '{$modelJs}',
            scope: '{$scope}',
            sortable: {$sortableJs},
            draggable: {$draggableJs},
            configurable: {$configurableJs},
            resizable: {$resizableJs}
        });
    }
});
</script>
HTML;

            // 渲染结束后弹出表头标签
            // TableContext::popTag();
            
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