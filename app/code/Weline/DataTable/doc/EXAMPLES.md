# DataTable 使用示例

## 概述

本文档提供了 DataTable 模块的各种使用示例，从基本用法到高级功能，帮助开发者快速上手和深入使用。

## 基本示例

### 1. 简单表格

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'simple-table',
    'data_url' => $this->getUrl('user/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID'],
        ['field' => 'name', 'title' => '姓名'],
        ['field' => 'email', 'title' => '邮箱']
    ]
]) ?>
```

### 2. 带分页的表格

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'paged-table',
    'data_url' => $this->getUrl('user/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID', 'width' => '80px'],
        ['field' => 'name', 'title' => '姓名', 'width' => '120px'],
        ['field' => 'email', 'title' => '邮箱', 'width' => '200px'],
        ['field' => 'status', 'title' => '状态', 'width' => '100px']
    ],
    'pagination' => true,
    'page_size' => 10
]) ?>
```

### 3. 可搜索和排序的表格

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'searchable-table',
    'data_url' => $this->getUrl('user/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID', 'sortable' => true],
        ['field' => 'name', 'title' => '姓名', 'sortable' => true, 'searchable' => true],
        ['field' => 'email', 'title' => '邮箱', 'sortable' => true, 'searchable' => true],
        ['field' => 'created_at', 'title' => '创建时间', 'sortable' => true]
    ],
    'pagination' => true,
    'search' => true,
    'sorting' => true
]) ?>
```

## 表单示例

### 1. 基本表单

```php
<?= $this->getTaglib('Weline_DataTable:Form', [
    'id' => 'user-form',
    'action' => $this->getUrl('user/index/save'),
    'method' => 'POST',
    'fields' => [
        ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => '邮箱', 'type' => 'email', 'required' => true],
        ['name' => 'phone', 'label' => '电话', 'type' => 'tel'],
        ['name' => 'status', 'label' => '状态', 'type' => 'select', 'options' => [
            ['value' => '1', 'label' => '启用'],
            ['value' => '0', 'label' => '禁用']
        ]]
    ],
    'submit_text' => '保存用户',
    'reset_text' => '重置表单'
]) ?>
```

### 2. 复杂表单

```php
<?= $this->getTaglib('Weline_DataTable:Form', [
    'id' => 'complex-form',
    'action' => $this->getUrl('user/index/save'),
    'method' => 'POST',
    'fields' => [
        ['name' => 'username', 'label' => '用户名', 'type' => 'text', 'required' => true, 'placeholder' => '请输入用户名'],
        ['name' => 'password', 'label' => '密码', 'type' => 'password', 'required' => true],
        ['name' => 'confirm_password', 'label' => '确认密码', 'type' => 'password', 'required' => true],
        ['name' => 'birth_date', 'label' => '出生日期', 'type' => 'date'],
        ['name' => 'gender', 'label' => '性别', 'type' => 'radio', 'options' => [
            ['value' => 'male', 'label' => '男'],
            ['value' => 'female', 'label' => '女']
        ]],
        ['name' => 'interests', 'label' => '兴趣爱好', 'type' => 'checkbox', 'options' => [
            ['value' => 'reading', 'label' => '阅读'],
            ['value' => 'sports', 'label' => '运动'],
            ['value' => 'music', 'label' => '音乐']
        ]],
        ['name' => 'avatar', 'label' => '头像', 'type' => 'file', 'accept' => 'image/*'],
        ['name' => 'bio', 'label' => '个人简介', 'type' => 'textarea', 'rows' => 4]
    ],
    'layout' => 'horizontal',
    'submit_text' => '提交',
    'reset_text' => '重置'
]) ?>
```

## 字段类型示例

### 1. 文本类型字段

```php
// 普通文本
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'text',
    'name' => 'title',
    'label' => '标题',
    'placeholder' => '请输入标题'
]) ?>

// 密码字段
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'password',
    'name' => 'password',
    'label' => '密码',
    'required' => true
]) ?>

// 邮箱字段
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'email',
    'name' => 'email',
    'label' => '邮箱',
    'validation' => 'email'
]) ?>

// 多行文本
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'textarea',
    'name' => 'description',
    'label' => '描述',
    'rows' => 5,
    'placeholder' => '请输入描述信息'
]) ?>
```

### 2. 数字类型字段

```php
// 数字输入
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'number',
    'name' => 'age',
    'label' => '年龄',
    'min' => 0,
    'max' => 150
]) ?>

// 货币字段
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'currency',
    'name' => 'price',
    'label' => '价格',
    'currency' => 'CNY',
    'precision' => 2
]) ?>

// 范围滑块
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'range',
    'name' => 'rating',
    'label' => '评分',
    'min' => 0,
    'max' => 5,
    'step' => 0.5
]) ?>
```

### 3. 日期时间字段

```php
// 日期选择
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'date',
    'name' => 'birth_date',
    'label' => '出生日期',
    'format' => 'Y-m-d'
]) ?>

// 时间选择
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'time',
    'name' => 'meeting_time',
    'label' => '会议时间',
    'format' => 'H:i'
]) ?>

// 日期时间选择
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'datetime',
    'name' => 'event_time',
    'label' => '事件时间',
    'format' => 'Y-m-d H:i:s'
]) ?>
```

### 4. 选择类型字段

```php
// 下拉选择
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'select',
    'name' => 'category',
    'label' => '分类',
    'options' => [
        ['value' => 'tech', 'label' => '技术'],
        ['value' => 'business', 'label' => '商业'],
        ['value' => 'lifestyle', 'label' => '生活']
    ]
]) ?>

// 单选按钮
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'radio',
    'name' => 'gender',
    'label' => '性别',
    'options' => [
        ['value' => 'male', 'label' => '男'],
        ['value' => 'female', 'label' => '女'],
        ['value' => 'other', 'label' => '其他']
    ]
]) ?>

// 复选框
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'checkbox',
    'name' => 'permissions',
    'label' => '权限',
    'options' => [
        ['value' => 'read', 'label' => '读取'],
        ['value' => 'write', 'label' => '写入'],
        ['value' => 'delete', 'label' => '删除'],
        ['value' => 'admin', 'label' => '管理']
    ]
]) ?>
```

### 5. 特殊类型字段

```php
// 文件上传
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'file',
    'name' => 'document',
    'label' => '文档',
    'accept' => '.pdf,.doc,.docx',
    'multiple' => false
]) ?>

// 颜色选择
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'color',
    'name' => 'theme_color',
    'label' => '主题颜色',
    'value' => '#667eea'
]) ?>

// 富文本编辑器
<?= $this->getTaglib('Weline_DataTable:Field', [
    'type' => 'richtext',
    'name' => 'content',
    'label' => '内容',
    'height' => 300,
    'toolbar' => ['bold', 'italic', 'underline', 'link', 'image']
]) ?>
```

## 高级功能示例

### 1. 多模型表格

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'multi-model-table',
    'data_url' => $this->getUrl('order/index/getData'),
    'columns' => [
        ['field' => 'order.id', 'title' => '订单ID'],
        ['field' => 'user.name', 'title' => '用户名'],
        ['field' => 'user.email', 'title' => '用户邮箱'],
        ['field' => 'product.name', 'title' => '产品名称'],
        ['field' => 'order.amount', 'title' => '订单金额', 'type' => 'currency'],
        ['field' => 'order.status', 'title' => '订单状态'],
        ['field' => 'order.created_at', 'title' => '创建时间']
    ],
    'models' => [
        'order' => 'Order',
        'user' => 'User',
        'product' => 'Product'
    ],
    'joins' => [
        ['type' => 'left', 'table' => 'user', 'on' => 'order.user_id = user.id'],
        ['type' => 'left', 'table' => 'product', 'on' => 'order.product_id = product.id']
    ],
    'pagination' => true,
    'search' => true,
    'sorting' => true
]) ?>
```

### 2. 自定义渲染器

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'custom-render-table',
    'data_url' => $this->getUrl('user/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID'],
        ['field' => 'name', 'title' => '姓名'],
        ['field' => 'status', 'title' => '状态', 'renderer' => 'statusRenderer'],
        ['field' => 'actions', 'title' => '操作', 'renderer' => 'actionRenderer']
    ]
]) ?>

<script>
function statusRenderer(value, row) {
    if (value == 1) {
        return '<span class="badge bg-success">启用</span>';
    } else {
        return '<span class="badge bg-danger">禁用</span>';
    }
}

function actionRenderer(value, row) {
    return `
        <button class="btn btn-sm btn-primary" onclick="editUser(${row.id})">编辑</button>
        <button class="btn btn-sm btn-danger" onclick="deleteUser(${row.id})">删除</button>
    `;
}
</script>
```

### 3. 条件格式化

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'conditional-table',
    'data_url' => $this->getUrl('product/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID'],
        ['field' => 'name', 'title' => '产品名称'],
        ['field' => 'price', 'title' => '价格', 'type' => 'currency'],
        ['field' => 'stock', 'title' => '库存', 'formatter' => 'stockFormatter']
    ]
]) ?>

<script>
function stockFormatter(value, row) {
    if (value > 100) {
        return '<span style="color: green;">' + value + '</span>';
    } else if (value > 10) {
        return '<span style="color: orange;">' + value + '</span>';
    } else {
        return '<span style="color: red;">' + value + '</span>';
    }
}
</script>
```

### 4. 动态列显示

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'dynamic-table',
    'data_url' => $this->getUrl('user/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID', 'visible' => true],
        ['field' => 'name', 'title' => '姓名', 'visible' => true],
        ['field' => 'email', 'title' => '邮箱', 'visible' => true],
        ['field' => 'phone', 'title' => '电话', 'visible' => false],
        ['field' => 'address', 'title' => '地址', 'visible' => false]
    ],
    'column_selector' => true
]) ?>
```

### 5. 行选择和批量操作

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'selectable-table',
    'data_url' => $this->getUrl('user/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID'],
        ['field' => 'name', 'title' => '姓名'],
        ['field' => 'email', 'title' => '邮箱'],
        ['field' => 'status', 'title' => '状态']
    ],
    'selectable' => true,
    'batch_actions' => [
        ['name' => 'enable', 'label' => '启用', 'class' => 'btn-success'],
        ['name' => 'disable', 'label' => '禁用', 'class' => 'btn-warning'],
        ['name' => 'delete', 'label' => '删除', 'class' => 'btn-danger']
    ]
]) ?>

<script>
function onBatchAction(action, selectedRows) {
    if (action === 'delete') {
        if (confirm('确定要删除选中的用户吗？')) {
            // 执行删除操作
            deleteUsers(selectedRows.map(row => row.id));
        }
    } else {
        // 执行其他批量操作
        updateUserStatus(selectedRows.map(row => row.id), action);
    }
}
</script>
```

## 事件处理示例

### 1. 表格事件

```javascript
// 分页事件
function onPageChange(page, pageSize) {
    console.log('页面改变:', page, pageSize);
    // 可以在这里执行额外的逻辑
}

// 排序事件
function onSort(field, order) {
    console.log('排序:', field, order);
    // 可以在这里执行额外的逻辑
}

// 搜索事件
function onSearch(keyword) {
    console.log('搜索:', keyword);
    // 可以在这里执行额外的逻辑
}

// 行选择事件
function onRowSelect(rows) {
    console.log('选中行:', rows);
    updateBatchActions(rows.length > 0);
}

// 数据加载事件
function onDataLoad(data) {
    console.log('数据加载:', data);
    updateStatistics(data.total);
}
```

### 2. 表单事件

```javascript
// 提交事件
function onSubmit(formData) {
    console.log('表单提交:', formData);
    // 可以在这里执行额外的验证或处理
}

// 重置事件
function onReset() {
    console.log('表单重置');
    // 可以在这里执行额外的清理逻辑
}

// 字段变化事件
function onFieldChange(field, value) {
    console.log('字段变化:', field, value);
    // 可以在这里执行字段间的联动逻辑
}

// 验证事件
function onValidate(field, value, isValid) {
    console.log('字段验证:', field, value, isValid);
    if (!isValid) {
        showFieldError(field, '验证失败');
    }
}
```

## 样式定制示例

### 1. 自定义主题

```css
/* 自定义主题样式 */
.datatable-theme-custom {
    --primary-color: #667eea;
    --secondary-color: #764ba2;
    --border-color: #dee2e6;
    --background-color: #f8f9fa;
    --text-color: #333;
    --hover-color: #e9ecef;
}

.datatable-theme-custom .datatable-table {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.datatable-theme-custom .datatable-header {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
}

.datatable-theme-custom .datatable-pagination .page-link {
    color: var(--primary-color);
    border-color: var(--border-color);
}

.datatable-theme-custom .datatable-pagination .page-item.active .page-link {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}
```

### 2. 响应式设计

```css
/* 响应式表格样式 */
@media (max-width: 768px) {
    .datatable-table {
        font-size: 14px;
    }
    
    .datatable-table th,
    .datatable-table td {
        padding: 8px 4px;
    }
    
    .datatable-pagination {
        flex-direction: column;
        gap: 10px;
    }
    
    .datatable-search {
        width: 100%;
        margin-bottom: 10px;
    }
}

@media (max-width: 576px) {
    .datatable-table {
        font-size: 12px;
    }
    
    .datatable-table th,
    .datatable-table td {
        padding: 6px 2px;
    }
    
    .btn {
        padding: 4px 8px;
        font-size: 12px;
    }
}
```

## 性能优化示例

### 1. 服务器端处理

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'server-side-table',
    'data_url' => $this->getUrl('user/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID'],
        ['field' => 'name', 'title' => '姓名'],
        ['field' => 'email', 'title' => '邮箱'],
        ['field' => 'status', 'title' => '状态']
    ],
    'server_side' => true,
    'page_size' => 50,
    'cache' => true,
    'cache_ttl' => 300
]) ?>
```

### 2. 虚拟滚动

```php
<?= $this->getTaglib('Weline_DataTable:Table', [
    'id' => 'virtual-scroll-table',
    'data_url' => $this->getUrl('user/index/getData'),
    'columns' => [
        ['field' => 'id', 'title' => 'ID'],
        ['field' => 'name', 'title' => '姓名'],
        ['field' => 'email', 'title' => '邮箱']
    ],
    'virtual_scroll' => true,
    'row_height' => 40,
    'visible_rows' => 20
]) ?>
```

## 错误处理示例

### 1. 网络错误处理

```javascript
function handleNetworkError(error) {
    console.error('网络错误:', error);
    
    // 显示错误消息
    showErrorMessage('网络连接失败，请检查网络设置');
    
    // 显示重试按钮
    showRetryButton(() => {
        table.reload();
    });
}
```

### 2. 验证错误处理

```javascript
function handleValidationError(errors) {
    console.error('验证错误:', errors);
    
    // 清除之前的错误
    clearFieldErrors();
    
    // 显示字段错误
    Object.keys(errors).forEach(field => {
        showFieldError(field, errors[field]);
    });
    
    // 滚动到第一个错误字段
    scrollToFirstError();
}
```

### 3. 服务器错误处理

```javascript
function handleServerError(error) {
    console.error('服务器错误:', error);
    
    if (error.code === 'PERMISSION_DENIED') {
        showErrorMessage('权限不足，无法执行此操作');
    } else if (error.code === 'RESOURCE_NOT_FOUND') {
        showErrorMessage('请求的资源不存在');
    } else {
        showErrorMessage('服务器内部错误，请稍后重试');
    }
}
```

## 完整应用示例

### 用户管理系统

```php
<?php
// 控制器
class UserController extends FrontendController
{
    public function index()
    {
        return $this->fetch('user/index.phtml');
    }
    
    public function getData()
    {
        $page = $this->request->getParam('page', 1);
        $pageSize = $this->request->getParam('page_size', 10);
        $search = $this->request->getParam('search', '');
        $sort = $this->request->getParam('sort', 'id');
        $order = $this->request->getParam('order', 'asc');
        
        // 构建查询
        $query = User::select();
        
        if ($search) {
            $query->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
        }
        
        $query->orderBy($sort, $order);
        
        $total = $query->count();
        $users = $query->offset(($page - 1) * $pageSize)
                      ->limit($pageSize)
                      ->get();
        
        return $this->success('获取数据成功', [
            'items' => $users,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize
        ]);
    }
    
    public function save()
    {
        $data = $this->request->getPost();
        
        // 验证数据
        $validator = new UserValidator();
        if (!$validator->validate($data)) {
            return $this->error('验证失败', $validator->getErrors());
        }
        
        // 保存数据
        $user = new User();
        $user->fill($data);
        $user->save();
        
        return $this->success('保存成功', $user);
    }
}
?>

<!-- 视图文件 -->
<!DOCTYPE html>
<html>
<head>
    <title>用户管理</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1>用户管理系统</h1>
        
        <!-- 用户表格 -->
        <?= $this->getTaglib('Weline_DataTable:Table', [
            'id' => 'user-table',
            'data_url' => $this->getUrl('user/index/getData'),
            'columns' => [
                ['field' => 'id', 'title' => 'ID', 'width' => '80px'],
                ['field' => 'name', 'title' => '姓名', 'width' => '120px'],
                ['field' => 'email', 'title' => '邮箱', 'width' => '200px'],
                ['field' => 'phone', 'title' => '电话', 'width' => '120px'],
                ['field' => 'status', 'title' => '状态', 'width' => '100px'],
                ['field' => 'created_at', 'title' => '创建时间', 'width' => '150px'],
                ['field' => 'actions', 'title' => '操作', 'width' => '150px', 'renderer' => 'actionRenderer']
            ],
            'pagination' => true,
            'search' => true,
            'sorting' => true,
            'export' => true,
            'selectable' => true
        ]) ?>
        
        <!-- 用户表单 -->
        <div class="modal fade" id="userModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">用户信息</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?= $this->getTaglib('Weline_DataTable:Form', [
                            'id' => 'user-form',
                            'action' => $this->getUrl('user/index/save'),
                            'method' => 'POST',
                            'fields' => [
                                ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true],
                                ['name' => 'email', 'label' => '邮箱', 'type' => 'email', 'required' => true],
                                ['name' => 'phone', 'label' => '电话', 'type' => 'tel'],
                                ['name' => 'status', 'label' => '状态', 'type' => 'select', 'options' => [
                                    ['value' => '1', 'label' => '启用'],
                                    ['value' => '0', 'label' => '禁用']
                                ]]
                            ],
                            'submit_text' => '保存',
                            'reset_text' => '重置'
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function actionRenderer(value, row) {
            return `
                <button class="btn btn-sm btn-primary" onclick="editUser(${row.id})">编辑</button>
                <button class="btn btn-sm btn-danger" onclick="deleteUser(${row.id})">删除</button>
            `;
        }
        
        function editUser(id) {
            // 加载用户数据
            fetch(`/user/index/get/${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 填充表单
                        fillForm(data.data);
                        // 显示模态框
                        new bootstrap.Modal(document.getElementById('userModal')).show();
                    }
                });
        }
        
        function deleteUser(id) {
            if (confirm('确定要删除这个用户吗？')) {
                fetch(`/user/index/delete/${id}`, { method: 'DELETE' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // 重新加载表格
                            table.reload();
                        }
                    });
            }
        }
        
        function fillForm(data) {
            Object.keys(data).forEach(key => {
                const field = document.querySelector(`[name="${key}"]`);
                if (field) {
                    field.value = data[key];
                }
            });
        }
    </script>
</body>
</html>
```

这个完整的示例展示了如何使用 DataTable 模块构建一个完整的用户管理系统，包括数据展示、搜索、排序、分页、编辑、删除等功能。 