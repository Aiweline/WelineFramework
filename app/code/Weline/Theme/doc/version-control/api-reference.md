# 版本控制 API 参考

## 概述

所有 API 端点基于 `/backend/theme-editor/` 路径。

## API 端点

### 获取版本列表

**GET** `/backend/theme-editor/versions`

获取指定主题和页面类型的版本历史。

**参数**

| 参数 | 类型 | 必填 | 说明 |
|-----|------|-----|------|
| `theme_id` | int | 是 | 主题ID |
| `page_type` | string | 否 | 页面类型，默认 `homepage` |
| `limit` | int | 否 | 返回数量限制，默认 20 |

**响应**

```json
{
    "success": true,
    "data": {
        "versions": [
            {
                "version_id": 5,
                "theme_id": 1,
                "page_type": "homepage",
                "version_number": 5,
                "version_name": "添加轮播图",
                "display_name": "添加轮播图",
                "version_type": "manual",
                "version_type_label": "手动保存",
                "is_current": true,
                "is_published": true,
                "created_at": "2026-02-02 14:30:00",
                "created_by": 1,
                "description": null,
                "is_auto_backup": false
            }
        ],
        "current_version_id": 5,
        "published_version_id": 5
    }
}
```

---

### 保存新版本

**POST** `/backend/theme-editor/save-version`

将当前工作区保存为新版本。

**请求体**

```json
{
    "theme_id": 1,
    "page_type": "homepage",
    "version_name": "添加导航菜单",
    "description": "新增顶部导航和面包屑"
}
```

| 参数 | 类型 | 必填 | 说明 |
|-----|------|-----|------|
| `theme_id` | int | 是 | 主题ID |
| `page_type` | string | 否 | 页面类型，默认 `homepage` |
| `version_name` | string | 否 | 版本名称 |
| `description` | string | 否 | 版本描述 |

**响应**

```json
{
    "success": true,
    "message": "已保存为 v6",
    "data": {
        "version_id": 6,
        "version_number": 6,
        "version_name": "添加导航菜单",
        "display_name": "添加导航菜单",
        "is_current": true,
        "is_published": false
    }
}
```

---

### 切换版本

**POST** `/backend/theme-editor/switch-version`

切换到指定历史版本继续编辑。

**请求体**

```json
{
    "theme_id": 1,
    "page_type": "homepage",
    "version_id": 3
}
```

| 参数 | 类型 | 必填 | 说明 |
|-----|------|-----|------|
| `theme_id` | int | 是 | 主题ID |
| `page_type` | string | 是 | 页面类型 |
| `version_id` | int | 是 | 目标版本ID |

**响应**

```json
{
    "success": true,
    "message": "已切换到选定版本",
    "data": {
        "layout": { ... }
    }
}
```

---

### 恢复原始布局

**POST** `/backend/theme-editor/restore-original`

恢复到主题模板的原始布局状态。

**行为**
1. 自动创建当前状态的备份版本（type=`auto_backup`）
2. 清空工作区所有部件
3. 创建新的"原始布局"版本（type=`restore`）

**请求体**

```json
{
    "theme_id": 1,
    "page_type": "homepage"
}
```

**响应**

```json
{
    "success": true,
    "message": "已恢复到原始布局 (已备份为 备份 - 2026-02-02 14:30:00)",
    "data": {
        "backup_version": {
            "version_id": 7,
            "version_name": "备份 - 2026-02-02 14:30:00",
            "version_type": "auto_backup"
        },
        "new_version": {
            "version_id": 8,
            "version_name": "原始布局",
            "version_type": "restore"
        }
    }
}
```

---

### 发布版本

**POST** `/backend/theme-editor/publish-version`

发布当前版本到前台。

**请求体**

```json
{
    "theme_id": 1,
    "page_type": "homepage",
    "version_id": 5
}
```

| 参数 | 类型 | 必填 | 说明 |
|-----|------|-----|------|
| `theme_id` | int | 是 | 主题ID |
| `page_type` | string | 是 | 页面类型 |
| `version_id` | int | 否 | 版本ID，不提供则发布当前版本 |

**响应**

```json
{
    "success": true,
    "message": "版本已发布"
}
```

---

### 删除版本

**POST** `/backend/theme-editor/delete-version`

删除指定版本。

**限制**
- 不能删除当前版本（`is_current=1`）
- 不能删除已发布版本（`is_published=1`）

**请求体**

```json
{
    "version_id": 3
}
```

**响应**

```json
{
    "success": true,
    "message": "版本已删除"
}
```

---

### 重命名版本

**POST** `/backend/theme-editor/rename-version`

重命名指定版本。

**请求体**

```json
{
    "version_id": 5,
    "version_name": "新版本名称"
}
```

**响应**

```json
{
    "success": true,
    "message": "版本已重命名"
}
```

## 错误处理

所有 API 在失败时返回：

```json
{
    "success": false,
    "message": "错误描述信息"
}
```

常见错误：
- `缺少主题ID` - 未提供 theme_id 参数
- `参数不完整` - 缺少必要参数
- `版本不存在` - 指定的 version_id 不存在
- `无法删除当前版本或已发布版本` - 违反删除限制

## 前端集成

### JavaScript API 调用示例

```javascript
// 加载版本列表
async function loadVersions() {
    const result = await Weline.Api.get('/backend/theme-editor/versions', {
        theme_id: themeId,
        page_type: pageType
    });
    if (result.success) {
        renderVersionPanel(result.data.versions);
    }
}

// 保存新版本
async function saveVersion(name) {
    return await Weline.Api.post('/backend/theme-editor/save-version', {
        theme_id: themeId,
        page_type: pageType,
        version_name: name
    });
}
```

### 全局函数

以下函数已暴露到 `window` 对象：

| 函数 | 说明 |
|-----|------|
| `switchToVersion(versionId)` | 切换到指定版本 |
| `deleteVersion(versionId)` | 删除指定版本 |
| `toggleVersionPanel()` | 切换版本面板显示 |
| `loadVersions()` | 加载版本列表 |
