<?php

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\TableContext;
use Weline\Framework\App\Exception;
use Weline\Taglib\TaglibInterface;

class TableFooter implements TaglibInterface
{
    public static function name(): string
    {
        return 't-footer';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return [
            'scope' => false,
            'model' => false,
            'show-pagination' => false,
            'show-summary' => false,
            'show-actions' => false
        ];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function parent(): ?string
    {
        return 'd-table';
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $scope = $attributes['scope'] ?? '';
            $model = $attributes['model'] ?? '';
            $showPagination = filter_var($attributes['show-pagination'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $showSummary = filter_var($attributes['show-summary'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $showActions = filter_var($attributes['show-actions'] ?? true, FILTER_VALIDATE_BOOLEAN);

            $inheritedAttributes = TableContext::inheritTableAttributes(
                $attributes, 
                $scope, 
                ['model', 'scope', 'show-pagination']
            );

            $model = $inheritedAttributes['model'] ?? $model;
            $scope = $inheritedAttributes['scope'] ?? $scope;

            TableContext::validateRequiredAttributes(
                ['model' => $model, 'scope' => $scope], 
                ['model', 'scope'], 
                't-footer'
            );

            $scope = $scope . '-footer';
            TableContext::pushChildTag('t-footer', $scope, $inheritedAttributes);

            $content = $tag_data[2] ?? '';

            $showSummaryDisplay = $showSummary ? 'block' : 'none';
            $showPaginationDisplay = $showPagination ? 'block' : 'none';
            $showPaginationJs = $showPagination ? 'true' : 'false';
            $showSummaryJs = $showSummary ? 'true' : 'false';
            $showActionsJs = $showActions ? 'true' : 'false';

            // 修复模型名称的转义问题
            $modelJs = str_replace('\\', '\\\\', $model);

            $result = <<<HTML
<tfoot class="datatable-footer" data-model="{$model}" data-scope="{$scope}">
    <tr>
        <td colspan="100%">
            <div class="datatable-footer-content">
                <div class="datatable-summary" style="display: {$showSummaryDisplay};">
                    <span class="datatable-summary-text">共 {{total}} 条记录</span>
                </div>
                
                <div class="datatable-footer-center">
                    {$content}
                </div>
                
                <div class="datatable-pagination" style="display: {$showPaginationDisplay};">
                    <nav aria-label="分页导航">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item">
                                <button class="page-link" onclick="DataTableManager.goToPage('{$scope}', 1)">
                                    <i class="fas fa-angle-double-left"></i>
                                </button>
                            </li>
                            <li class="page-item">
                                <button class="page-link" onclick="DataTableManager.goToPage('{$scope}', 'prev')">
                                    <i class="fas fa-angle-left"></i>
                                </button>
                            </li>
                            <li class="page-item">
                                <span class="page-link" id="page-info-{$scope}">第 1 页，共 1 页</span>
                            </li>
                            <li class="page-item">
                                <button class="page-link" onclick="DataTableManager.goToPage('{$scope}', 'next')">
                                    <i class="fas fa-angle-right"></i>
                                </button>
                            </li>
                            <li class="page-item">
                                <button class="page-link" onclick="DataTableManager.goToPage('{$scope}', 'last')">
                                    <i class="fas fa-angle-double-right"></i>
                                </button>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </td>
    </tr>
</tfoot>
<script>
$(function() {
    if (window.DataTableManager) {
        window.DataTableManager.initFooter('{$scope}', {
            model: '{$modelJs}',
            scope: '{$scope}',
            showPagination: {$showPaginationJs},
            showSummary: {$showSummaryJs},
            showActions: {$showActionsJs}
        });
    }
});
</script>
HTML;

            return $result;
        };
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    public static function document(): string
    {
        return <<<DOC
表格底部组件使用方式：

基础用法：
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-footer>
        <div class="text-muted">
            <small>数据更新时间：{{update_time}}</small>
        </div>
    </w:t-footer>
</w:d-table>

属性说明：
scope: 可选，指定数据作用域（自动生成：{table-scope}-footer）
model: 可选，指定数据模型类名（可从父标签继承）
show-pagination: 可选，是否显示分页，默认true
show-summary: 可选，是否显示数据汇总，默认true
show-actions: 可选，是否显示操作按钮，默认true
DOC;
    }
}
