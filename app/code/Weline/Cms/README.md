# Weline Cms CMS内容管理系统模块

## 快速开始

### 1. 安装模块

```bash
# 注册模块并创建数据库表
php bin/w command:upgrade

# 清理缓存
php bin/w cache:clear -f

# 启动服务器
php bin/w s:sta
```

### 2. 访问后台

从日志中获取后台地址：
```bash
cat var/log/server-start.log | grep "后端地址"
```

菜单位置：**内容管理 > CMS内容管理系统**

## 模块结构

```
Weline/Cms/
├── Controller/
│   └── Backend/
│       └── Index.php                      # 后端控制器（CRUD + 翻译）
├── Model/
│   ├── Page.php                          # 页面主模型
│   └── Page/
│       └── LocalDescription.php          # 页面多语言翻译模型
├── view/
│   └── templates/
│       └── Backend/
│           └── Index/
│               ├── index.phtml           # 页面列表视图
│               ├── form.phtml            # 新建/编辑页面表单
│               └── translate.phtml       # 翻译页面表单
├── etc/
│   ├── backend/
│   │   └── menu.xml                      # 后台菜单配置
│   └── env.php                           # 路由配置
├── doc/
│   └── 开发/
│       └── 页面构建器使用说明.md          # 详细使用文档
├── register.php                          # 模块注册文件
└── README.md                             # 本文件
```

## 核心功能

### ✅ 页面管理
- 创建、编辑、删除页面
- 页面搜索和分页
- 状态管理（草稿/已发布）
- 8种预定义页面类型

### ✅ 多语言支持
- 基于I18n LocalModel实现
- 选择多个语言进行翻译
- 翻译状态提示
- 独立翻译管理页面

### ✅ SEO优化
- Meta Title、Description、Keywords
- Hreflang支持（通过多语言）
- 语义化HTML结构

### ✅ 第三方集成
- Google Analytics 4 (GA4)
- Google Tag Manager (GTM)
- Facebook Pixel

### ✅ 页面层级
- 父子页面关系
- 层级管理
- 删除保护

### ✅ 品牌资源
- Logo图片设置
- 网站图标设置

## 数据库表

### 主表：`weline_cms_page`
存储页面基本信息、SEO数据、跟踪代码等

### 翻译表：`cms_page_local_description`
存储多语言翻译内容（名称、标题、内容、SEO信息）

## 权限系统

| 权限ID | 说明 |
|--------|------|
| Weline_Cms::page_builder | 访问模块 |
| Weline_Cms::page_builder_index | 查看列表 |
| Weline_Cms::page_builder_create | 新建页面 |
| Weline_Cms::page_builder_edit | 编辑页面 |
| Weline_Cms::page_builder_delete | 删除页面 |
| Weline_Cms::page_builder_translate | 翻译页面 |

## 快速使用指南

### 创建页面
1. 点击"新建页面"
2. 填写必填项（句柄、类型、名称、标题）
3. 选择需要翻译的语言
4. 点击"创建页面"

### 翻译页面
1. 编辑页面，勾选需要翻译的语言
2. 保存后，在"翻译管理"中点击对应语言的"翻译"按钮
3. 左侧显示原文，右侧填写翻译
4. 保存翻译

### 管理层级
1. 编辑页面时选择"父页面"
2. 政策页面可设置主页为父页面
3. 有子页面时无法删除

## 代码示例

### 获取页面（基础）
```php
use Weline\Cms\Model\Page;

$page = ObjectManager::getInstance(Page::class);
$page->clear()->where('handle', 'about-us')->find()->fetch();
```

### 获取多语言内容（推荐）
```php
use Weline\Cms\Helper\PageHelper;

$pageHelper = ObjectManager::getInstance(PageHelper::class);

// 获取当前语言的所有内容
$content = $pageHelper->getLocalizedContent($page);
echo $content['content'];  // HTML内容
echo $content['title'];    // 标题
echo $content['name'];     // 名称

// 或者直接获取特定字段
$htmlContent = $pageHelper->getContent($page);
$title = $pageHelper->getTitle($page);

// 获取SEO数据（包含hreflang）
$seoData = $pageHelper->getSeoData($page);
```

### 获取原始翻译数据
```php
use Weline\Cms\Model\Page\LocalDescription;

$translation = ObjectManager::getInstance(LocalDescription::class);
$translation->clear()
    ->where('page_id', $pageId)
    ->where('local_code', Cookie::getLang())
    ->find()
    ->fetch();
```

## 多语言Content处理

### 为什么content字段不使用local标签？

`content`字段使用所见即所得（WYSIWYG）编辑器，存储HTML内容。由于编辑器会解析HTML，因此不能使用框架的`<local>`标签。

### 解决方案

我们通过**独立翻译管理**实现：
1. ✅ 主表存储默认语言的content
2. ✅ LocalDescription翻译表存储其他语言的content
3. ✅ 后台提供专门的翻译页面（左右对照）
4. ✅ 前端使用PageHelper自动获取当前语言的content
5. ✅ 完整的SEO支持（包含hreflang标签）

### 使用示例

```php
// 前端控制器
use Weline\Cms\Helper\PageHelper;

$pageHelper = ObjectManager::getInstance(PageHelper::class);
$content = $pageHelper->getLocalizedContent($page);

// 前端模板
<div class="page-content">
    <?= $content['content'] ?>  <!-- 自动显示当前语言的HTML内容 -->
</div>
```

## 前端多语言访问

### URL参数切换语言

```
# 访问默认语言
/cms/frontend/page/view?handle=about-us

# 切换到中文
/cms/frontend/page/view?handle=about-us&lang=zh_CN

# 切换到英文
/cms/frontend/page/view?handle=about-us&lang=en_US
```

### 工作流程

1. URL参数`?lang=zh_CN` 指定语言
2. 系统更新Cookie保持语言偏好
3. 自动加载对应语言的翻译内容
4. 显示页面（包括翻译后的content）
5. 底部显示语言切换器

### 特性

- ✅ URL参数切换语言
- ✅ Cookie持久化用户偏好
- ✅ 无翻译时自动回退到默认语言
- ✅ 翻译状态提示（⚠️图标）
- ✅ 可视化语言切换器
- ✅ 完整SEO支持（hreflang标签）

## 详细文档

- 📖 **完整使用说明**：`doc/开发/页面构建器使用说明.md`
- 📖 **多语言Content处理**：`doc/开发/多语言Content处理说明.md`
- 📖 **前端多语言访问**：`doc/开发/前端多语言访问说明.md`

## 技术栈

- **框架**：Weline Framework
- **多语言**：Weline I18n LocalModel
- **权限**：Weline ACL
- **前端**：Bootstrap 5
- **图标**：Material Design Icons

## 版本信息

- **版本**：1.0.0
- **依赖模块**：
  - Weline_Framework
  - Weline_Admin
  - Weline_Backend
  - Weline_I18n

## 作者

Weline Development Team

---

**提示**：首次使用前请确保已安装并配置好 Weline Framework 和 I18n 模块。

