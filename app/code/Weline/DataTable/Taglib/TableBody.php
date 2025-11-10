<?php

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\TableContext;
use Weline\Framework\App\Exception;
use Weline\Taglib\TaglibInterface;

class TableBody implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 't-body';
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
            'editable' => false,
            'inline-edit' => false,
            'modal-edit' => false,
            'selectable' => false,
            'multi-select' => false,
            'row-actions' => false,
            'empty-text' => false,
            'loading-text' => false
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
                    return '<!-- DataTable 表体标签只能在后端使用，当前为前端请求 -->';
                }
                return '';
            }
            
            $scope = $attributes['scope'] ?? '';
            $model = $attributes['model'] ?? '';
            $editable = filter_var($attributes['editable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $inheritedAttributes = TableContext::inheritTableAttributes($attributes, $scope, ['model', 'scope', 'editable', 'inline-edit', 'modal-edit']);
            $model = $inheritedAttributes['model'] ?? $model;
            $scope = $inheritedAttributes['scope'] ?? $scope;
            if (isset($inheritedAttributes['editable'])) {
                $editable = filter_var($inheritedAttributes['editable'], FILTER_VALIDATE_BOOLEAN);
            }
            TableContext::validateRequiredAttributes(['model' => $model, 'scope' => $scope], ['model', 'scope'], 't-body');
            $scope = $scope . '-body';
            TableContext::pushChildTag('t-body', $scope, $inheritedAttributes);
            $content = $tag_data[2] ?? '';
            
            // HTML 属性转义
            $modelHtml = htmlspecialchars($model ?? '', ENT_QUOTES, 'UTF-8');
            $scopeHtml = htmlspecialchars($scope ?? '', ENT_QUOTES, 'UTF-8');
            
            $result = <<<HTML
<tbody class="datatable-body" data-model="{$modelHtml}" data-scope="{$scopeHtml}">
    {$content}
</tbody>
HTML;
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

    public static function document(): string
    {
        return <<<DOC
表格主体组件使用方式：

基础用法（从父标签继承model和scope）：
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-body>
        <!-- 数据行模板 -->
        <w:field name="id" width="80">#{{id}}</w:field>
        <w:field name="name" width="200">#{{name}}</w:field>
        <w:field name="status" width="100">#{{status}}</w:field>
        <w:field name="actions" width="150">
            <button class="btn btn-sm btn-primary" onclick="editRow({{id}})">编辑</button>
            <button class="btn btn-sm btn-danger" onclick="deleteRow({{id}})">删除</button>
        </w:field>
    </w:t-body>
</w:d-table>

高级用法（手动指定属性）：
<w:t-body scope="custom-body-scope" model="Weline\Demo\Model\Demo" 
          editable="true" inline-edit="true" selectable="true" multi-select="true">
    <!-- 数据行模板 -->
    <w:field name="id" width="80">#{{id}}</w:field>
    <w:field name="name" width="200" editable="true">#{{name}}</w:field>
    <w:field name="status" width="100" type="select" options="1:启用,0:禁用">#{{status}}</w:field>
</w:t-body>

属性说明：
scope: 可选，指定数据作用域（自动生成：{table-scope}-body）
model: 可选，指定数据模型类名（可从父标签继承）
editable: 可选，是否启用编辑功能，默认true
inline-edit: 可选，是否启用行内编辑，默认true
modal-edit: 可选，是否启用弹窗编辑，默认true
selectable: 可选，是否启用行选择，默认true
multi-select: 可选，是否启用多选，默认true
row-actions: 可选，是否显示行操作按钮，默认true
empty-text: 可选，空数据提示文本，默认"暂无数据"
loading-text: 可选，加载中提示文本，默认"加载中..."

注意：
1. 当在w:d-table内部使用时，model和scope属性会自动从父标签继承
2. scope会自动生成为"{table-scope}-body"格式
3. 无需手动指定model和scope，除非需要覆盖继承的值
4. t-body标签主要用于定义数据行的模板结构
DOC;
    }
}
