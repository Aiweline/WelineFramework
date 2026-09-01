# DataTable 模块 API 参考文档

## 版本信息
- **文档版本**: 2.0.0
- **最后更新**: 2024年12月

## 一、概述

DataTable 浏览器运行时以 `Weline.Api` QueryProvider 为规范接口。模块仍保留 Backend REST 兼容层，但它不再接受任意 PHP Model；默认资源注册表只允许模块 Demo 模型，业务模块必须发布自己的 Provider 与 ACL 策略。

### 1.1 规范 Provider：`datatable`

| operation | 模式 | auth | 说明 |
|---|---|---|---|
| `data` | read | any | 分页、搜索、筛选、排序 |
| `fields` | read | any | 表格/筛选字段、资源能力 |
| `metadata` | read | any | `datatable.model-metadata.v1` |
| `formFields` | read | any | 安全表单字段；敏感字段不暴露 |
| `formRecord` | read | backend | 单记录编辑数据 |
| `create` / `update` / `saveData` / `deleteData` | write | backend + ACL | 单表写操作 |
| `saveConfig` / `clearConfig` | write | backend + ACL | per-scope 远程偏好 |
| `previewWrite` | write | backend + ACL | 非变更写入计划与单次 token |
| `executeWrite` | write | backend + ACL | 摘要匹配后按计划事务执行 |

所有 operation 都是 `external=false`。`frontend=true` 仅表示可通过 `Weline.Api` worker 通道调用，不代表匿名授权；真正授权以 `auth` 和 `backend_acl.source_id` 为准。

### 1.2 资源边界

默认 Provider 的资源为 `demo.users`、`demo.products`、`demo.orders`、`demo.user_profiles`、`demo.user_addresses`。`model` 仅参与元数据与载荷一致性校验，不能替代资源注册和 ACL。业务模块通过独立 `api-provider` 接入，禁止由浏览器提交 adapter 类名或任意 Model 类名。

### 1.3 写入计划协议

`previewWrite` 请求包含 `model`、`model_config`、`data`、`dependencies`、`transaction`、`write_order`、`scope` 和 `write_operation`。响应 `datatable.write-plan.v1` 包含：

- `steps` / `targets`：顺序、资源、模型、全部非敏感字段、值、必填、变更、依赖；
- `missing_required` 与 `can_proceed`；
- `transaction`、`atomic_scope`、`warnings`；
- 仅当 `can_proceed=true` 时返回 5 分钟、单次使用的 `plan_token`。

`executeWrite` 必须原样重送同一载荷并附 token。服务端在执行前删除 token，再校验摘要，因此篡改、重放和并发重复提交都会失败。

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

## 二、DataTable 控制器 API（后台兼容层）

以下路由仅用于旧后台调用；新页面应使用上述 QueryProvider。兼容层同样逐个验证单/多模型声明是否位于显式资源注册表，并统一过滤未知、主键及敏感写入字段。

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
