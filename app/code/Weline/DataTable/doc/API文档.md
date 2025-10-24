# DataTable API 文档

## 概述

DataTable 模块提供了完整的 REST API 接口，支持数据的增删改查、字段配置、表格配置等功能。

## 基础信息

- **API 基础路径**: `/api/rest/v1/datatable`
- **请求格式**: JSON
- **响应格式**: JSON
- **认证方式**: 基于 Weline Framework 的认证机制

## 通用响应格式

```json
{
    "success": true,
    "message": "操作成功",
    "data": {
        // 具体数据
    },
    "code": 200
}
```

错误响应：
```json
{
    "success": false,
    "message": "错误信息",
    "data": null,
    "code": 400
}
```

## 数据操作接口

### 1. 获取数据列表

**接口地址**: `POST /api/rest/v1/datatable/data`

**请求参数**:
```json
{
    "model": "App\\Model\\User",
    "scope": "user-list",
    "page": 1,
    "limit": 20,
    "filters": {
        "name": "张三",
        "status": "1"
    },
    "sort": {
        "created_at": "DESC",
        "id": "ASC"
    },
    "join": "left users.department_id = departments.id",
    "model_config": {
        "models": ["App\\Model\\User", "App\\Model\\Department"]
    }
}
```

**响应示例**:
```json
{
    "success": true,
    "message": "数据获取成功",
    "data": {
        "data": [
            {
                "id": 1,
                "name": "张三",
                "email": "zhangsan@example.com",
                "status": 1,
                "created_at": "2024-01-01 10:00:00"
            }
        ],
        "total": 100,
        "page": 1,
        "limit": 20,
        "pages": 5,
        "has_next": true,
        "has_prev": false
    }
}
```

### 2. 创建记录

**接口地址**: `POST /api/rest/v1/datatable/create`

**请求参数**:
```json
{
    "model": "App\\Model\\User",
    "data": {
        "name": "李四",
        "email": "lisi@example.com",
        "status": 1
    }
}
```

**响应示例**:
```json
{
    "success": true,
    "message": "记录创建成功",
    "data": {
        "id": 2,
        "data": {
            "id": 2,
            "name": "李四",
            "email": "lisi@example.com",
            "status": 1,
            "created_at": "2024-01-01 11:00:00"
        }
    }
}
```

### 3. 更新记录

**接口地址**: `POST /api/rest/v1/datatable/update`

**请求参数**:
```json
{
    "model": "App\\Model\\User",
    "id": 2,
    "data": {
        "name": "李四修改",
        "email": "lisi_new@example.com"
    }
}
```

### 4. 删除记录

**接口地址**: `POST /api/rest/v1/datatable/delete`

**请求参数**:
```json
{
    "model": "App\\Model\\User",
    "id": 2
}
```

或批量删除：
```json
{
    "model": "App\\Model\\User",
    "ids": [2, 3, 4]
}
```

## 字段配置接口

### 1. 获取字段信息

**接口地址**: `POST /api/rest/v1/datatable/fields`

**请求参数**:
```json
{
    "model": "App\\Model\\User",
    "mode": "add",
    "record_id": "",
    "exclude_fields": ["password", "deleted_at"],
    "include_fields": ["name", "email", "status"]
}
```

**响应示例**:
```json
{
    "success": true,
    "message": "字段获取成功",
    "data": {
        "fields": [
            {
                "name": "name",
                "label": "姓名",
                "type": "text",
                "required": true,
                "placeholder": "请输入姓名",
                "maxlength": 50
            },
            {
                "name": "email",
                "label": "邮箱",
                "type": "email",
                "required": true,
                "placeholder": "请输入邮箱地址"
            },
            {
                "name": "status",
                "label": "状态",
                "type": "select",
                "required": false,
                "options": "1:启用,0:禁用",
                "value": "1"
            }
        ]
    }
}
```

## 配置管理接口

### 1. 保存表格配置

**接口地址**: `POST /api/rest/v1/datatable/save-config`

**请求参数**:
```json
{
    "scope": "user-list",
    "table_id": "datatable-user-list",
    "display_fields": [
        {
            "name": "id",
            "label": "ID",
            "width": "80",
            "visible": true,
            "sortable": true
        },
        {
            "name": "name",
            "label": "姓名",
            "width": "200",
            "visible": true,
            "sortable": true
        }
    ],
    "page_size": 20,
    "sort_config": {
        "field": "created_at",
        "direction": "DESC"
    }
}
```

### 2. 获取表格配置

**接口地址**: `POST /api/rest/v1/datatable/get-config`

**请求参数**:
```json
{
    "scope": "user-list",
    "table_id": "datatable-user-list"
}
```

### 3. 清除表格配置

**接口地址**: `POST /api/rest/v1/datatable/clear-config`

**请求参数**:
```json
{
    "scope": "user-list",
    "table_id": "datatable-user-list"
}
```

## 错误码说明

| 错误码 | 说明 |
|--------|------|
| 200 | 操作成功 |
| 400 | 请求参数错误 |
| 401 | 未授权访问 |
| 403 | 权限不足 |
| 404 | 资源不存在 |
| 500 | 服务器内部错误 |

## 使用示例

### JavaScript 调用示例

```javascript
// 获取数据
fetch('/api/rest/v1/datatable/data', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
        model: 'App\\Model\\User',
        scope: 'user-list',
        page: 1,
        limit: 20,
        filters: {
            name: '张三'
        }
    })
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        console.log('数据获取成功:', data.data);
    } else {
        console.error('错误:', data.message);
    }
});

// 创建记录
fetch('/api/rest/v1/datatable/create', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
        model: 'App\\Model\\User',
        data: {
            name: '新用户',
            email: 'newuser@example.com',
            status: 1
        }
    })
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        console.log('创建成功:', data.data);
    } else {
        console.error('创建失败:', data.message);
    }
});
```

### PHP 调用示例

```php
// 在控制器中使用
$dataTableApi = $this->objectManager->get(\Weline\DataTable\Api\Rest\V1\DataTable::class);

// 获取数据
$result = $dataTableApi->postData();

// 创建记录
$this->request->setParam('model', 'App\\Model\\User');
$this->request->setParam('data', [
    'name' => '新用户',
    'email' => 'newuser@example.com',
    'status' => 1
]);
$result = $dataTableApi->postCreate();
```
