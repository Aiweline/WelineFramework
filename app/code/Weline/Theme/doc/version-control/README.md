# 主题编辑器版本控制系统

## 概述

主题编辑器版本控制系统为布局编辑提供完整的版本管理功能，包括：

- **版本保存**：将当前工作区保存为新版本，支持自定义版本名称
- **版本切换**：在历史版本间自由切换，继续编辑任意版本
- **恢复原始布局**：一键恢复到主题模板的原始状态（自动创建备份）
- **版本发布**：将指定版本发布到前台

## 核心概念

### 工作区 vs 版本快照

| 概念 | 说明 | 存储位置 |
|-----|------|---------|
| 工作区（Draft） | 当前正在编辑的实时数据 | `m_theme_layout` 表（status=draft） |
| 版本快照 | 某一时刻的完整布局备份 | `m_theme_layout_version` 表 |

### 版本类型

| 类型 | 代码 | 说明 |
|-----|------|-----|
| 手动保存 | `manual` | 用户主动保存的版本 |
| 自动备份 | `auto_backup` | 恢复原始布局前自动创建 |
| 恢复原始 | `restore` | 恢复操作后的空布局版本 |
| 发布快照 | `publish` | 发布时自动创建（可选） |

## 架构设计

```
┌─────────────────────────────────────────────────────────────┐
│                      前端 UI (theme-editor.js)              │
├─────────────────────────────────────────────────────────────┤
│  版本选择面板  │  保存按钮  │  恢复按钮  │  发布按钮       │
└───────┬─────────────┬───────────┬───────────┬───────────────┘
        │             │           │           │
        ▼             ▼           ▼           ▼
┌─────────────────────────────────────────────────────────────┐
│                 ThemeEditor 控制器 (API 层)                 │
├─────────────────────────────────────────────────────────────┤
│ getVersions │ postSaveVersion │ postRestoreOriginal │ ...   │
└───────┬─────────────┬───────────┬───────────┬───────────────┘
        │             │           │           │
        ▼             ▼           ▼           ▼
┌─────────────────────────────────────────────────────────────┐
│              ThemeLayoutVersionService (业务逻辑)           │
├─────────────────────────────────────────────────────────────┤
│ saveVersion │ switchToVersion │ restoreOriginal │ publish   │
└───────┬─────────────┬───────────────────────────────────────┘
        │             │
        ▼             ▼
┌─────────────────────┐  ┌────────────────────────────────────┐
│ ThemeLayoutVersion  │  │ ThemeLayout（工作区）               │
│ （版本快照表）       │  │ ThemeLayoutService                 │
└─────────────────────┘  └────────────────────────────────────┘
```

## 数据模型

### 版本表结构 (`m_theme_layout_version`)

| 字段 | 类型 | 说明 |
|-----|------|-----|
| `version_id` | INT UNSIGNED PK | 自增主键 |
| `theme_id` | INT UNSIGNED | 主题ID |
| `page_type` | VARCHAR(50) | 页面/布局类型 |
| `version_number` | INT UNSIGNED | 版本号 (1, 2, 3...) |
| `version_name` | VARCHAR(100) | 版本名称（可自定义） |
| `version_type` | VARCHAR(20) | 类型：manual/auto_backup/restore/publish |
| `snapshot_data` | LONGTEXT | JSON 快照数据 |
| `parent_version_id` | INT UNSIGNED NULL | 父版本ID |
| `is_current` | TINYINT(1) | 是否为当前编辑版本 |
| `is_published` | TINYINT(1) | 是否为已发布版本 |
| `created_at` | DATETIME | 创建时间 |
| `created_by` | INT UNSIGNED NULL | 创建者用户ID |
| `description` | TEXT NULL | 版本描述 |

### 快照数据格式

```json
{
    "header": {
        "label": "头部区域",
        "widgets": [
            {
                "widget_code": "logo",
                "widget_module": "Weline_Theme",
                "widget_type": "header",
                "slot_id": "logo",
                "config": { "image": "/logo.png" },
                "sort_order": 0
            }
        ]
    },
    "content": { ... },
    "footer": { ... }
}
```

## 使用指南

### 保存版本

1. 在主题编辑器中进行布局修改
2. 点击工具栏的「保存版本」按钮
3. 输入版本名称（可选）
4. 系统自动创建版本快照

### 切换版本

1. 点击版本选择器展开版本面板
2. 选择要切换的历史版本
3. 点击「切换」按钮
4. 工作区将恢复到该版本的状态

### 恢复原始布局

1. 点击「恢复原始布局」按钮
2. 确认操作（系统会自动备份当前状态）
3. 工作区清空，恢复到主题模板原始状态
4. 可随时通过版本面板切换回备份版本

### 发布版本

1. 确认当前工作区是期望发布的状态
2. 点击「发布」按钮
3. 系统将 draft 复制到 published
4. 前台将显示新发布的布局

## API 参考

详见 [API 文档](./api-reference.md)

## 相关文件

- `Model/ThemeLayoutVersion.php` - 版本数据模型
- `Service/ThemeLayoutVersionService.php` - 版本管理服务
- `Controller/Backend/ThemeEditor.php` - 控制器 API
- `view/statics/js/theme-editor.js` - 前端交互逻辑
- `view/templates/backend/ThemeEditor/index.phtml` - 主编辑器模板（包含版本面板 UI）
