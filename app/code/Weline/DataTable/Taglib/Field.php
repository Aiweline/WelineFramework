<?php

namespace Weline\DataTable\Taglib;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Model;
use Weline\Taglib\TaglibInterface;
use Weline\DataTable\Helper\TableContext;

class Field implements TaglibInterface
{
    const default_sortable = false;

    static public function name(): string
    {
        return 'field';
    }

    static function tag(): bool
    {
        return true;
    }

    static function attr(): array
    {
        return [
            'name' => true,
            'belong' => true,
            'sortable' => false,
            'url' => false,
            'multi' => false,
            'icon' => false,
            'width' => false,
            'min-width' => false,
            'max-width' => false,
            'resizable' => false,
            'visible' => false,
            'editable' => false,
            'searchable' => false,
            'type' => false,
            'placeholder' => false,
            'options' => false,
            'class' => false,
            'style' => false,
            'formatter' => false,
            'validator' => false,
            'default' => false
        ];
    }

    static function tag_start(): bool
    {
        return false;
    }

    static function tag_end(): bool
    {
        return false;
    }

    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attrs) {
            // 检查是否为后端请求
            /** @var Request $req */
            $req = ObjectManager::getInstance(Request::class);
            if (!$req->isBackend() && !$req->isApiBackend()) {
                // 前端请求直接返回空（开发环境返回注释说明）
                if (defined('DEV') && DEV) {
                    $name = $attrs['name'] ?? 'unknown';
                    return "<!-- DataTable 字段标签只能在后端使用，当前为前端请求（字段：{$name}） -->";
                }
                return '';
            }

            $name = $attrs['name'] ?? '';
            $belong = $attrs['belong'] ?? '';

            // 验证belong属性
            if (empty($belong)) {
                throw new Exception(__('field标签（字段：%{1}）缺少必填属性belong！请指定belong="t-header"、belong="t-filter"或belong="d-form"。', [$name]));
            }

            if (!in_array($belong, ['t-header', 't-filter', 'd-form'])) {
                throw new Exception(__('field标签（字段：%{1}）的belong属性值无效：%{2}。有效值：t-header、t-filter 或 d-form。', [$name, $belong]));
            }

            // 临时解决方案：如果没有上下文，直接返回占位符
            // 这样可以避免标签解析顺序导致的问题
            try {
            
            $multi = boolval($attrs['multi'] ?? false);
            $sortable = boolval($attrs['sortable'] ?? self::default_sortable);
            $width = $attrs['width'] ?? '';
            $minWidth = $attrs['min-width'] ?? '';
            $maxWidth = $attrs['max-width'] ?? '';
            $resizable = boolval($attrs['resizable'] ?? true);
            $visible = boolval($attrs['visible'] ?? true);
            $editable = boolval($attrs['editable'] ?? false);
            $searchable = boolval($attrs['searchable'] ?? true);
            $type = $attrs['type'] ?? 'text';
            $placeholder = $attrs['placeholder'] ?? '';
            $options = $attrs['options'] ?? '';
            $class = $attrs['class'] ?? '';
            $style = $attrs['style'] ?? '';
            $formatter = $attrs['formatter'] ?? '';
            $validator = $attrs['validator'] ?? '';
            $default = $attrs['default'] ?? '';

            // 获取当前上下文信息
            $context = TableContext::getRenderStack($belong);
            if (empty($context)) {
                // 尝试从全局上下文中获取最近的表格上下文
                $tableContext = TableContext::getCurrentTableContext();
                if (empty($tableContext)) {
                    // 最后尝试从所有表格上下文中查找匹配的scope
                    $allContexts = TableContext::getAllTableContexts();
                    if (!empty($allContexts)) {
                        // 使用第一个可用的表格上下文
                        $tableContext = reset($allContexts);
                    }
                }

                if (empty($tableContext)) {
                    // 调试信息
                    $debugInfo = [
                        'field_name' => $name,
                        'belong' => $belong,
                        'render_stack' => TableContext::getRenderStack(),
                        'all_contexts' => TableContext::getAllTableContexts()
                    ];
                    error_log('DataTable Field Debug: ' . json_encode($debugInfo, JSON_UNESCAPED_UNICODE));

                    // 在没有上下文的情况下，返回一个占位符，而不是抛出异常
                    // 这样可以避免标签解析顺序导致的问题
                    return "<!-- Field '{$name}' waiting for context -->";
                }

                // 创建一个临时上下文
                $context = [
                    'type' => $belong,
                    'scope' => $tableContext['scope'] . '-' . str_replace('d-', '', $belong),
                    'attributes' => $tableContext
                ];
            }
            $parentTagType = $context['type'];
            $parentAttributes = $context['attributes'];
            $tableContext = TableContext::getTableContext($parentAttributes['scope']);
            
            // 验证belong属性与父标签类型是否匹配
            if ($belong !== $parentTagType) {
                throw new Exception(__('field标签（字段：%{1}）的belong属性值"%{2}"与父标签类型"%{3}"不匹配！请确保belong属性值与父标签类型一致。', [$name, $belong, $parentTagType]));
            }

            // 根据上下文验证字段和参数
            self::validateFieldByContext($name, $belong, $parentAttributes, $tableContext, $attrs);

            // 记录模板中定义的字段
            $scope = $parentAttributes['scope'] ?? '';
            if ($scope) {
                $fieldConfig = [
                    'name' => $name,
                    'belong' => $belong,
                    'sortable' => $sortable,
                    'width' => $width,
                    'min_width' => $minWidth,
                    'max_width' => $maxWidth,
                    'resizable' => $resizable,
                    'visible' => $visible,
                    'editable' => $editable,
                    'searchable' => $searchable,
                    'type' => $type,
                    'placeholder' => $placeholder,
                    'options' => $options,
                    'class' => $class,
                    'style' => $style,
                    'formatter' => $formatter,
                    'validator' => $validator,
                    'default' => $default,
                    'template_defined' => true,
                    'content' => $tag_data[2] ?? $name
                ];
                
                // 记录到TableContext中
                self::recordTemplateField($scope, $belong, $name, $fieldConfig);
            }

            // 构建样式
            $styleStr = $style;
            if ($width) $styleStr .= "width: {$width};";
            if ($minWidth) $styleStr .= "min-width: {$minWidth};";
            if ($maxWidth) $styleStr .= "max-width: {$maxWidth};";

            // 构建属性字符串
            $attrStr = '';
            $attrStr .= " data-belong=\"{$belong}\"";
            if ($class) $attrStr .= " class=\"{$class}\"";
            if ($styleStr) $attrStr .= " style=\"{$styleStr}\"";
            if (!$visible) $attrStr .= " data-visible=\"false\"";
            if ($editable) $attrStr .= " data-editable=\"true\"";
            if ($searchable) $attrStr .= " data-searchable=\"true\"";
            if ($resizable) $attrStr .= " data-resizable=\"true\"";
            if ($sortable) $attrStr .= " data-sortable=\"true\"";
            if ($formatter) $attrStr .= " data-formatter=\"{$formatter}\"";
            // 新增：序列化所有属性到data-w-field
            $fieldData = [
                'name' => $name,
                'belong' => $belong,
                'sortable' => $sortable,
                'width' => $width,
                'min_width' => $minWidth,
                'max_width' => $maxWidth,
                'resizable' => $resizable,
                'visible' => $visible,
                'editable' => $editable,
                'searchable' => $searchable,
                'type' => $type,
                'placeholder' => $placeholder,
                'options' => $options,
                'class' => $class,
                'style' => $style,
                'formatter' => $formatter,
                'validator' => $validator,
                'default' => $default,
                'template_defined' => true,
                'content' => $tag_data[2] ?? $name
            ];
            $attrStr .= " data-w-field='" . htmlspecialchars(json_encode($fieldData), ENT_QUOTES) . "'";

            // 新增：每个属性都输出为data-xxx="..."
            foreach ($fieldData as $k => $v) {
                if (is_bool($v)) $v = $v ? 'true' : 'false';
                if ($v !== '' && $v !== null) {
                    $attrStr .= ' data-' . str_replace('_', '-', strtolower($k)) . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
                }
            }

            $content = $tag_data[2] ?? $name;

            // 根据belong属性渲染不同的字段
            if ($belong === 't-header') {
                // 表格头部字段
                return self::renderTableHeaderField($name, $content, $sortable, $req, $attrStr, $placeholder);
            } elseif ($belong === 't-filter') {
                // 过滤器字段
                return self::renderFilterField($name, $type, $placeholder, $options, $class, $attrStr);
            } elseif ($belong === 'd-form') {
                // 表单字段
                return self::renderFormField($name, $type, $placeholder, $options, $class, $attrStr, $content);
            } else {
                // 默认表格头部字段
                return self::renderTableHeaderField($name, $content, $sortable, $req, $attrStr, $placeholder);
            }

            } catch (Exception $e) {
                // 如果出现上下文相关的错误，返回占位符
                if (strpos($e->getMessage(), '必须位于d-table标签内') !== false) {
                    return "<!-- Field '{$name}' waiting for context: {$e->getMessage()} -->";
                }
                // 其他错误继续抛出
                throw $e;
            }
        };
    }

    /**
     * 记录模板中定义的字段到TableContext
     * @param string $scope 表格作用域
     * @param string $belong 字段所属类型
     * @param string $fieldName 字段名称
     * @param array $fieldConfig 字段配置
     */
    private static function recordTemplateField(string $scope, string $belong, string $fieldName, array $fieldConfig): void
    {
        // 使用静态数组来存储模板字段配置
        static $templateFields = [];
        
        if (!isset($templateFields[$scope])) {
            $templateFields[$scope] = [
                't-header' => [],
                't-filter' => []
            ];
        }
        
        $templateFields[$scope][$belong][$fieldName] = $fieldConfig;
        
        // 将配置存储到TableContext中（如果TableContext支持的话）
        if (method_exists(TableContext::class, 'recordTemplateField')) {
            TableContext::recordTemplateField($scope, $belong, $fieldName, $fieldConfig);
        }
    }

    /**
     * 根据上下文验证字段和参数
     * @param string $fieldName 字段名称
     * @param string $parentTagType 父标签类型
     * @param array $parentAttributes 父标签属性
     * @param array|null $tableContext 表格上下文
     * @param array $fieldAttributes 字段属性
     * @throws Exception 当验证失败时抛出异常
     */
    private static function validateFieldByContext(string $fieldName, string $parentTagType, array $parentAttributes, ?array $tableContext, array $fieldAttributes): void
    {
        // 使用TableContext助手类继承表格属性
        $inheritedAttributes = TableContext::inheritTableAttributes(
            $parentAttributes, 
            $parentAttributes['scope'] ?? '', 
            ['model', 'scope']
        );

        // 获取model类名
        $modelClass = $inheritedAttributes['model'] ?? '';
        
        // 检查是否有转义问题并修复
        if (strpos($modelClass, '\\\\') !== false) {
            // 修复转义问题：将双反斜杠替换为单反斜杠
            $modelClass = str_replace('\\\\', '\\', $modelClass);
        }

        // 如果从父标签没有获取到model，尝试从表格上下文获取
        if (empty($modelClass) && $tableContext) {
            $modelClass = $tableContext['model'] ?? '';
        }

        // 验证必需的属性
        try {
            TableContext::validateRequiredAttributes(
                ['model' => $modelClass, 'scope' => $inheritedAttributes['scope'] ?? ''], 
                ['model'], 
                'field'
            );
        } catch (Exception $e) {
            // 提供更具体的错误信息
            throw new Exception(__('field标签（字段：%{1}）配置错误：%{2}。请确保在父标签（t-header或t-filter）中正确设置了model属性，或确保field标签位于d-table标签内。', [$fieldName, $e->getMessage()]));
        }
        

        // 验证字段是否在model中存在
        self::validateFieldExists($fieldName, $modelClass);

        // 根据上下文验证特定参数
        if ($parentTagType === 't-header') {
            // 确保在表格头部上下文中进行验证
            self::validateHeaderFieldAttributes($fieldAttributes);
        } elseif ($parentTagType === 't-filter') {
            // 确保在过滤器上下文中进行验证
            self::validateFilterFieldAttributes($fieldAttributes);
        } else {
            // 如果无法确定上下文，使用默认的表格头部验证
            // 这种情况通常发生在field标签没有明确的父标签时
            self::validateHeaderFieldAttributes($fieldAttributes);
        }
    }

    /**
     * 验证字段是否在model中存在
     * @param string $fieldName 字段名称
     * @param string $modelClass model类名
     * @throws Exception 当字段不存在时抛出异常
     */
    private static function validateFieldExists(string $fieldName, string $modelClass): void
    {
        // 首先检查类是否存在
        if (!class_exists($modelClass)) {
            throw new Exception(__('field标签验证失败：Model类"%{1}"不存在！请检查类名是否正确，确保Model类已正确加载。常见Model类：Weline\Demo\Model\Demo、WeShop\Product\Model\Product等。', [$modelClass]));
        }

        try {
            // 实例化model
            /** @var Model $model */
            $model = ObjectManager::getInstance($modelClass);

            
            // 获取model的字段列表
            $modelFields = $model->getModelFields();

            
            // 检查字段是否存在
            if (!in_array($fieldName, $modelFields)) {
                throw new Exception(__('field标签（字段：%{1}）在Model类"%{2}"中不存在！可用字段：%{3}。请检查字段名称是否正确，或确认该字段在Model中已定义。', [
                    $fieldName, 
                    $modelClass, 
                    implode(', ', $modelFields)
                ]));
            }
        } catch (\Exception $e) {
            // 如果model实例化失败，抛出更友好的错误信息
            if ($e instanceof Exception) {
                throw $e;
            }
            throw new Exception(__('field标签验证失败：实例化Model类"%{1}"时发生错误：%{2}。请检查Model类是否正确配置。', [$modelClass, $e->getMessage()]));
        }
    }

    /**
     * 验证表格头部字段的属性
     * @param array $attributes 字段属性
     * @throws Exception 当验证失败时抛出异常
     */
    private static function validateHeaderFieldAttributes(array $attributes): void
    {
        $fieldName = $attributes['name'] ?? 'unknown';
        
        // 表格头部字段的验证规则
        $type = $attributes['type'] ?? '';
        if ($type && !in_array($type, ['text', 'number', 'date', 'select', 'checkbox'])) {
            throw new Exception(__('field标签（字段：%{1}）在t-header上下文中不支持type属性，或type值无效：%{2}。在t-header中，field标签主要用于定义表格列，不需要指定type属性。', [$fieldName, $type]));
        }

        // 检查过滤器特有的属性（只有options是过滤器特有的）
        $filterOnlyAttrs = ['options'];
        foreach ($filterOnlyAttrs as $attr) {
            if (isset($attributes[$attr])) {
                $attrValue = $attributes[$attr];
                $suggestion = '';
                
                // 如果是options属性，提供更具体的建议
                if ($attr === 'options') {
                    $suggestion = '。如果您需要为字段"'.$fieldName.'"创建下拉选择器，请将该field标签移动到t-filter标签内，例如：<w:t-filter><w:field name="'.$fieldName.'" type="select" options="'.$attrValue.'">'.$fieldName.'</w:field></w:t-filter>';
                }
                
                throw new Exception(__('field标签（字段：%{1}）在t-header上下文中不支持%{2}属性！该属性仅用于t-filter中的过滤器字段。请将%{2}属性移除，或将该field标签移动到t-filter标签内。%{3}', [$fieldName, $attr, $suggestion]));
            }
        }
    }

    /**
     * 验证过滤器字段的属性
     * @param array $attributes 字段属性
     * @throws Exception 当验证失败时抛出异常
     */
    private static function validateFilterFieldAttributes(array $attributes): void
    {
        $fieldName = $attributes['name'] ?? 'unknown';
        $type = $attributes['type'] ?? 'text';
        
        // 验证type值
        $validTypes = [
            'text', 'select', 'date', 'datetime', 'number', 'checkbox', 'radio',
            'email', 'tel', 'url', 'password', 'search', 'range', 'color',
            'time', 'month', 'week', 'file', 'hidden'
        ];
        if (!in_array($type, $validTypes)) {
            throw new Exception(__('field标签（字段：%{1}）在t-filter上下文中，type属性值无效：%{2}。有效值：%{3}。请检查type属性值是否正确。', [
                $fieldName,
                $type,
                implode(', ', $validTypes)
            ]));
        }

        // 根据type验证特定属性
        if ($type === 'select') {
            $options = $attributes['options'] ?? '';
            if (empty($options)) {
                throw new Exception(__('field标签（字段：%{1}）在t-filter上下文中，select类型的字段必须指定options属性！请添加options属性，格式：value:label,value2:label2。', [$fieldName]));
            }
            
            // 验证options格式
            $optionPairs = explode(',', $options);
            foreach ($optionPairs as $pair) {
                $parts = explode(':', $pair);
                if (count($parts) !== 2) {
                    throw new Exception(__('field标签（字段：%{1}）在t-filter上下文中，select类型的options属性格式错误：%{2}。正确格式：value:label,value2:label2。请检查options属性格式。', [$fieldName, $pair]));
                }
            }
        }

        // 检查表格头部特有的属性
        $headerOnlyAttrs = ['sortable', 'width', 'min-width', 'max-width', 'resizable', 'formatter'];
        foreach ($headerOnlyAttrs as $attr) {
            if (isset($attributes[$attr])) {
                $attrValue = $attributes[$attr];
                $suggestion = '';
                
                // 如果是sortable属性，提供更具体的建议
                if ($attr === 'sortable') {
                    $suggestion = '。如果您需要为字段"'.$fieldName.'"启用排序功能，请将该field标签移动到t-header标签内，例如：<w:t-header><w:field name="'.$fieldName.'" sortable="true">'.$fieldName.'</w:field></w:t-header>';
                } elseif (in_array($attr, ['width', 'min-width', 'max-width', 'resizable'])) {
                    $suggestion = '。如果您需要为字段"'.$fieldName.'"设置列宽相关属性，请将该field标签移动到t-header标签内。';
                } elseif ($attr === 'formatter') {
                    $suggestion = '。如果您需要为字段"'.$fieldName.'"设置格式化函数，请将该field标签移动到t-header标签内。';
                }
                
                throw new Exception(__('field标签（字段：%{1}）在t-filter上下文中不支持%{2}属性！该属性仅用于t-header中的表格头部字段。请将%{2}属性移除，或将该field标签移动到t-header标签内。%{3}', [$fieldName, $attr, $suggestion]));
            }
        }
    }

    /**
     * 渲染表格头部字段
     */
    private static function renderTableHeaderField($name, $content, $sortable, $req, $attrStr, $placeholder = '')
    {
        $sort_name = 'sort.' . $name;
        $current = $req->getGet('current', '');
        $current_sort_name = $current ? 'sort.' . $current : '';
        $order = $current_sort_name ? strtolower($req->getGet($current_sort_name, 'desc')) : 'desc';

        # 获取所有排序
        $sorts = $req->getGetByPre('sort.');
        // 默认非多字段排序
        $multi = false;
        if (!$multi) {
            foreach ($sorts as $key => $sort) {
                if ($key != $current) {
                    $sorts['sort.'.$key] = '';
                }else{
                    $sorts['sort.'.$key] = $sort;
                }
                unset($sorts[$key]);
            }
        }

        # 当前字段可排序时显示排序图标
        $icon_str = '';
        $field_active = $sort_name == $current_sort_name;
        if ($sortable) {
            $icon_str = "<i class=\"fa fa-sort{{icon}}\"></i>";
            $icon_status = '';
            if ($order and $field_active) {
                $order = $order == 'asc' ? 'desc' : 'asc';
                $icon_status = $order == 'asc' ? '-down' : '-asc';
            }
            $icon_str = str_replace('{{icon}}', $icon_status, $icon_str);
        }

        $url_params = $_GET;
        $url_params['current'] = $name;
        $url_params = array_merge($url_params, $sorts);
        $url_params['sort.' . $url_params['current']] = $order;

        $url = $req->getUrlBuilder()->getCurrentUrl($url_params, false);
        $url = $req->getUrlBuilder()->extractedUrl($url_params, false, $url);

        // 添加placeholder作为title属性
        $titleAttr = $placeholder ? " title=\"{$placeholder}\"" : '';
        
        $start = <<<DOC
<th data-field="{$name}" data-sort-field="{$sort_name}"{$attrStr}{$titleAttr}>
DOC;
        if ($sortable) {
            $field_active = $field_active ? 'active text-info' : '';
            $start .= "<a href=\"{$url}\" class='{$field_active}'>" . $content . $icon_str . '</a>';
        } else {
            $start .= $content;
        }
        return $start . '</th>';
    }

    /**
     * 渲染过滤器字段
     */
    private static function renderFilterField($name, $type, $placeholder, $options, $class, $attrStr)
    {
        $inputClass = "form-control form-control-sm {$class}";
        $placeholder = $placeholder ?: "请输入{$name}";

        switch ($type) {
            case 'select':
                $optionsHtml = '<option value="">请选择</option>';
                if ($options) {
                    $optionPairs = explode(',', $options);
                    foreach ($optionPairs as $pair) {
                        $parts = explode(':', $pair);
                        if (count($parts) == 2) {
                            $value = trim($parts[0]);
                            $label = trim($parts[1]);
                            $optionsHtml .= "<option value=\"{$value}\">{$label}</option>";
                        }
                    }
                }
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <select class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]" data-field="{$name}" title="{$name}">
        {$optionsHtml}
    </select>
</div>
HTML;

            case 'date':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="date" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]"
           data-field="{$name}" placeholder="{$placeholder}" title="{$name}">
</div>
HTML;

            case 'datetime':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="datetime-local" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]"
           data-field="{$name}" placeholder="{$placeholder}" title="{$name}">
</div>
HTML;

            case 'time':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="time" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]"
           data-field="{$name}" placeholder="{$placeholder}" title="{$name}">
</div>
HTML;

            case 'number':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="number" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]"
           data-field="{$name}" placeholder="{$placeholder}" title="{$name}">
</div>
HTML;

            case 'range':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="range" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]"
           data-field="{$name}" title="{$name}" min="0" max="100">
    <small class="form-text text-muted">{$placeholder}</small>
</div>
HTML;

            case 'email':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="email" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]"
           data-field="{$name}" placeholder="{$placeholder}" title="{$name}">
</div>
HTML;

            case 'tel':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="tel" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]"
           data-field="{$name}" placeholder="{$placeholder}" title="{$name}">
</div>
HTML;

            case 'url':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="url" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]"
           data-field="{$name}" placeholder="{$placeholder}" title="{$name}">
</div>
HTML;

            case 'search':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="search" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]"
           data-field="{$name}" placeholder="{$placeholder}" title="{$name}">
</div>
HTML;

            case 'checkbox':
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <div class="form-check">
        <input type="checkbox" class="form-check-input" id="filter-{$name}" name="filter[{$name}]"
               data-field="{$name}" value="1" title="{$name}">
        <label class="form-check-label" for="filter-{$name}">{$name}</label>
    </div>
</div>
HTML;

            case 'radio':
                $radioOptions = $options ? explode(',', $options) : ['是:1', '否:0'];
                $radioHtml = '';
                foreach ($radioOptions as $option) {
                    $parts = explode(':', $option);
                    $label = trim($parts[0]);
                    $value = isset($parts[1]) ? trim($parts[1]) : $label;
                    $radioHtml .= <<<HTML
                    <div class="form-check form-check-inline">
                        <input type="radio" class="form-check-input" id="filter-{$name}-{$value}"
                               name="filter[{$name}]" value="{$value}" data-field="{$name}">
                        <label class="form-check-label" for="filter-{$name}-{$value}">{$label}</label>
                    </div>
HTML;
                }
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    {$radioHtml}
</div>
HTML;

            default: // text
                return <<<HTML
<div class="filter-field" data-field="{$name}"{$attrStr}>
    <input type="text" class="{$inputClass}" id="filter-{$name}" name="filter[{$name}]" 
           data-field="{$name}" placeholder="{$placeholder}" title="{$name}">
</div>
HTML;
        }
    }

    /**
     * 渲染表单字段
     */
    private static function renderFormField($name, $type, $placeholder, $options, $class, $attrStr, $content)
    {
        $inputClass = "form-control {$class}";
        $label = $content ?: $name;
        $placeholder = $placeholder ?: "请输入{$label}";

        // 情况1：如果内容包含HTML标签，直接返回内容（用户自定义HTML）
        if (strip_tags($content) !== $content) {
            return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    {$content}
</div>
HTML;
        }

        // 情况2：如果没有指定type且内容为空，自动推测字段类型
        if (empty($type) && empty($content)) {
            $type = self::inferFieldType($name);
        }

        // 情况3：如果仍然没有type，使用默认的text类型
        if (empty($type)) {
            $type = 'text';
        }

        switch ($type) {
            case 'textarea':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <textarea class="{$inputClass}" id="form-{$name}" name="{$name}" 
              placeholder="{$placeholder}" rows="3"></textarea>
</div>
HTML;

            case 'select':
                $optionsHtml = '<option value="">请选择</option>';
                if ($options) {
                    $optionPairs = explode(',', $options);
                    foreach ($optionPairs as $pair) {
                        $parts = explode(':', $pair);
                        if (count($parts) == 2) {
                            $value = trim($parts[0]);
                            $label = trim($parts[1]);
                            $optionsHtml .= "<option value=\"{$value}\">{$label}</option>";
                        }
                    }
                }
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <select class="{$inputClass}" id="form-{$name}" name="{$name}">
        {$optionsHtml}
    </select>
</div>
HTML;

            case 'checkbox':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <div class="form-check">
        <input type="checkbox" class="form-check-input" id="form-{$name}" name="{$name}" value="1">
        <label class="form-check-label" for="form-{$name}">{$label}</label>
    </div>
</div>
HTML;

            case 'radio':
                $optionsHtml = '';
                if ($options) {
                    $optionPairs = explode(',', $options);
                    foreach ($optionPairs as $pair) {
                        $parts = explode(':', $pair);
                        if (count($parts) == 2) {
                            $value = trim($parts[0]);
                            $label = trim($parts[1]);
                            $optionsHtml .= <<<HTML
<div class="form-check">
    <input type="radio" class="form-check-input" id="form-{$name}-{$value}" name="{$name}" value="{$value}">
    <label class="form-check-label" for="form-{$name}-{$value}">{$label}</label>
</div>
HTML;
                        }
                    }
                }
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label class="form-label">{$label}</label>
    <div class="form-radio-group">
        {$optionsHtml}
    </div>
</div>
HTML;

            case 'date':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="date" class="{$inputClass}" id="form-{$name}" name="{$name}" 
           placeholder="{$placeholder}">
</div>
HTML;

            case 'datetime':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="datetime-local" class="{$inputClass}" id="form-{$name}" name="{$name}"
           placeholder="{$placeholder}">
</div>
HTML;

            case 'time':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="time" class="{$inputClass}" id="form-{$name}" name="{$name}"
           placeholder="{$placeholder}">
</div>
HTML;

            case 'number':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="number" class="{$inputClass}" id="form-{$name}" name="{$name}"
           placeholder="{$placeholder}">
</div>
HTML;

            case 'range':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="range" class="{$inputClass}" id="form-{$name}" name="{$name}"
           min="0" max="100" step="1">
    <small class="form-text text-muted">{$placeholder}</small>
</div>
HTML;

            case 'color':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="color" class="{$inputClass}" id="form-{$name}" name="{$name}">
    <small class="form-text text-muted">{$placeholder}</small>
</div>
HTML;

            case 'file':
                $accept = $options ? "accept=\"{$options}\"" : '';
                $multiple = strpos($attrStr, 'multiple') !== false ? 'multiple' : '';
                $maxSize = strpos($attrStr, 'max-size') !== false ?
                    preg_replace('/.*max-size="([^"]*)".*/', '$1', $attrStr) : '10MB';

                return <<<HTML
<div class="w-form-field w-file-field" data-field="{$name}"{$attrStr}>
    <label for="field-{$name}" class="w-field-label">{$label}</label>
    <div class="w-field-control">
        <input type="file" id="field-{$name}" name="{$name}" class="w-file-input" {$accept} {$multiple} style="display: none;">

        <div class="w-file-selector">
            <button type="button" class="w-btn w-btn-outline-primary w-file-btn" onclick="DataTableFormManager.triggerFileSelect('field-{$name}')">
                <i class="fas fa-upload"></i> 选择文件
            </button>
            <span class="w-file-info">支持格式：{$options}，最大：{$maxSize}</span>
        </div>

        <div class="w-file-list" id="w-file-list-field-{$name}">
            <div class="w-file-placeholder">未选择文件</div>
        </div>

        <div class="w-upload-progress" id="w-upload-progress-field-{$name}" style="display: none;">
            <div class="w-progress-bar">
                <div class="w-progress-fill" style="width: 0%"></div>
            </div>
            <div class="w-progress-text">0%</div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof DataTableFormManager !== 'undefined') {
        DataTableFormManager.bindFileEvents('field-{$name}', {
            accept: '{$options}',
            multiple: {$multiple} !== '',
            maxSize: '{$maxSize}'
        });
    }
});
</script>
HTML;

            case 'image':
                $accept = $options ?: 'image/*';
                $multiple = strpos($attrStr, 'multiple') !== false ? 'multiple' : '';
                $maxSize = strpos($attrStr, 'max-size') !== false ?
                    preg_replace('/.*max-size="([^"]*)".*/', '$1', $attrStr) : '5MB';

                return <<<HTML
<div class="w-form-field w-image-field" data-field="{$name}"{$attrStr}>
    <label for="field-{$name}" class="w-field-label">{$label}</label>
    <div class="w-field-control">
        <input type="file" id="field-{$name}" name="{$name}" class="w-image-input" accept="{$accept}" {$multiple} style="display: none;">

        <div class="w-image-preview" id="w-image-preview-field-{$name}">
            <div class="w-image-placeholder" onclick="DataTableFormManager.triggerFileSelect('field-{$name}')">
                <i class="fas fa-image"></i>
                <div class="w-placeholder-text">点击选择图片</div>
                <div class="w-placeholder-info">支持格式：JPG、PNG、GIF，最大：{$maxSize}</div>
            </div>
        </div>

        <div class="w-image-actions" style="display: none;">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-primary" onclick="DataTableFormManager.triggerFileSelect('field-{$name}')">
                <i class="fas fa-edit"></i> 更换
            </button>
            <button type="button" class="w-btn w-btn-sm w-btn-outline-danger" onclick="DataTableFormManager.clearImageField('field-{$name}')">
                <i class="fas fa-trash"></i> 删除
            </button>
        </div>

        <div class="w-upload-progress" id="w-upload-progress-field-{$name}" style="display: none;">
            <div class="w-progress-bar">
                <div class="w-progress-fill" style="width: 0%"></div>
            </div>
            <div class="w-progress-text">0%</div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof DataTableFormManager !== 'undefined') {
        DataTableFormManager.bindImageEvents('field-{$name}', {
            accept: '{$accept}',
            multiple: {$multiple} !== '',
            maxSize: '{$maxSize}'
        });
    }
});
</script>
HTML;

            case 'email':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="email" class="{$inputClass}" id="form-{$name}" name="{$name}"
           placeholder="{$placeholder}">
</div>
HTML;

            case 'tel':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="tel" class="{$inputClass}" id="form-{$name}" name="{$name}"
           placeholder="{$placeholder}">
</div>
HTML;

            case 'url':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="url" class="{$inputClass}" id="form-{$name}" name="{$name}"
           placeholder="{$placeholder}">
</div>
HTML;

            case 'password':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="password" class="{$inputClass}" id="form-{$name}" name="{$name}"
           placeholder="{$placeholder}">
</div>
HTML;

            case 'search':
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="search" class="{$inputClass}" id="form-{$name}" name="{$name}"
           placeholder="{$placeholder}">
</div>
HTML;

            case 'hidden':
                return <<<HTML
<input type="hidden" id="form-{$name}" name="{$name}" data-field="{$name}"{$attrStr}>
HTML;

            default: // text
                return <<<HTML
<div class="form-group" data-field="{$name}"{$attrStr}>
    <label for="form-{$name}" class="form-label">{$label}</label>
    <input type="text" class="{$inputClass}" id="form-{$name}" name="{$name}"
           placeholder="{$placeholder}">
</div>
HTML;
        }
    }

    /**
     * 根据字段名称自动推测字段类型
     * @param string $fieldName 字段名称
     * @return string 推测的字段类型
     */
    private static function inferFieldType($fieldName)
    {
        $fieldName = strtolower($fieldName);
        
        // 日期时间相关字段
        if (strpos($fieldName, 'date') !== false || strpos($fieldName, 'time') !== false) {
            if (strpos($fieldName, 'datetime') !== false) {
                return 'datetime';
            }
            return 'date';
        }
        
        // 邮箱字段
        if (strpos($fieldName, 'email') !== false || strpos($fieldName, 'mail') !== false) {
            return 'email';
        }
        
        // 密码字段
        if (strpos($fieldName, 'password') !== false || strpos($fieldName, 'pwd') !== false) {
            return 'password';
        }
        
        // 数字字段
        if (strpos($fieldName, 'price') !== false || strpos($fieldName, 'amount') !== false || 
            strpos($fieldName, 'count') !== false || strpos($fieldName, 'number') !== false ||
            strpos($fieldName, 'quantity') !== false || strpos($fieldName, 'qty') !== false ||
            strpos($fieldName, 'age') !== false || strpos($fieldName, 'score') !== false ||
            strpos($fieldName, 'rate') !== false || strpos($fieldName, 'percent') !== false) {
            return 'number';
        }
        
        // 长文本字段
        if (strpos($fieldName, 'description') !== false || strpos($fieldName, 'content') !== false ||
            strpos($fieldName, 'detail') !== false || strpos($fieldName, 'remark') !== false ||
            strpos($fieldName, 'note') !== false || strpos($fieldName, 'comment') !== false) {
            return 'textarea';
        }
        
        // 状态字段
        if (strpos($fieldName, 'status') !== false || strpos($fieldName, 'state') !== false ||
            strpos($fieldName, 'type') !== false || strpos($fieldName, 'category') !== false) {
            return 'select';
        }
        
        // 布尔字段
        if (strpos($fieldName, 'is_') !== false || strpos($fieldName, 'has_') !== false ||
            strpos($fieldName, 'enable') !== false || strpos($fieldName, 'active') !== false) {
            return 'checkbox';
        }
        
        // 默认返回文本类型
        return 'text';
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
        return true;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return 't-header,t-filter,d-form'; // Field标签依赖于t-header、t-filter或d-form标签
    }

    public static function document(): string
    {
        return <<<DOC
字段组件使用方式：

表格头部字段（在t-header标签内）：
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-header>
        <w:field belong="t-header" name="store_id" sortable="true" width="80" editable="false">ID</w:field>
        <w:field belong="t-header" name="name" sortable="true" width="200" editable="true">店铺名称</w:field>
        <w:field belong="t-header" name="status" sortable="true" width="100">状态</w:field>
    </w:t-header>
</w:d-table>

过滤器字段（在t-filter标签内）：
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-filter>
        <w:field belong="t-filter" name="name" type="text" placeholder="搜索店铺名称" class="col-md-3"></w:field>
        <w:field belong="t-filter" name="status" type="select" options="1:启用,0:禁用" class="col-md-3"></w:field>
        <w:field belong="t-filter" name="created_at" type="date" class="col-md-3"></w:field>
        <w:field belong="t-filter" name="is_active" type="checkbox" class="col-md-3"></w:field>
    </w:t-filter>
</w:d-table>

表单字段（在d-form标签内）：

方式1：指定type属性（推荐）
<w:d-form model="WeShop\Store\Model\Store" scope="store-form">
    <w:field belong="d-form" name="name" type="text" label="店铺名称" required="true">店铺名称</w:field>
    <w:field belong="d-form" name="description" type="textarea" label="店铺描述">店铺描述</w:field>
    <w:field belong="d-form" name="status" type="select" label="状态" options="1:启用,0:禁用">状态</w:field>
    <w:field belong="d-form" name="email" type="email" label="联系邮箱">联系邮箱</w:field>
    <w:field belong="d-form" name="phone" type="text" label="联系电话">联系电话</w:field>
</w:d-form>

方式2：自定义HTML内容（不指定type）
<w:d-form model="WeShop\Store\Model\Store" scope="store-form">
    <w:field belong="d-form" name="custom_field">
        <div class="custom-input-group">
            <input type="text" name="custom_field" class="form-control" placeholder="自定义输入框">
            <button type="button" class="btn btn-secondary">选择</button>
        </div>
    </w:field>
</w:d-form>

方式3：自动推测字段类型（不指定type且无HTML内容）
<w:d-form model="WeShop\Store\Model\Store" scope="store-form">
    <w:field belong="d-form" name="email">邮箱地址</w:field>
    <w:field belong="d-form" name="created_date">创建日期</w:field>
    <w:field belong="d-form" name="price">价格</w:field>
    <w:field belong="d-form" name="description">商品描述</w:field>
    <w:field belong="d-form" name="status">状态</w:field>
    <w:field belong="d-form" name="is_active">是否启用</w:field>
</w:d-form>

继承模式表单字段：
<w:d-table model="WeShop\Store\Model\Store" scope="store-table" form="true">
    <w:d-form>
        <w:field belong="d-form" name="name" type="text" label="店铺名称" required="true">店铺名称</w:field>
        <w:field belong="d-form" name="description" type="textarea" label="店铺描述">店铺描述</w:field>
        <w:field belong="d-form" name="status" type="select" label="状态" options="1:启用,0:禁用">状态</w:field>
    </w:d-form>
</w:d-table>

高级用法（手动指定model和scope）：
<w:t-header model="Weline\Demo\Model\Demo" scope="custom-header-scope">
    <w:field belong="t-header" name="store_id" sortable="true" width="80">ID</w:field>
    <w:field belong="t-header" name="name" sortable="true" width="200">店铺名称</w:field>
</w:t-header>

<w:t-filter model="Weline\Demo\Model\Demo" scope="custom-filter-scope">
    <w:field belong="t-filter" name="name" type="text" placeholder="搜索店铺名称"></w:field>
    <w:field belong="t-filter" name="status" type="select" options="1:启用,0:禁用"></w:field>
</w:t-filter>

<w:d-form model="WeShop\Store\Model\Store" scope="custom-form-scope">
    <w:field belong="d-form" name="name" type="text" label="店铺名称">店铺名称</w:field>
    <w:field belong="d-form" name="status" type="select" label="状态" options="1:启用,0:禁用">状态</w:field>
</w:d-form>

常见模型类示例：
- 店铺管理：WeShop\Store\Model\Store
- 产品管理：WeShop\Product\Model\Product
- 用户管理：Weline\Backend\Model\BackendUser
- 菜单管理：Weline\Backend\Model\Menu
- 系统配置：Weline\SystemConfig\Model\Config

属性说明：
name: 必须，字段名称（必须在model中存在）
belong: 必须，指定属于哪个父元素（t-header、t-filter 或 d-form）

表格头部字段特有属性：
sortable: 可选，是否可排序，默认false
width: 可选，列宽度
min-width: 可选，最小列宽度
max-width: 可选，最大列宽度
resizable: 可选，是否可调整列宽，默认true
editable: 可选，是否可编辑，默认false
formatter: 可选，格式化函数

过滤器字段特有属性：
type: 可选，字段类型（text/select/date/number/checkbox），默认text
placeholder: 可选，占位符文本
options: 可选，select类型选项（格式：value:label,value2:label2）

表单字段特有属性：
type: 可选，字段类型（text/textarea/select/checkbox/radio/date/datetime/number/email/password）
  - 如果不指定type且field内容包含HTML标签，则直接渲染自定义HTML内容
  - 如果不指定type且field内容为空，则根据字段名称自动推测类型：
    * 包含date/time → date类型
    * 包含email/mail → email类型  
    * 包含password/pwd → password类型
    * 包含price/amount/count/number/qty/age/score/rate/percent → number类型
    * 包含description/content/detail/remark/note/comment → textarea类型
    * 包含status/state/type/category → select类型
    * 包含is_/has_/enable/active → checkbox类型
    * 其他 → text类型（默认）
placeholder: 可选，占位符文本
options: 可选，select/radio/checkbox类型选项（格式：value:label,value2:label2）
required: 可选，是否必填，默认false
readonly: 可选，是否只读，默认false
disabled: 可选，是否禁用，默认false

通用属性：
class: 可选，CSS类名
style: 可选，内联样式
visible: 可选，是否可见，默认true
searchable: 可选，是否可搜索，默认true
validator: 可选，验证规则
default: 可选，默认值

验证规则：
1. belong属性为必填，必须指定为"t-header"、"t-filter"或"d-form"
2. belong属性值必须与父标签类型匹配
3. 字段名称必须在对应的model中存在，否则会抛出异常
4. 表格头部字段不支持过滤器/表单特有属性（placeholder、options等）
5. 过滤器字段不支持表格头部特有属性（sortable、width等）
6. 表单字段支持所有字段类型和属性
7. select类型的字段必须指定options属性
8. model属性会自动从父标签继承，无需重复指定
9. Model类必须存在且可实例化

上下文识别：
- field标签通过belong属性明确指定父标签类型（t-header、t-filter或d-form）
- 系统会验证belong属性值与实际父标签类型是否匹配
- 根据belong属性进行相应的参数校验和渲染
- 支持从d-table继承model和scope属性
- 支持手动覆盖继承的属性

错误处理：
- 当belong属性缺失时，会提示添加必填属性
- 当belong属性值无效时，会提示正确的值
- 当belong属性与父标签类型不匹配时，会提示修正
- 当Model类不存在时，会提示检查类名是否正确
- 当字段不存在时，会显示可用字段列表
- 当属性使用错误时，会提供明确的错误信息

注意：
1. belong属性是必填的，用于明确指定field标签属于哪个父元素
2. belong属性值必须与实际的父标签类型一致
3. 当在w:d-table内部使用时，model和scope属性会自动从父标签继承
4. 使用错误的属性会抛出明确的异常信息
5. 支持动态属性传递和覆盖
6. 确保Model类的命名空间正确，例如：WeShop\Store\Model\Store
DOC;
    }
}