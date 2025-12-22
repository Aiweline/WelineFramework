# DataTable 模块 API 参考文档

## 版本信息
- **文档版本**: 2.0.0
- **最后更新**: 2024年12月

## 一、概述

DataTable 模块提供完整的 RESTful API 接口，用于数据表格的增删改查、导入导出、权限控制等功能。

### API 基础信息
- **基础路径**: `/api/rest/v1/datatable/`
- **认证方式**: 后端API需要登录认证
- **请求格式**: JSON
- **响应格式**: JSON

### 统一响应格式

#### 成功响应
```json
{
    "code": 200,
    "msg": "操作成功",
    "data": {
        // 响应数据
    }
}
```

#### 错误响应
```json
{
    "code": 400,
    "msg": "错误消息",
    "data": null,
    "error": {
        "type": "异常类型",
        "file": "文件路径",
        "line": 行号
    }
}
```

## 二、DataTable 控制器 API

### 2.1 获取数据

**接口**: `POST /api/rest/v1/datatable/data-table/data`

**功能**: 获取数据表格数据，支持分页、排序、筛选

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "scope": "user-list",
    "page": 1,
    "limit": 20,
    "filters": {
        "name": "张三",
        "status": "1"
    },
    "sort": {
        "created_at": "DESC"
    },
    "join": "",
    "model_config": {}
}
```

**响应示例**:
```json
{
    "code": 200,
    "msg": "数据获取成功",
    "data": {
        "data": [...],
        "total": 100,
        "page": 1,
        "limit": 20,
        "pages": 5,
        "has_next": true,
        "has_prev": false
    }
}
```

### 2.2 获取字段信息

**接口**: `POST /api/rest/v1/datatable/data-table/fields`

**功能**: 获取模型字段信息，包括显示字段和筛选字段

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "scope": "user-list",
    "table_id": "datatable-123"
}
```

### 2.3 创建记录

**接口**: `POST /api/rest/v1/datatable/data-table/create`

**功能**: 创建新记录，支持单表和多表

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "data": {
        "name": "张三",
        "email": "zhangsan@example.com",
        "status": 1
    },
    "dependencies": "",
    "transaction": true
}
```

### 2.4 更新记录

**接口**: `POST /api/rest/v1/datatable/data-table/update`

**功能**: 更新记录

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "id": 1,
    "data": {
        "name": "李四",
        "status": 0
    }
}
```

### 2.5 删除记录

**接口**: `POST /api/rest/v1/datatable/data-table/delete`

**功能**: 删除记录，支持单个和批量删除

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "id": 1,
    "ids": [1, 2, 3],
    "soft_delete": false,
    "cascade_delete": false,
    "force_delete": false
}
```

### 2.6 批量更新

**接口**: `POST /api/rest/v1/datatable/data-table/batch-update`

**功能**: 批量更新多条记录

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "ids": [1, 2, 3],
    "data": {
        "status": 1
    }
}
```

### 2.7 批量状态变更

**接口**: `POST /api/rest/v1/datatable/data-table/batch-status`

**功能**: 批量变更记录状态

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "ids": [1, 2, 3],
    "status_field": "status",
    "status_value": 1
}
```

## 三、Form 控制器 API

### 3.1 获取表单字段

**接口**: `POST /api/rest/v1/datatable/form/fields`

**功能**: 获取表单字段信息

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "scope": "form",
    "form_id": "user-form",
    "exclude_fields": ["id", "created_at"],
    "include_fields": [],
    "manual_fields": []
}
```

### 3.2 提交表单

**接口**: `POST /api/rest/v1/datatable/form/submit`

**功能**: 提交表单数据（新增或更新）

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "data": {
        "name": "张三",
        "email": "zhangsan@example.com"
    },
    "record_id": null
}
```

## 四、Import 控制器 API

### 4.1 解析导入文件

**接口**: `POST /api/rest/v1/datatable/import/parse`

**功能**: 上传并解析导入文件（Excel/CSV/JSON）

**请求参数**: 表单数据（multipart/form-data）
- `file`: 文件（必需）

**响应示例**:
```json
{
    "code": 200,
    "msg": "文件解析成功",
    "data": {
        "file_name": "users.xlsx",
        "file_size": 10240,
        "format": "excel",
        "headers": ["name", "email", "status"],
        "total_rows": 100,
        "preview_data": [...],
        "sample_data": {...}
    }
}
```

### 4.2 验证导入数据

**接口**: `POST /api/rest/v1/datatable/import/validate`

**功能**: 验证导入数据的有效性

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "data": [...],
    "field_mapping": {
        "姓名": "name",
        "邮箱": "email"
    },
    "rules": {
        "name": {"required": true},
        "email": {"required": true, "email": true, "unique": true}
    }
}
```

### 4.3 执行数据导入

**接口**: `POST /api/rest/v1/datatable/import/execute`

**功能**: 执行数据导入

**请求参数**:
```json
{
    "model": "Weline\\DataTable\\Model\\TestUser",
    "data": [...],
    "field_mapping": {},
    "batch_size": 100
}
```

**响应示例**:
```json
{
    "code": 200,
    "msg": "数据导入完成",
    "data": {
        "success_count": 95,
        "failed_count": 5,
        "total_count": 100,
        "errors": [...]
    }
}
```

## 五、错误代码

| 错误代码 | 说明 |
|---------|------|
| 1001 | 模型类不存在 |
| 1002 | 字段不存在 |
| 1003 | 数据验证失败 |
| 1004 | 权限不足 |
| 1005 | 数据导入失败 |
| 1006 | 数据导出失败 |
| 1007 | 文件上传失败 |
| 1008 | 数据查询失败 |
| 1009 | 数据保存失败 |
| 1010 | 数据删除失败 |

## 六、使用示例

### 6.1 JavaScript 调用示例

```javascript
// 获取数据
const response = await fetch('/api/rest/v1/datatable/data-table/data', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        model: 'Weline\\DataTable\\Model\\TestUser',
        scope: 'user-list',
        page: 1,
        limit: 20
    })
});

const result = await response.json();
console.log(result);
```

### 6.2 PHP 调用示例

```php
use Weline\DataTable\Api\Rest\V1\DataTable;

$dataTableApi = w_obj(DataTable::class);
$result = $dataTableApi->postData();
```

## 七、注意事项

1. 所有API都需要后端登录认证
2. 模型类名需要使用完整的命名空间
3. 批量操作建议使用事务处理
4. 文件上传大小受PHP配置限制
5. 大数据量操作建议分批处理

---

**更多信息**: 请参考 [使用指南.md](使用指南.md) 和 [需求文档.md](需求文档.md)

