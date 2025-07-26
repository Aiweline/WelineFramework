# DataTable API 参考文档

## 概述

本文档详细介绍了 DataTable 模块的 API 接口和使用方法，包括表格、表单、字段等各个组件的配置选项和事件处理。

## 表格组件 (Table)

### 基本配置

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'table-id',
    'data_url' => 'api/data',
    'columns' => [],
    'pagination' => true,
    'search' => true,
    'sorting' => true
]) ?>
```

### 配置参数

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `id` | string | - | 表格唯一标识符 |
| `data_url` | string | - | 数据源URL地址 |
| `columns` | array | [] | 列配置数组 |
| `pagination` | boolean | false | 是否启用分页 |
| `search` | boolean | false | 是否启用搜索 |
| `sorting` | boolean | false | 是否启用排序 |
| `export` | boolean | false | 是否启用导出 |
| `selectable` | boolean | false | 是否支持行选择 |
| `server_side` | boolean | false | 是否使用服务器端处理 |
| `responsive` | boolean | true | 是否响应式设计 |

### 列配置

```php
'columns' => [
    [
        'field' => 'id',           // 字段名
        'title' => 'ID',           // 列标题
        'width' => '80px',         // 列宽度
        'sortable' => true,        // 是否可排序
        'searchable' => true,      // 是否可搜索
        'visible' => true,         // 是否可见
        'type' => 'text',          // 数据类型
        'formatter' => null,       // 格式化函数
        'renderer' => null         // 自定义渲染函数
    ]
]
```

### 分页配置

```php
'pagination' => [
    'enabled' => true,
    'page_size' => 10,
    'page_size_options' => [5, 10, 20, 50],
    'show_total' => true,
    'show_page_info' => true,
    'style' => 'detailed' // simple, detailed, compact
]
```

### 搜索配置

```php
'search' => [
    'enabled' => true,
    'placeholder' => '搜索...',
    'delay' => 300,
    'min_length' => 2,
    'highlight' => true
]
```

### 排序配置

```php
'sorting' => [
    'enabled' => true,
    'multi_sort' => false,
    'default_sort' => 'id',
    'default_order' => 'asc',
    'sort_priority' => []
]
```

### 导出配置

```php
'export' => [
    'enabled' => true,
    'formats' => ['excel', 'csv', 'pdf', 'json'],
    'filename' => 'data-export',
    'include_header' => true,
    'batch_export' => false
]
```

## 表单组件 (Form)

### 基本配置

```php
<?= $this->getTaglib('Weline_DataTable:Form', [
    'id' => 'form-id',
    'action' => 'api/save',
    'method' => 'POST',
    'fields' => [],
    'layout' => 'vertical'
]) ?>
```

### 配置参数

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `id` | string | - | 表单唯一标识符 |
| `action` | string | - | 提交地址 |
| `method` | string | 'POST' | 提交方法 |
| `fields` | array | [] | 字段配置数组 |
| `layout` | string | 'vertical' | 布局方式 |
| `submit_text` | string | '提交' | 提交按钮文本 |
| `reset_text` | string | '重置' | 重置按钮文本 |
| `enable_validation` | boolean | true | 是否启用验证 |

### 字段配置

```php
'fields' => [
    [
        'name' => 'username',      // 字段名
        'label' => '用户名',       // 字段标签
        'type' => 'text',          // 字段类型
        'value' => '',             // 默认值
        'required' => true,        // 是否必填
        'placeholder' => '',       // 占位符
        'validation' => '',        // 验证规则
        'options' => [],           // 选项（用于select等）
        'attributes' => []         // 额外属性
    ]
]
```

## 字段组件 (Field)

### 基本使用

```php
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'text',
    'name' => 'field_name',
    'label' => '字段标签',
    'value' => '',
    'required' => false
]) ?>
```

### 支持的字段类型

#### 文本类型

```php
// 普通文本
['type' => 'text', 'placeholder' => '请输入文本']

// 密码
['type' => 'password', 'min_length' => 6]

// 邮箱
['type' => 'email', 'validation' => 'email']

// 网址
['type' => 'url', 'validation' => 'url']

// 多行文本
['type' => 'textarea', 'rows' => 4]
```

#### 数字类型

```php
// 数字
['type' => 'number', 'min' => 0, 'max' => 100]

// 整数
['type' => 'integer', 'step' => 1]

// 小数
['type' => 'decimal', 'precision' => 2]

// 货币
['type' => 'currency', 'currency' => 'CNY']
```

#### 日期时间类型

```php
// 日期
['type' => 'date', 'format' => 'Y-m-d']

// 时间
['type' => 'time', 'format' => 'H:i:s']

// 日期时间
['type' => 'datetime', 'format' => 'Y-m-d H:i:s']
```

#### 选择类型

```php
// 下拉选择
[
    'type' => 'select',
    'options' => [
        ['value' => '1', 'label' => '选项1'],
        ['value' => '2', 'label' => '选项2']
    ]
]

// 单选按钮
[
    'type' => 'radio',
    'options' => [
        ['value' => 'male', 'label' => '男'],
        ['value' => 'female', 'label' => '女']
    ]
]

// 复选框
[
    'type' => 'checkbox',
    'options' => [
        ['value' => 'reading', 'label' => '阅读'],
        ['value' => 'sports', 'label' => '运动']
    ]
]
```

#### 特殊类型

```php
// 文件上传
['type' => 'file', 'accept' => 'image/*', 'multiple' => true]

// 颜色选择
['type' => 'color', 'value' => '#667eea']

// 范围滑块
['type' => 'range', 'min' => 0, 'max' => 100, 'step' => 5]

// 富文本
['type' => 'richtext', 'height' => 200]
```

## 事件处理

### 表格事件

```javascript
// 分页事件
function onPageChange(page, pageSize) {
    console.log('页面改变:', page, pageSize);
}

// 排序事件
function onSort(field, order) {
    console.log('排序:', field, order);
}

// 搜索事件
function onSearch(keyword) {
    console.log('搜索:', keyword);
}

// 行选择事件
function onRowSelect(rows) {
    console.log('选中行:', rows);
}

// 数据加载事件
function onDataLoad(data) {
    console.log('数据加载:', data);
}
```

### 表单事件

```javascript
// 提交事件
function onSubmit(formData) {
    console.log('表单提交:', formData);
}

// 重置事件
function onReset() {
    console.log('表单重置');
}

// 字段变化事件
function onFieldChange(field, value) {
    console.log('字段变化:', field, value);
}

// 验证事件
function onValidate(field, value, isValid) {
    console.log('字段验证:', field, value, isValid);
}
```

### 字段事件

```javascript
// 值变化事件
function onValueChange(field, value) {
    console.log('值变化:', field, value);
}

// 焦点事件
function onFocus(field) {
    console.log('获得焦点:', field);
}

function onBlur(field) {
    console.log('失去焦点:', field);
}
```

## 数据格式

### 表格数据格式

```json
{
    "success": true,
    "data": {
        "items": [
            {
                "id": 1,
                "name": "张三",
                "email": "zhangsan@example.com",
                "status": 1,
                "created_at": "2024-01-01 12:00:00"
            }
        ],
        "total": 100,
        "page": 1,
        "page_size": 10,
        "total_pages": 10
    },
    "message": "获取数据成功"
}
```

### 表单数据格式

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "张三",
        "email": "zhangsan@example.com",
        "status": 1
    },
    "message": "保存成功"
}
```

## 验证规则

### 内置验证规则

```php
// 必填验证
'required' => true

// 邮箱验证
'validation' => 'email'

// 手机号验证
'validation' => 'phone'

// 身份证验证
'validation' => 'idcard'

// 邮编验证
'validation' => 'zipcode'

// 自定义正则验证
'validation' => '/^[a-zA-Z0-9]+$/'
```

### 自定义验证

```javascript
function customValidator(field, value) {
    if (value.length < 3) {
        return '长度不能少于3个字符';
    }
    return true;
}
```

## 样式定制

### CSS 类名

```css
/* 表格容器 */
.datatable-container

/* 表格 */
.datatable-table

/* 表头 */
.datatable-header

/* 表体 */
.datatable-body

/* 表尾 */
.datatable-footer

/* 分页 */
.datatable-pagination

/* 搜索框 */
.datatable-search

/* 表单 */
.datatable-form

/* 字段 */
.datatable-field
```

### 主题定制

```css
/* 自定义主题 */
.datatable-theme-custom {
    --primary-color: #667eea;
    --secondary-color: #764ba2;
    --border-color: #dee2e6;
    --background-color: #f8f9fa;
    --text-color: #333;
}
```

## 性能优化

### 大数据量处理

```php
// 启用服务器端处理
'server_side' => true,

// 设置合理的页面大小
'page_size' => 50,

// 启用虚拟滚动
'virtual_scroll' => true
```

### 缓存策略

```php
// 启用数据缓存
'cache' => true,
'cache_ttl' => 300,

// 启用查询缓存
'query_cache' => true
```

## 错误处理

### 错误响应格式

```json
{
    "success": false,
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "验证失败",
        "details": {
            "username": "用户名不能为空",
            "email": "邮箱格式不正确"
        }
    }
}
```

### 错误处理示例

```javascript
function handleError(error) {
    if (error.code === 'VALIDATION_ERROR') {
        // 处理验证错误
        Object.keys(error.details).forEach(field => {
            showFieldError(field, error.details[field]);
        });
    } else if (error.code === 'NETWORK_ERROR') {
        // 处理网络错误
        showNetworkError();
    }
}
```

## 最佳实践

### 1. 数据加载优化

```php
// 使用延迟加载
'lazy_load' => true,
'load_threshold' => 100

// 使用分页加载
'pagination' => true,
'page_size' => 20
```

### 2. 用户体验优化

```php
// 添加加载状态
'loading_indicator' => true,

// 添加空状态
'empty_message' => '暂无数据',

// 添加错误状态
'error_message' => '加载失败，请重试'
```

### 3. 安全性考虑

```php
// 启用CSRF保护
'csrf_protection' => true,

// 启用XSS过滤
'xss_filter' => true,

// 启用SQL注入防护
'sql_injection_protection' => true
```

## 常见问题

### Q: 如何处理大数据量的表格？
A: 建议使用服务器端分页和虚拟滚动：

```php
'server_side' => true,
'virtual_scroll' => true,
'page_size' => 50
```

### Q: 如何自定义表格样式？
A: 可以通过CSS类名或主题变量进行定制：

```css
.datatable-table {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
```

### Q: 如何实现动态列显示？
A: 可以通过JavaScript动态控制列的可见性：

```javascript
table.setColumnVisibility('column_name', false);
```

### Q: 如何处理表单验证错误？
A: 可以通过错误处理函数显示验证错误：

```javascript
function onValidationError(errors) {
    errors.forEach(error => {
        showFieldError(error.field, error.message);
    });
}
``` 