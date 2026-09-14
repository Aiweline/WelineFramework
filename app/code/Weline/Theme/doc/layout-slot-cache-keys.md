# Theme 布局 / Slot 结构缓存键（MCP / Agent）

> 权威用法入口：Framework [`3-开发/缓存使用指南.md`](../../Framework/doc/3-开发/缓存使用指南.md)「结构缓存 vs 呈现缓存」。  
> 本文只约束 **Theme 布局与 Slot**，避免 Agent 把多语言/货币写成多份布局结构。

## 入选闸门（去维总规则）

**只有载荷不依赖某维，才允许从缓存/身份键去掉该维。**

| 载荷性质 | lang | currency | 是否去维 |
|----------|------|----------|----------|
| 纯结构（挂载、slot、uid、code、拓扑、未译 config） | 去掉 | 去掉 | **是** |
| 含译文/译名/译后 HTML/按语言 resolve 的配置 | **保留** | 按是否含价 | **不去 lang**；无价可只砍 currency |
| 含标价或含价 HTML | 按文案 | **保留** | **不去 currency** |
| 结构+语言混在同一缓存条目 | — | — | **先拆分**；未拆前整项不改键 |

有语言区别、依赖语言的资源，**不要**从组合键删语言；货币同理。

## 硬规则

1. **Slot 树、部件挂载关系、已发布布局投影（未译）**与语言、货币无关；只跟 **website / store / channel / store_mode**（以及 theme、area、layout_option、page_type、target）有关。
2. 缓存键必须用框架缓存类创建，**禁止**业务手写拼接 `locale` / `lang` / `currency` / `request_id` 进**结构**键。
3. 推荐路径：
   - `w_cache(...)->getCustom/setCustom(..., website: true)`（`lang`/`currency` 保持默认 `false`）
   - 或 `CachePolicy(scope: website|store|channel, vary: [])` + `StorefrontScopeHotCache` / `KeyBuilder::policyKey`
   - 底层等价：`KeyBuilder::applyDimensionFlags($key, website: true, lang: false, currency: false)`
4. `website: true` 时键会带上冻结的 store、channel、store_mode 与 namespace fingerprint；这是范围隔离，不是语言隔离。
5. **不要**把 `RequestContext::getId()`、Fiber id、Worker pid、launch id 写入可跨请求共享的结构键。
6. 整页 **FPC HTML**、已渲染 **chrome HTML**、**RESOURCE_I18N**、译名目录等属于呈现/语言层，键**保留** lang（及需要时的 currency）；不得据此再复制一份 Slot **结构**。

## 分层

| 层 | 是否随语言/货币变 | 建键 |
|----|-------------------|------|
| 布局 identity 的结构语义（slot → widget 挂载、未译 config） | 否 | Custom / Policy，`vary=[]`，排除 lang/currency/request_id |
| RESOURCE_I18N / 词典后贴、可译字段 | 是 | 后贴或另键，**保留 locale/lang** |
| 类型化 file-image 的 usage locale | 媒体边界 | 不进结构身份 |
| 前台输出 HTML（FPC / chrome partial） | 是 | 普通环境维或 FPC 向量，保留 lang |

## 实现收口

- 结构读：`resolveStructureLayout` 只产出未译挂载投影；`applyLayoutLocaleOverlay` 按请求/编辑 locale 后贴 I18N。
- `getLayoutData` 结构半边键**不含** `locale_code`；后贴在命中之后。
- `LayoutIdentityHasher` / LAYOUT·META `identityLocale` / 结构 Normalizer 不把请求语言当结构维。
- **不改**：I18N snapshot 键、chrome/nav HTML 的 lang、`getConfigList`、类目译名树、LayoutData 译名缓存、Acl 整行。

## 正例

```php
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\KeyBuilder;

$logical = 'layout:' . $themeId . ':' . $pageType . ':' . $layoutOption
    . ':target:' . $targetType . ':' . $targetId;

// 结构：多语言共享
w_cache('theme_layout')->setCustom($logical, $publishedLayout, 3600, website: true);

// 或 Policy
$policy = new CachePolicy(
    resource: 'theme.layout.published',
    pool: 'theme_layout',
    scope: 'channel',
    vary: [],
    dependencies: ['theme'],
);
```

## 反例

```php
// 错误：请求语言进入结构键
$cacheKey = "{$themeId}:{$pageType}:{$status}:{$localeCode}:...";

// 错误：普通 get/set 自动注入 lang+currency
w_cache('theme_layout')->set($logical, $publishedLayout);

// 错误：请求 ID 进入共享键
$cacheKey = $logical . '|' . RequestContext::getId();

// 错误：对译后 HTML / I18N 去 lang
$policy = new CachePolicy(..., vary: []); // 呈现层需要 lang 时禁止
```

## 相关代码

- `Weline\Framework\Cache\KeyBuilder::applyDimensionFlags`
- `Weline\Framework\Cache\Pool\CachePool::getCustom` / `setCustom`
- `Weline\Framework\Cache\CachePolicy`（结构资源 `vary` 应留空）
- `Weline\Theme\Service\Scoped\ThemeScopedPreviewResolver`（结构 / 后贴分层）
- `Weline\Theme\Service\SlotRendererService::getLayoutData`
