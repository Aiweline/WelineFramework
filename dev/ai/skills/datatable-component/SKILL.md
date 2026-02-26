# DataTable 数据表格组件技能

## 触发关键词

DataTable, 数据表格, d-table, t-header, t-filter, d-form, field, 表格, table, 列表页, listing, CRUD, 排序, sortable, 筛选, filter, 分页, pagination, 行内编辑, inline-edit

## 适用场景

- 创建后台数据列表页
- 配置表格表头和筛选器
- 实现 CRUD 操作
- 多模型 JOIN 查询
- 排序、筛选、分页

---

## 1. 基本用法

### 1.1 最简用法（自动生成）

```html
<w:d-table model="WeShop\Store\Model\Store" scope="store-listing"></w:d-table>
```

自动功能：
- 从模型获取字段信息
- 智能过滤敏感字段（password、token、secret）
- 限制显示字段数量（最多 8 个）
- 自动生成过滤器（前 3 个主要字段）

### 1.2 手动配置

```html
<w:d-table model="WeShop\Store\Model\Store" scope="store-listing">
    <w:t-filter>
        <w:field belong="t-filter" name="name" type="text" placeholder="搜索名称"></w:field>
        <w:field belong="t-filter" name="status" type="select" options="1:启用,0:禁用"></w:field>
    </w:t-filter>
    <w:t-header>
        <w:field belong="t-header" name="store_id" sortable="true" width="80">ID</w:field>
        <w:field belong="t-header" name="name" sortable="true" width="200">名称</w:field>
        <w:field belong="t-header" name="status" sortable="true" width="100">状态</w:field>
    </w:t-header>
</w:d-table>
```

---

## 2. `<w:d-table>` 主标签属性

| 属性 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `model` | string | **必需** | 模型类名 |
| `scope` | string | **必需** | 表格作用域标识 |
| `id` | string | 自动 | 表格 ID |
| `editable` | bool | `false` | 是否可编辑 |
| `inline-edit` | bool | `true` | 行内编辑 |
| `modal-edit` | bool | `true` | 弹窗编辑 |
| `searchable` | bool | `true` | 启用搜索 |
| `sortable` | bool | `true` | 启用排序 |
| `page-size` | int | `20` | 每页记录数 |
| `show-pagination` | bool | `true` | 显示分页 |
| `show-toolbar` | bool | `true` | 显示工具栏 |
| `height` | string | `auto` | 表格高度 |
| `width` | string | `100%` | 表格宽度 |

### 2.1 多模型 JOIN 查询

```html
<w:d-table 
    model="Weline\Admin\Model\Admin as admin, Weline\Store\Model\Store as store" 
    join="left admin.store_id = store.store_id"
    scope="admin-store-join">
    <w:t-header>
        <w:field belong="t-header" name="admin.admin_id" sortable="true">管理员ID</w:field>
        <w:field belong="t-header" name="admin.username" sortable="true">用户名</w:field>
        <w:field belong="t-header" name="store.name" sortable="true">店铺名</w:field>
    </w:t-header>
</w:d-table>
```

JOIN 类型：`left`、`right`、`inner`、`outer`

---

## 3. `<w:t-header>` 表头标签

### 3.1 属性

| 属性 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `sortable` | bool | `true` | 启用排序 |
| `draggable` | bool | `false` | 可拖拽排序 |
| `resizable` | bool | `true` | 可调整列宽 |

### 3.2 `<w:field>` 表头字段属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `name` | string | 字段名（**必需**） |
| `belong` | string | 必须为 `t-header` |
| `sortable` | bool | 是否可排序 |
| `width` | string | 列宽 |
| `min-width` | string | 最小列宽 |
| `editable` | bool | 是否可编辑 |
| `formatter` | string | 格式化函数名 |
| `visible` | bool | 是否可见 |

---

## 4. `<w:t-filter>` 筛选器标签

### 4.1 属性

| 属性 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `searchable` | bool | `true` | 启用搜索 |
| `advanced` | bool | `false` | 高级筛选 |
| `collapsible` | bool | `true` | 可折叠 |

### 4.2 `<w:field>` 筛选字段属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `name` | string | 字段名（**必需**） |
| `belong` | string | 必须为 `t-filter` |
| `type` | string | 字段类型（见下方） |
| `placeholder` | string | 占位符 |
| `options` | string | 选项（格式：`value:label,value:label`） |

### 4.3 支持的筛选类型

- `text`, `email`, `number`, `tel`, `url`, `search`
- `select`, `checkbox`, `radio`
- `date`, `datetime`, `time`

---

## 5. `<w:d-form>` 表单标签

### 5.1 属性

| 属性 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `for` | string | - | 用途（`add`, `edit`, `add,edit`） |
| `title` | string | 自动 | 表单标题 |
| `form-mode` | string | `modal` | 模式（modal/inline） |
| `layout` | string | `vertical` | 布局（vertical/horizontal） |
| `auto_fields` | bool | `true` | 自动生成字段 |
| `exclude_fields` | string | - | 排除字段（逗号分隔） |

### 5.2 `<w:field>` 表单字段属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `name` | string | 字段名（**必需**） |
| `belong` | string | 必须为 `d-form` |
| `type` | string | 字段类型 |
| `label` | string | 标签文本 |
| `placeholder` | string | 占位符 |
| `options` | string | 选项 |
| `required` | bool | 是否必填 |
| `readonly` | bool | 是否只读 |
| `disabled` | bool | 是否禁用 |

---

## 6. 完整 CRUD 示例

```html
<w:d-table model="WeShop\Store\Model\Store" scope="store-management" 
           page-size="20" show-pagination="true" show-toolbar="true">
    
    <!-- 表单配置 -->
    <w:d-form for="add,edit" title="店铺管理" form-mode="modal">
        <w:field belong="d-form" name="name" type="text" label="店铺名称" required="true"></w:field>
        <w:field belong="d-form" name="description" type="textarea" label="描述"></w:field>
        <w:field belong="d-form" name="status" type="select" label="状态" options="1:启用,0:禁用"></w:field>
    </w:d-form>
    
    <!-- 过滤器配置 -->
    <w:t-filter>
        <w:field belong="t-filter" name="name" type="text" placeholder="搜索店铺名"></w:field>
        <w:field belong="t-filter" name="status" type="select" options=":全部,1:启用,0:禁用"></w:field>
    </w:t-filter>
    
    <!-- 表头配置 -->
    <w:t-header>
        <w:field belong="t-header" name="store_id" sortable="true" width="80">ID</w:field>
        <w:field belong="t-header" name="name" sortable="true" width="200">店铺名称</w:field>
        <w:field belong="t-header" name="status" sortable="true" width="100">状态</w:field>
        <w:field belong="t-header" name="created_at" sortable="true" width="150">创建时间</w:field>
    </w:t-header>
</w:d-table>
```

---

## 7. 自定义渲染器

```html
<w:t-header>
    <w:field belong="t-header" name="status" formatter="statusRenderer">状态</w:field>
    <w:field belong="t-header" name="actions" formatter="actionRenderer" width="150">操作</w:field>
</w:t-header>

<script>
// 状态渲染器
window.statusRenderer = function(value, row, index) {
    return value == 1 
        ? '<span class="badge bg-success">启用</span>'
        : '<span class="badge bg-danger">禁用</span>';
};

// 操作按钮渲染器
window.actionRenderer = function(value, row, index) {
    return `
        <button class="btn btn-sm btn-primary" onclick="editRow(${row.id})">编辑</button>
        <button class="btn btn-sm btn-danger" onclick="deleteRow(${row.id})">删除</button>
    `;
};
</script>
```

---

## 8. JavaScript 事件

```javascript
document.addEventListener('datatable:loaded', (e) => console.log('表格加载', e.detail));
document.addEventListener('datatable:refreshed', (e) => console.log('表格刷新'));
document.addEventListener('datatable:row-selected', (e) => console.log('行选择', e.detail));
document.addEventListener('datatable:row-clicked', (e) => console.log('行点击'));
document.addEventListener('datatable:form-submitted', (e) => console.log('表单提交'));
document.addEventListener('datatable:data-saved', (e) => console.log('数据保存'));
document.addEventListener('datatable:data-deleted', (e) => console.log('数据删除'));
```

---

## 9. 注意事项

### 9.1 `belong` 属性必需

```html
<!-- ❌ 错误：缺少 belong -->
<w:field name="status" type="select"></w:field>

<!-- ✅ 正确 -->
<w:field belong="t-filter" name="status" type="select"></w:field>
```

### 9.2 字段名必须存在于 Model

```html
<!-- ❌ 错误：Model 中不存在 non_existent_field -->
<w:field belong="t-header" name="non_existent_field">字段</w:field>

<!-- ✅ 正确：使用 Model 中存在的字段 -->
<w:field belong="t-header" name="store_id">ID</w:field>
```

### 9.3 scope 命名有意义

```html
<!-- ❌ 不推荐：无意义的 scope -->
<w:d-table model="..." scope="table1">

<!-- ✅ 推荐：有意义的 scope -->
<w:d-table model="..." scope="store-management">
```

### 9.4 仅后台可用

DataTable 标签只能在后台模板中使用，前端请求会返回空。

---

## 10. 规范总结

| 项目 | 规范 |
|------|------|
| 必需属性 | `model`, `scope` |
| `<w:field>` 必需 | `name`, `belong` |
| `belong` 值 | `t-header`, `t-filter`, `d-form` |
| 选项格式 | `value:label,value:label` |
| JOIN 语法 | `join="left a.id = b.a_id"` |
| 渲染器 | 挂载到 `window` 对象 |
