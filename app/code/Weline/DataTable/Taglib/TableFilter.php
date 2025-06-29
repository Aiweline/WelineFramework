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
            $scope = $attributes['scope'] ?? '';
            $model = $attributes['model'] ?? '';
            $searchable = filter_var($attributes['searchable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $advanced = filter_var($attributes['advanced'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $collapsible = filter_var($attributes['collapsible'] ?? true, FILTER_VALIDATE_BOOLEAN);

            // 使用TableContext助手类继承表格属性
            $inheritedAttributes = TableContext::inheritTableAttributes(
                $attributes, 
                $scope, 
                ['model', 'scope', 'searchable']
            );

            // 更新变量值
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

            $scope = $scope . '-filter';

            // 推入过滤器标签到渲染栈
            TableContext::pushChildTag('t-filter', $scope, $inheritedAttributes);

            $content = $tag_data[2] ?? '';

            // 转换为JavaScript布尔值
            $searchableJs = $searchable ? 'true' : 'false';
            $advancedJs = $advanced ? 'true' : 'false';
            $collapsibleJs = $collapsible ? 'true' : 'false';

            // 修复模型名称的转义问题
            $modelJs = str_replace('\\', '\\\\', $model);

            // 提前计算高级按钮HTML
            $advancedButton = $advanced ? '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="DataTableManager.toggleAdvancedFilter(\'' . $scope . '\')"><i class="fas fa-cog"></i> 高级</button>' : '';

            $result = <<<HTML
<div class="datatable-filter-container" data-model="{$model}" data-scope="{$scope}">
    <div class="datatable-filter-toolbar">
        <div class="datatable-filter-left">
            <div class="datatable-filter-form">
                {$content}
            </div>
        </div>
        <div class="datatable-filter-right">
            <button type="button" class="btn btn-primary btn-sm" onclick="DataTableManager.search('{$scope}')">
                <i class="fas fa-search"></i> <lang>搜索</lang>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="DataTableManager.resetFilter('{$scope}')">
                <i class="fas fa-undo"></i> <lang>重置</lang>
            </button>
            {$advancedButton}
        </div>
    </div>
    <script>
    $(function() {
        // 初始化过滤器配置
        if (window.DataTableManager) {
            window.DataTableManager.initFilter('{$scope}', {
                model: '{$modelJs}',
                scope: '{$scope}',
                searchable: {$searchableJs},
                advanced: {$advancedJs},
                collapsible: {$collapsibleJs}
            });
        }
    });
    </script>
</div>
HTML;

            // 渲染结束后弹出过滤器标签
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