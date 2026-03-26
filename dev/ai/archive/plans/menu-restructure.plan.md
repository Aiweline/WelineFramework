# 后台菜单结构重组计划

**状态**：🟢 已完成（status: completed）
**创建时间**：2026-02-28
**完成时间**：2026-02-28
**目标**：精简顶层菜单，优化菜单层级，提升用户体验

---

## 一、现状分析

### 当前顶层菜单（11个）

| 顺序 | source_id | 菜单名 | 模块 | 问题 |
|-----|-----------|-------|------|------|
| 0 | Weline_Backend::dashboard | 面板 | Weline_Backend | ✅ 保留 |
| 40 | Weline_Cms::menu_page_management | 页面管理 | Weline_Cms | ❌ 重复 |
| 40 | GuoLaiRen_PageBuilder::menu_page_management | 页面管理 | GuoLaiRen_PageBuilder | ❌ 重复 |
| 40 | WeShop_Cms::cms_page_management | CMS页面管理 | WeShop_Cms | ❌ 重复 |
| 10000 | Weline_Backend::business_module | 业务模块 | Weline_Backend | ⚠️ 需整合 |
| 10000 | GuoLaiRen_Desensitization::main | 数据脱敏 | GuoLaiRen_Desensitization | ❌ 应归入工具 |
| 20000 | Weline_Backend::system_menu | 系统管理 | Weline_Backend | ⚠️ 需整合 |
| 25000 | Weline_Backend::system_settings | 系统设置 | Weline_Backend | ❌ 与系统管理重叠 |
| 30000 | Weline_Backend::system_service | 系统服务 | Weline_Backend | ❌ 与系统管理重叠 |
| 80000 | Weline_Backend::system_module | 模组 | Weline_Backend | ❌ 仅1项，空洞 |
| 90000 | Weline_Backend::system_dev_configuration | 开发工具 | Weline_Backend | ✅ 保留 |

### 主要问题

1. **顶层菜单过多**：11个顶层菜单，用户选择困难
2. **页面管理重复**：3个模块都定义了同 order 的「页面管理」顶层菜单
3. **系统类分散**：系统管理、系统设置、系统服务功能重叠，应合并
4. **模组菜单空洞**：仅有 AI 助手一个子菜单
5. **工具类分散**：数据脱敏独立成顶层，其他工具分散各处

---

## 二、优化方案

### 新顶层菜单结构（6个）

| 顺序 | source_id | 菜单名 | 图标 | 包含内容 |
|-----|-----------|-------|------|---------|
| 0 | Weline_Backend::dashboard | **仪表盘** | mdi mdi-monitor-dashboard | 首页、统计面板 |
| 1000 | Weline_Backend::content_management | **内容管理** | mdi mdi-file-document-multiple | 页面构建、CMS、博客、媒体 |
| 2000 | Weline_Backend::business_operations | **业务运营** | mdi mdi-store | 商城、订单、客户、营销、配送、支付 |
| 3000 | Weline_Backend::system_management | **系统管理** | mdi mdi-cog-outline | 用户权限、系统配置、服务、国际化 |
| 4000 | Weline_Backend::apps_tools | **应用工具** | mdi mdi-puzzle | AI助手、SEO、数据脱敏、字体工具 |
| 9000 | Weline_Backend::developer | **开发者** | mdi mdi-code-tags | 开发工作台、主题模板、调试、模组 |

### 菜单归属调整

#### 1. 内容管理 (order: 1000)
```
内容管理
├── 页面构建（合并 PageBuilder）
│   ├── 快速建站
│   ├── 网站构建器
│   ├── 网站管理
│   └── 域名管理
├── 内容系统
│   ├── CMS内容管理
│   ├── 表单提交管理
│   └── 模板管理
├── 博客管理
│   ├── 文章管理
│   ├── 分类管理
│   └── 趋势分析
└── 媒体管理
    └── 文件管理
```

#### 2. 业务运营 (order: 2000)
```
业务运营
├── 商城管理（WeShop）
│   ├── 产品目录
│   ├── 店铺管理
│   └── 客户管理
├── 订单管理
│   ├── 订单列表
│   ├── 支付管理
│   └── 发货管理
├── 客户管理
│   └── 客户列表
├── 营销管理
│   ├── 营销规则
│   ├── 优惠券
│   └── 促销活动
├── 配送管理
│   ├── 地址管理
│   └── 配送系统
├── 支付管理
│   ├── 支付方式
│   └── 交易记录
└── 货币管理
```

#### 3. 系统管理 (order: 3000) — 合并原系统管理+系统设置+系统服务
```
系统管理
├── 用户与权限
│   ├── 管理员
│   │   ├── 用户列表
│   │   └── 角色归配
│   ├── 权限管理
│   │   ├── 权限角色
│   │   └── 权限资源
│   └── 安全设置
│       ├── 安全日志
│       └── IP白名单
├── 系统配置（原系统设置）
│   ├── 基础设置
│   ├── 后台配置
│   ├── 邮件设置
│   ├── 存储设置
│   └── 消息通知
├── 系统服务
│   ├── 缓存管理
│   ├── 消息队列
│   ├── 计划任务
│   └── 网站管理
├── 系统维护
│   ├── 维护模式
│   ├── 系统备份
│   ├── 系统监控
│   └── 访问日志
├── 国际化
│   ├── 本地化
│   ├── 国家
│   └── 词典
└── 菜单与路由
    ├── 菜单管理
    ├── 路由管理
    ├── 插件管理
    ├── 事件管理
    └── 扩展管理
```

#### 4. 应用工具 (order: 4000)
```
应用工具
├── AI 助手（原模组下）
│   ├── 概览与监控
│   ├── 模型中心
│   ├── 助手管理
│   └── ... (保持原结构)
├── SEO 管理
│   ├── SEO总览
│   ├── Sitemap管理
│   └── 站点绑定
├── 数据脱敏
│   ├── 敏感检测
│   ├── AI润色
│   └── 模块配置
└── 其他工具
    └── 字体工具等
```

#### 5. 开发者 (order: 9000) — 合并原开发工具+模组
```
开发者
├── 开发工作台
│   ├── 测试沙盒
│   ├── 开发文档
│   └── 调试配置
├── 事件与扩展
│   ├── 事件管理
│   ├── Hook管理
│   └── 扩展管理
├── 主题模板
│   ├── 主题管理
│   ├── 可视化编辑
│   ├── 标签库
│   └── 后端模板库
├── 模组管理
│   ├── 已安装模块
│   └── EAV管理
└── 编辑器
    ├── 编辑器管理
    ├── 部件管理
    └── DataTable
```

---

## 三、实施步骤

### 阶段 1：修改 Weline_Backend 核心菜单
**涉及文件**：`app/code/Weline/Backend/etc/backend/menu.xml`

- [ ] 重新定义 6 个顶层菜单
- [ ] 调整 order 值
- [ ] 更新图标

### 阶段 2：调整各模块菜单归属
**涉及模块**：

| 模块 | 调整内容 |
|-----|---------|
| Weline_Admin | 用户管理 parent 改为 system_management |
| Weline_Acl | 权限管理 parent 改为 system_management |
| Weline_Cms | 合并到 content_management |
| GuoLaiRen_PageBuilder | 合并到 content_management |
| WeShop_Cms | 取消顶层，归入 content_management |
| GuoLaiRen_Blog | 归入 content_management |
| Weline_Order | 归入 business_operations |
| Weline_Customer | 归入 business_operations |
| Weline_Marketing | 归入 business_operations |
| Weline_Shipping | 归入 business_operations |
| Weline_Payment | 归入 business_operations |
| Weline_Ai | 归入 apps_tools |
| GuoLaiRen_Desensitization | 归入 apps_tools |
| Weline_Seo | 归入 apps_tools |
| Weline_CacheManager | 归入 system_management |
| Weline_Queue | 归入 system_management |
| Weline_Cron | 归入 system_management |
| Weline_I18n | 归入 system_management |
| Weline_ModuleManager | 归入 developer |
| Weline_DeveloperWorkspace | 归入 developer |
| Weline_Theme | 归入 developer |
| Weline_Event | 归入 developer |
| Weline_Hook | 归入 developer |
| Weline_Extends | 归入 developer |

### 阶段 3：删除冗余顶层菜单
- [ ] 删除 `Weline_Backend::system_settings`（合并到 system_management）
- [ ] 删除 `Weline_Backend::system_service`（合并到 system_management）
- [ ] 删除 `Weline_Backend::system_module`（合并到 apps_tools）
- [ ] 删除 `Weline_Backend::business_module`（改为 business_operations）

### 阶段 4：升级与验证
- [ ] 执行 `php bin/w setup:upgrade --route`
- [ ] 清理菜单缓存
- [ ] 验证所有菜单正常显示
- [ ] 验证权限正常

---

## 四、风险评估

| 风险 | 影响 | 缓解措施 |
|-----|------|---------|
| ACL source_id 变更 | 权限失效 | 保持原 source_id 不变，仅改 parent |
| 菜单消失 | 功能无法访问 | 逐模块修改，每步验证 |
| 用户习惯改变 | 找不到功能 | 保持子菜单结构，仅调整归属 |

---

## 五、涉及文件清单

```
app/code/Weline/Backend/etc/backend/menu.xml          # 核心顶层菜单定义
app/code/Weline/Admin/etc/backend/menu.xml            # 用户管理
app/code/Weline/Acl/etc/backend/menu.xml              # 权限管理
app/code/Weline/Cms/etc/backend/menu.xml              # CMS
app/code/GuoLaiRen/PageBuilder/etc/backend/menu.xml   # PageBuilder
app/code/WeShop/Cms/etc/backend/menu.xml              # WeShop CMS
app/code/GuoLaiRen/Blog/etc/backend/menu.xml          # 博客
app/code/Weline/Order/etc/backend/menu.xml            # 订单
app/code/Weline/Customer/etc/backend/menu.xml         # 客户
app/code/Weline/Marketing/etc/backend/menu.xml        # 营销
app/code/Weline/Shipping/etc/backend/menu.xml         # 配送
app/code/Weline/Payment/etc/backend/menu.xml          # 支付
app/code/Weline/Ai/etc/backend/menu.xml               # AI
app/code/GuoLaiRen/Desensitization/etc/backend/menu.xml # 数据脱敏
app/code/Weline/Seo/etc/backend/menu.xml              # SEO
app/code/Weline/CacheManager/etc/backend/menu.xml     # 缓存
app/code/Weline/Queue/etc/backend/menu.xml            # 队列
app/code/Weline/Cron/etc/backend/menu.xml             # 定时任务
app/code/Weline/I18n/etc/backend/menu.xml             # 国际化
app/code/Weline/ModuleManager/etc/backend/menu.xml    # 模组管理
app/code/Weline/DeveloperWorkspace/etc/backend/menu.xml # 开发工作台
app/code/Weline/Theme/etc/backend/menu.xml            # 主题
app/code/Weline/Event/etc/backend/menu.xml            # 事件
app/code/Weline/Hook/etc/backend/menu.xml             # Hook
app/code/Weline/Extends/etc/backend/menu.xml          # 扩展
app/code/Weline/Currency/etc/backend/menu.xml         # 货币
app/code/Weline/Websites/etc/backend/menu.xml         # 网站
app/code/Weline/MediaManager/etc/backend/menu.xml     # 媒体
```

---

## 六、预期效果

### 优化前
- 顶层菜单：**11 个**
- 用户需要扫描很多选项才能找到功能
- 系统类功能分散在 3 个顶层菜单

### 优化后
- 顶层菜单：**6 个**
- 清晰的功能分区：仪表盘 → 内容 → 业务 → 系统 → 工具 → 开发
- 系统类功能统一收归「系统管理」
- 开发类功能统一收归「开发者」
