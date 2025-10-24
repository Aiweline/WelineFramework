# DataTable 模块文档

## 概述

DataTable 是 Weline 框架中一个强大的数据表格组件，提供了完整的数据展示、操作和管理功能。该模块支持多种数据类型、丰富的交互功能和灵活的配置选项。

## 主要功能

### 1. 数据表格功能
- **基本表格显示** - 支持各种数据类型的表格展示
- **分页功能** - 支持客户端和服务器端分页
- **排序功能** - 支持单列和多列排序
- **搜索过滤** - 支持全局搜索和列过滤
- **数据导出** - 支持多种格式的数据导出

### 2. 表单功能
- **字段类型支持** - 支持文本、数字、日期、选择等多种字段类型
- **表单验证** - 内置多种验证规则
- **动态表单** - 支持动态字段和条件显示
- **文件上传** - 支持单文件和多文件上传

### 3. 多模型支持
- **多模型查询** - 支持多个数据模型的联合查询
- **JOIN 操作** - 支持各种类型的 JOIN 查询
- **关联数据** - 支持关联数据的自动加载和显示

## 目录结构

```
DataTable/
├── Api/                    # API接口
├── Controller/             # 控制器
│   └── Test/              # 测试控制器
├── Taglib/                # 标签库
│   ├── Table.php          # 表格标签
│   ├── Form.php           # 表单标签
│   ├── Field.php          # 字段标签
│   ├── TableHeader.php    # 表头标签
│   ├── TableBody.php      # 表体标签
│   ├── TableFooter.php    # 表尾标签
│   └── TableFilter.php    # 过滤器标签
├── Helper/                # 辅助类
├── view/                  # 视图文件
│   ├── test/              # 测试页面
│   └── statics/           # 静态资源
├── doc/                   # 文档
├── example/               # 示例代码
└── register.php           # 模块注册文件
```

## 快速开始

### 1. 基本表格使用

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'user-table',
    'data_url' => $this->getUrl('user/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID', 'width' => '80px'],
        ['field' => 'name', 'title' => '姓名', 'width' => '120px'],
        ['field' => 'email', 'title' => '邮箱', 'width' => '200px'],
        ['field' => 'status', 'title' => '状态', 'width' => '100px']
    ],
    'pagination' => true,
    'search' => true,
    'sorting' => true
]) ?>
```

### 2. 表单使用

```php
<?= $this->getTaglib('Weline_DataTable:Form', [
    'id' => 'user-form',
    'action' => $this->getUrl('user/index/save'),
    'method' => 'POST',
    'fields' => [
        ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => '邮箱', 'type' => 'email', 'required' => true],
        ['name' => 'status', 'label' => '状态', 'type' => 'select', 'options' => [
            ['value' => '1', 'label' => '启用'],
            ['value' => '0', 'label' => '禁用']
        ]]
    ],
    'submit_text' => '保存',
    'reset_text' => '重置'
]) ?>
```

### 3. 字段使用

```php
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'text',
    'name' => 'username',
    'label' => '用户名',
    'required' => true,
    'placeholder' => '请输入用户名'
]) ?>
```

## 配置选项

### 表格配置

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| id | string | - | 表格唯一标识 |
| data_url | string | - | 数据源URL |
| columns | array | [] | 列配置 |
| pagination | boolean | false | 是否启用分页 |
| search | boolean | false | 是否启用搜索 |
| sorting | boolean | false | 是否启用排序 |
| export | boolean | false | 是否启用导出 |

### 表单配置

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| id | string | - | 表单唯一标识 |
| action | string | - | 提交地址 |
| method | string | 'POST' | 提交方法 |
| fields | array | [] | 字段配置 |
| submit_text | string | '提交' | 提交按钮文本 |
| reset_text | string | '重置' | 重置按钮文本 |

### 字段配置

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| type | string | 'text' | 字段类型 |
| name | string | - | 字段名称 |
| label | string | - | 字段标签 |
| value | mixed | null | 字段值 |
| required | boolean | false | 是否必填 |
| placeholder | string | - | 占位符文本 |

## 支持的字段类型

### 文本类型
- `text` - 普通文本
- `password` - 密码
- `email` - 邮箱
- `url` - 网址
- `textarea` - 多行文本

### 数字类型
- `number` - 数字
- `integer` - 整数
- `decimal` - 小数
- `currency` - 货币

### 日期时间类型
- `date` - 日期
- `time` - 时间
- `datetime` - 日期时间

### 选择类型
- `select` - 下拉选择
- `radio` - 单选按钮
- `checkbox` - 复选框

### 特殊类型
- `file` - 文件上传
- `color` - 颜色选择
- `range` - 范围滑块
- `richtext` - 富文本

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
```

### 表单事件

```javascript
// 提交事件
function onSubmit(formData) {
    console.log('表单提交:', formData);
}

// 验证事件
function onValidate(field, value) {
    console.log('字段验证:', field, value);
}
```

## 测试页面

DataTable 模块提供了完整的测试页面，可以通过以下方式访问：

1. **基本功能测试** - `/datatable/test/index/basic`
2. **表单功能测试** - `/datatable/test/index/form`
3. **字段类型测试** - `/datatable/test/index/fieldTypes`
4. **过滤搜索测试** - `/datatable/test/index/filter`
5. **分页功能测试** - `/datatable/test/index/pagination`
6. **排序功能测试** - `/datatable/test/index/sorting`
7. **导出功能测试** - `/datatable/test/index/export`
8. **多模型测试** - `/datatable/test/multimodel/`

## 扩展开发

### 自定义字段类型

```php
class CustomField extends Field
{
    protected function render()
    {
        // 自定义渲染逻辑
        return '<div class="custom-field">' . $this->value . '</div>';
    }
}
```

### 自定义过滤器

```php
class CustomFilter extends Filter
{
    protected function render()
    {
        // 自定义过滤器渲染
        return '<input type="text" class="custom-filter" />';
    }
}
```

## 常见问题

### Q: 如何设置表格的默认排序？
A: 使用 `default_sort` 和 `default_order` 参数：

```php
'default_sort' => 'id',
'default_order' => 'desc'
```

### Q: 如何自定义导出格式？
A: 使用 `export_formats` 参数：

```php
'export_formats' => ['excel', 'csv', 'pdf']
```

### Q: 如何实现服务器端分页？
A: 设置 `server_side` 参数为 `true`：

```php
'server_side' => true
```

## 更新日志

### v1.0.0
- 初始版本发布
- 支持基本表格功能
- 支持表单功能
- 支持多种字段类型
- 支持多模型查询

## 贡献指南

欢迎为 DataTable 模块贡献代码！请遵循以下步骤：

1. Fork 项目
2. 创建功能分支
3. 提交更改
4. 创建 Pull Request

## 许可证

本项目采用 MIT 许可证。详见 [LICENSE](LICENSE) 文件。

## 联系方式

- 作者：秋枫雁飞 (Aiweline)
- 邮箱：aiweline@qq.com
- 网站：www.aiweline.com
- 论坛：https://bbs.aiweline.com 