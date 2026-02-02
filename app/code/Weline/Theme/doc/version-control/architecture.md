# 版本控制系统架构

## 系统架构图

```mermaid
flowchart TB
    subgraph Frontend[前端 UI]
        Editor[主题编辑器]
        VersionPanel[版本选择面板]
        SaveBtn[保存按钮]
        RestoreBtn[恢复原始布局]
        PublishBtn[发布按钮]
    end
    
    subgraph Backend[后端服务层]
        Controller[ThemeEditor Controller]
        VersionService[ThemeLayoutVersionService]
        LayoutService[ThemeLayoutService]
        LayoutScanner[LayoutScanner]
    end
    
    subgraph Storage[数据存储]
        ThemeLayout[ThemeLayout 表 - 工作区]
        VersionTable[ThemeLayoutVersion 表 - 版本快照]
        LayoutFiles[布局文件 - 原始模板]
    end
    
    SaveBtn --> Controller
    RestoreBtn --> Controller
    PublishBtn --> Controller
    VersionPanel --> Controller
    
    Controller --> VersionService
    VersionService --> VersionTable
    VersionService --> LayoutService
    VersionService --> LayoutScanner
    LayoutService --> ThemeLayout
    LayoutScanner --> LayoutFiles
```

## 核心流程

### 保存版本流程

```mermaid
sequenceDiagram
    participant U as 用户
    participant FE as 前端
    participant C as Controller
    participant VS as VersionService
    participant VT as VersionTable
    participant TL as ThemeLayout
    
    U->>FE: 点击保存
    FE->>C: POST /save-version
    C->>VS: saveVersion(themeId, pageType)
    VS->>TL: 读取当前 draft 数据
    VS->>VT: 获取最新版本号
    VS->>VT: 插入新版本(version_number+1)
    VS->>VT: 设置 is_current=true
    VS->>VT: 旧版本 is_current=false
    VS-->>C: 返回新版本信息
    C-->>FE: JSON 响应
    FE-->>U: 显示"已保存为 v{n}"
```

### 切换版本流程

```mermaid
sequenceDiagram
    participant U as 用户
    participant FE as 前端
    participant C as Controller
    participant VS as VersionService
    participant VT as VersionTable
    participant TL as ThemeLayout
    
    U->>FE: 选择历史版本 v3
    FE->>C: POST /switch-version
    C->>VS: switchToVersion(versionId)
    VS->>VT: 读取 v3 快照
    VS->>TL: 清空当前 draft
    VS->>TL: 从快照恢复 draft
    VS->>VT: 更新 is_current 指针
    VS-->>C: 返回布局数据
    C-->>FE: JSON 响应
    FE-->>U: 刷新预览
```

### 恢复原始布局流程

```mermaid
sequenceDiagram
    participant U as 用户
    participant FE as 前端
    participant C as Controller
    participant VS as VersionService
    participant VT as VersionTable
    participant TL as ThemeLayout
    
    U->>FE: 点击恢复原始布局
    FE->>FE: 确认对话框
    FE->>C: POST /restore-original
    C->>VS: restoreOriginal(themeId, pageType)
    VS->>TL: 读取当前 draft
    VS->>VT: 创建备份版本(type=auto_backup)
    VS->>TL: 清空 draft
    Note over VS: 不添加任何部件,仅保留空插槽
    VS->>VT: 创建新版本(type=restore, name="原始布局")
    VS-->>C: 返回备份版本号和新版本
    C-->>FE: JSON 响应
    FE-->>U: 刷新预览,提示已备份
```

### 发布流程

```mermaid
sequenceDiagram
    participant U as 用户
    participant FE as 前端
    participant C as Controller
    participant VS as VersionService
    participant VT as VersionTable
    participant TL as ThemeLayout
    participant Cache as CacheGenerator
    
    U->>FE: 点击发布
    FE->>C: POST /publish-version
    C->>VS: publishVersion(themeId, pageType)
    VS->>VT: 标记当前版本 is_published=true
    VS->>VT: 旧版本 is_published=false
    VS->>TL: draft 复制到 published
    C->>Cache: 清除并重建缓存
    VS-->>C: 发布成功
    C-->>FE: JSON 响应
    FE-->>U: 提示发布完成
```

## 文件结构

```
app/code/Weline/Theme/
├── Model/
│   ├── ThemeLayout.php           # 工作区模型
│   └── ThemeLayoutVersion.php    # 版本模型 (新增)
├── Service/
│   ├── ThemeLayoutService.php    # 工作区服务
│   └── ThemeLayoutVersionService.php # 版本管理服务 (新增)
├── Controller/Backend/
│   └── ThemeEditor.php           # 控制器 (更新)
├── doc/
│   └── version-control/          # 版本控制文档 (新增)
│       ├── README.md
│       ├── architecture.md
│       └── api-reference.md
└── view/
    ├── templates/backend/ThemeEditor/
    │   └── index.phtml           # 主编辑器模板 (更新，包含版本面板)
    └── statics/js/
        └── theme-editor.js       # 前端交互 (更新)
```

## 设计原则

### 1. 非破坏性操作

- 恢复原始布局前**自动创建备份**
- 切换版本不删除原有数据
- 版本快照为完整副本，互不影响

### 2. 工作区与版本分离

- 工作区（`m_theme_layout`）存储实时编辑数据
- 版本表（`m_theme_layout_version`）存储历史快照
- 两者通过服务层协调，保持数据一致性

### 3. 向后兼容

- 现有 `ThemeLayout` 表结构不变
- `status` 字段（draft/published）继续使用
- 旧 API `/restore-layout` 委托给新实现

### 4. 版本追溯

- 每个版本记录父版本 ID（`parent_version_id`）
- 支持构建版本树结构
- 可追溯版本演化历史

## 未来扩展

### 可选优化

1. **快照压缩**：对大量配置数据使用 gzip 压缩
2. **版本清理**：设置最大版本数，自动清理过旧版本
3. **差异存储**：只存储与上一版本的差异（增量快照）
4. **版本比较**：UI 增加两个版本间的差异对比功能
5. **分支管理**：基于版本树支持分支编辑和合并
