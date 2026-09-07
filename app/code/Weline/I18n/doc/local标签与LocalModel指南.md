# `<local>` 标签与 LocalModel 开发指南

> 适用模块：`Weline_I18n`  
> 相关实现：`Taglib/Local.php`、`Service/TaglibLocalFormService.php`、`Api/Localization/LocalModel.php`  
> 最后更新：2026-08-27

## 概述

Weline 的业务多语言字段（如规则名称、EAV 属性名、活动主题文案）**不走** `i18n_dictionary` 词典表，而是走 **LocalModel 独立本地表 + `<local>` 标签**：

| 能力 | 说明 |
|---|---|
| 数据存储 | 每个主记录 × 每种语言一行，写入 `{module}_*_local*` 表 |
| 后台编辑 | 模板 `<local>` 标签渲染「语言」按钮，点击打开翻译抽屉 |
| 列表展示 | 主表查询链调用 `loadLocalDescription()`，按当前语言 JOIN 本地表 |
| AI 翻译 | 抽屉内「AI 翻译」按钮；`LocalModelTranslationCatalog` 也会扫描全部 LocalModel 供批量翻译 |

**与 `<lang>` / `__()` 的区别：**

- `<lang>`、`__()`：静态 UI 文案，写入模块 `i18n/*.csv` 或全局词典。
- `<local>` + LocalModel：**业务实体字段**的多语言值（名称、描述、主题文案等），按记录 ID 存储。

---

## 架构：主表 + 本地表

```
┌─────────────────┐       ┌──────────────────────────────┐
│  Article (主表)  │ 1───* │  Article\LocalDescription    │
│  id, title, ... │       │  id + local_code + title + …  │
└─────────────────┘       └──────────────────────────────┘
         ↑                              ↑
   默认语言回退值                  各语言翻译行
```

**规则：**

1. **主表 Model** 继承 `Weline\Framework\Database\Model`，**不**继承 LocalModel。
2. **本地表 Model** 继承 `Weline\I18n\Api\Localization\LocalModel`。
3. 要翻译的字段只在 **本地表** 上用 `#[Col]` 声明；框架自动识别，无需额外注册。
4. 推荐命名：`{MainModel}\LocalDescription` 或 `{MainModel}Local`，便于 `loadLocalDescription()` 自动推断关联。

> ⚠️ 新代码请使用 `Weline\I18n\Api\Localization\LocalModel`。  
> `Weline\I18n\LocalModel` 仅为兼容别名，不要在新模块中引用。

---

## 第一步：定义本地表 Model

### 最小示例

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Model\Article;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\YourModule\Model\Article;

#[Table(comment: '文章多语言')]
#[Index(name: 'idx_article_local', columns: ['id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_your_article_local';
    public const indexer = 'your_article_local';

    /** 关联主表主键（必须） */
    public const schema_fields_ID = Article::schema_fields_ID;
    public const fields_ID = Article::schema_fields_ID;

    /** 要翻译的字段：直接声明 Col 即可 */
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '标题')]
    public const schema_fields_TITLE = 'title';

    #[Col(type: 'text', nullable: true, comment: '摘要')]
    public const schema_fields_SUMMARY = 'summary';
}
```

### 表结构约定

| 列 | 说明 |
|---|---|
| `{主表PK}` | 外键，常量 `schema_fields_ID` 指向主表同名字段 |
| `local_code` | 语言码（如 `zh_Hans_CN`、`en_US`），LocalModel 基类已内置 |
| 业务字段 | `title`、`name`、`description` 等，即「要翻译的字段」 |
| `config` | 可选 JSON 列（基类支持），用于嵌套结构，见下文 |

**复合主键：** `(id, local_code)`。Setup 阶段在 Local 模型上声明 `UNIQUE(id, local_code)` 索引。

### 字段如何被识别为「可翻译」

`LocalModelTranslationCatalog` 扫描 `app/code/Weline/**/Model/**` 下所有 `LocalModel` 子类，自动收集字段，**排除**：

- `local_code`、`config`
- 主键 / 关联 ID 字段
- `*_at` 时间戳字段

因此：**在 Local 模型上加 `#[Col]` = 声明该字段参与多语言**。

---

## 第二步：注册表结构并升级

在模块 `Setup/Install.php` 或 `Setup/Upgrade.php` 中注册 Local 模型：

```php
foreach ([Article::class, LocalDescription::class] as $modelClass) {
    $setup->registerModel($modelClass);
}
```

然后执行：

```bash
php bin/w setup:upgrade
# 或仅模型：php bin/w setup:upgrade -m Weline_YourModule --model
```

---

## 第三步：控制器加载当前语言

主表查询链上调用 `loadLocalDescription()`，按 Cookie / 当前语言 LEFT JOIN 本地表（别名 `local`）：

```php
use Weline\YourModule\Model\Article;
use Weline\YourModule\Model\Article\LocalDescription;

/** @var Article $article */
$article = ObjectManager::getInstance(Article::class);

$article->reset()
    ->loadLocalDescription()                              // 默认：Cookie 当前语言
    // ->loadLocalDescription('en_US')                    // 指定语言
    // ->loadLocalDescription('', LocalDescription::class) // 显式指定 Local 类
    ->pagination()
    ->select()
    ->fetch();

$this->assign('articles', $article->getItems());
```

### JOIN 后的模板变量命名

`loadLocalDescription()` 使用别名 `local` 做 JOIN。与主表**同名字段**会被加上 `local_` 前缀：

| 本地表字段 | 列表/详情中的变量（常见） |
|---|---|
| `name` | `local_name` |
| `title` | `local_title` |
| `description` | `local_description` |

模板中应写**回退表达式**：`{{record.local_name|record.name}}`（有翻译用翻译，否则用主表默认值）。

---

## 第四步：模板中使用 `<local>` 标签

### 标签注册

- 标签名：`<local>`（命名空间写法 `<w:local>` 等价，均指向 `Weline\I18n\Taglib\Local`）
- 实现类：`Weline\I18n\Taglib\Local`
- 静态资源：`Weline_I18n::css/local-translation.css`、`view/statics/js/local-translation.js`

### 属性说明

| 属性 | 必填 | 说明 |
|---|---|---|
| `model` | ✅ | Local 模型完整类名，如 `Weline\Marketing\Model\Rule\LocalDescription` |
| `field` | ✅ | 要编辑/保存的字段名；嵌套 JSON 用 `config.xxx.yyy` |
| `id` | ✅ | 主表记录 ID 的模板变量路径，如 `rule.id`、`entity.eav_entity_id` |
| `name` | 建议 | 抽屉按钮文案、原文提示；也参与生成 DOM id |

**标签体：** 当前页面展示值（含回退），例如 `{{rule.local_name|rule.name}}`。

### 单字段示例（Marketing 规则列表）

```html
<local
    model="Weline\Marketing\Model\Rule\LocalDescription"
    field="name"
    id="rule.id"
    name="rule-name"
>{{rule.local_name|rule.name}}</local>
```

对应控制器（节选）：

```php
$rule->loadLocalDescription('', LocalDescription::class)
    ->pagination()->select()->fetch();
$this->assign('rules', $rule->getItems());
```

### 多字段：同一 Local 模型，每个字段一个标签

```html
<local model="Weline\YourModule\Model\Article\LocalDescription"
       field="title" id="article.id" name="article-title">
    {{article.local_title|article.title}}
</local>

<local model="Weline\YourModule\Model\Article\LocalDescription"
       field="summary" id="article.id" name="article-summary">
    {{article.local_summary|article.summary}}
</local>
```

### EAV 实体名称（Eav 模块）

```html
<local name="entity-name"
       model="Weline\Eav\Model\EavEntity\LocalDescription"
       field="name"
       id="entity.eav_entity_id">
    {{entity.local_name|entity.name}}
</local>
```

### 纯 PHP 模板（无 Taglib 变量语法）

```php
<local
    model="Weline\Marketing\Model\Rule\LocalDescription"
    field="name"
    id="rule.id"
    name="rule-name"
><?= $escapeHtml($rule['local_name'] ?? $rule['name'] ?? '') ?></local>
```

---

## 标签交互流程

```
后台列表/表单模板
    │
    ▼
<local model="..." field="..." id="...">展示值</local>
    │
    ├─ 渲染「语言」按钮 + 翻译抽屉（Weline UI Drawer）
    │
    ├─ GET  i18n/backend/taglib/local?model=&field=&id=&value=
    │       → TaglibLocalFormService::buildFormPayload()
    │       → 仅列出当前站点 WebsiteLanguage ∩ 已安装激活语言 + 已有译文 + 进度
    │
    ├─ POST 保存各语言译文
    │       → upsert 到 Local 表 (id + local_code + field)
    │
    └─ AI 翻译（抽屉内按钮）
            → TaglibLocalFormService::aiTranslate()
            → 指定母本 = 网站默认语言（面板 ★）；有效源 = 母本有值优先，否则取任一已填语言反哺（母本空时常见于切换默认语言）
            → 只翻译当前站点关联语言中的目标语言（禁止枚举全量已安装语言）
```

**前提：** 当前站点已配置**关联语言**，且这些语言在 I18n 中已**安装并启用**；否则抽屉会提示先配置网站关联语言。

---

## config 嵌套字段

LocalModel 基类提供 `config` JSON 列及 `getConfigValue()` / `setConfigValue()`。

模板 `field` 使用点号路径：

```html
<local model="..." field="config.hero.title" id="item.id" name="hero-title">
    {{item.local_config.hero.title|item.hero_title}}
</local>
```

| 能力 | config 嵌套字段 |
|---|---|
| 手填保存 | ✅ 支持 |
| 抽屉 AI 翻译 | ❌ 暂不支持（会返回明确错误） |

---

## 代码中直接读写（不经标签）

适用于前台渲染、API、Promotion 主题等场景：

```php
/** @var LocalDescription $local */
$local = ObjectManager::getInstance(LocalDescription::class);

$local->reset()
    ->where(LocalDescription::schema_fields_ID, $articleId)
    ->where(LocalDescription::schema_fields_local_code, 'en_US')
    ->find()
    ->fetch();

if ((int)$local->getId() <= 0) {
    $local->clear()->setData([
        LocalDescription::schema_fields_ID => $articleId,
        LocalDescription::schema_fields_local_code => 'en_US',
        LocalDescription::schema_fields_TITLE => 'English Title',
    ])->save();
} else {
    $local->setData(LocalDescription::schema_fields_TITLE, 'Updated')->save();
}
```

---

## AI 批量翻译（LocalModel 扫描）

除抽屉内单字段 AI 翻译外，I18n 后台 **AI 翻译** 页会通过 `LocalModelTranslationCatalog` 自动发现：

- 所有 `app/code/Weline/**/Model/**` 下的 `LocalModel` 非抽象子类
- 每个模型上的可翻译字段列表
- 与主表的 ID 映射（`LocalDescription` / `*Local` 命名自动推断）

**取源约定（重要）：**

1. 主表若有与 Local **同名字段**且非空 → **直接用主表值作源文**（Local 可完全为空）。
2. 否则再读源语言 Local 行。
3. 目标语言写入 Local；**不要求**先灌源语言 Local。

示例：主表 `region_name=成都市`，Local 无行 → AI 以「成都市」翻译写入 `en_US` 等 Local。

示例：`PromotionActivityThemeLocal` 上的 `nav_label`、`page_title` 等字段会被自动纳入扫描，无需手工配置字段清单。

### EAV 规格项的自动翻译

EAV 的实体、属性集、属性组、属性和属性选项本地描述同样继承公开的 `LocalModel`，所以规格名称和选项值（例如尺寸、颜色）会被同一套目录扫描。EAV 模型保存后由 `Weline\Eav\Observer\EavLocalModelTranslationTrigger` 幂等入队；AI 配置保存或后台“立即翻译”批量入口也会启动该队列。队列只处理 AI 配置中启用的目标语言，并在没有目标语言时跳过。

翻译在后台异步执行，商品详情请求不会等待模型调用。当前语言没有译文时，前台继续显示 EAV 原始值；队列完成后下一次读取即可得到对应语言的 LocalDescription。
LocalDescription 的身份是“业务记录 ID + local_code”，所以译文属于该记录的全局语言数据；网站、店铺、渠道的差异仍由 EAV 主记录和选项作用域决定，不把翻译缓存复制成三套。

---

## 完整开发检查清单

```
□ 主表 Model（Article）— 存默认语言 / 结构字段
□ Local 表 extends LocalModel（Article\LocalDescription）
    □ schema_fields_ID = 主表 PK
    □ #[Col] 声明各翻译字段
    □ UNIQUE(id, local_code)
□ Setup 注册 Local 模型 → php bin/w setup:upgrade
□ 控制器 loadLocalDescription() → assign 到视图
□ 模板 <local model field id> + {{local_*|*}} 回退
□ I18n 安装所需语言
□ （可选）AI 翻译补全其他语言
```

---

## 常见问题

### 列表里翻译不显示

- 查询是否调用 `loadLocalDescription()`？
- 模板是否使用 `local_` 前缀变量？
- 是否写了回退 `{{x.local_name|x.name}}`？

### 点击语言按钮报错

| 报错 | 原因 |
|---|---|
| 请设置 local 标签 model 属性 | `model` 为空或类不存在 |
| 请设置 local 标签 id 属性 | `id` 为空或记录尚未保存（ID=0） |
| local 标签 ID 不允许重复 | 同一页 `id + field` 组合重复 |
| 没有找到任何本地化数据 | I18n 未安装/启用语言 |

### 保存失败 / 500

- 本地表主键必须是 `(id, local_code)` 复合主键，不能只有 `id`。
- POST 字段名须为 `description[{locale}][{field}]` 结构；`TaglibLocalDescriptionNormalizer` 会归一化。
- 保存走 `i18n/backend/taglib/local` POST，不要误路由到 bin-query 词典接口。

### Local 类找不到

若未使用 `{Main}\LocalDescription` 命名，需显式传入：

```php
$model->loadLocalDescription('', \Full\Namespace\To\LocalModel::class);
```

---

## 仓库内参考实现

| 场景 | 主表 | Local 表 | 模板 / 控制器 |
|---|---|---|---|
| 营销规则 | `Marketing\Model\Rule\Rule` | `Rule\LocalDescription` | `Marketing/view/templates/Backend/rule/index.phtml` |
| EAV 实体 | `Eav\Model\EavEntity` | `EavEntity\LocalDescription` | `Eav/view/templates/Backend/Entity/index.phtml` |
| EAV 属性 | `Eav\Model\EavAttribute` | `EavAttribute\LocalDescription` | `Eav/view/templates/Backend/Attribute/index.phtml` |
| 活动主题 | `Promotion\Model\PromotionActivityTheme` | `PromotionActivityThemeLocal` | Service 层读写，无 `<local>` |

更完整的 Marketing 模块规范见：`app/code/Weline/Marketing/doc/开发规则/LocalModel开发规范.md`（偏该模块测试与约定；本指南为 I18n 官方入口）。

---

## 相关源码索引

| 文件 | 职责 |
|---|---|
| `I18n/Api/Localization/LocalModel.php` | LocalModel 基类 |
| `I18n/Api/Localization/TraitLocalModel.php` | local_code 过滤、config 读写 |
| `I18n/Taglib/Local.php` | `<local>` 标签渲染与抽屉 HTML |
| `I18n/Service/TaglibLocalFormService.php` | 表单数据、保存、AI 翻译 |
| `I18n/Controller/Backend/Taglib/Local.php` | 后台 GET/POST 接口 |
| `I18n/Service/LocalModelTranslation/LocalModelTranslationCatalog.php` | AI 批量扫描 |
| `Framework/Database/Model.php` | `loadLocalDescription()` |

---

## 相关文档

- [README.md](./README.md) — I18n 模块总览
- [AI翻译功能说明.md](./AI翻译功能说明.md) — 词典 AI 翻译（与 LocalModel 批量扫描互补）
- [AI翻译快速开始.md](./AI翻译快速开始.md) — AI 翻译配置入门
