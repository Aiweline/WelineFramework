# 支付客户指南 i18n 与 AI 翻译设计

> 适用范围：`/guide/payment` 聚合页、各供应商 **支付指南** / **支付政策** 正文。  
> **官方约定：供应商交付 phtml 模板，正文用 `<lang>` / `@lang()` 写源串**；`i18n:collect` 静态扫描后进入 I18n / AI 翻译流水线。Guide 类元数据仍可用 `__()`。

## 0. 供应商交付清单（必读）

| 交付物 | 路径 | 说明 |
| --- | --- | --- |
| Guide 注册类 | `extends/module/Weline_Payment/PaymentCustomerGuide/{Name}CustomerGuide.php` | 仅元数据：`getTitle()`、`getGuideTemplateCode()` 等，字符串须 `__()` |
| 支付指南 | `view/templates/Frontend/guide/payment/{method_code}/guide.phtml` | **正文：`<lang>` 标签** |
| 支付政策 | `view/templates/Frontend/guide/payment/{method_code}/policy.phtml` | **正文：`<lang>` 标签** |
| 词典 | `{YourModule}/i18n/zh_Hans_CN.csv`、`en_US.csv` | `php bin/w i18n:collect YourModule` 生成/合并 |
| 链接 | 模板内导航 | 使用 `@url{'guide/payment/...'}` / `@url{$guideRoute}`，禁止硬编码 `/guide/payment` |

路由（无需供应商自建）：

- `/guide/payment` — 聚合页
- `/guide/payment/{method_code}` — 指南
- `/guide/payment/{method_code}/policy` — 政策

外壳布局使用 Theme `payment_guide`（亚马逊风格）；供应商只维护上述 phtml 正文与自有 `i18n/*.csv`。

内置参考：`Weline_Payment` 下 `paypal/guide.phtml`、`fake_card/policy.phtml`。

## 1. 现状与问题

### 1.1 已具备能力

| 能力 | 说明 |
| --- | --- |
| 运行时翻译 | phtml 中 `<lang>中文源串</lang>`、`@lang(中文源串)`；属性用 `@lang()`；由 `State::getLangLocal()` 决定展示语言 |
| 静态收集 | `TranslationCollector` 扫描 `*.phtml` 中的 `<lang>`、`@lang()`；PHP 类中的 `__()` 仅用于元数据 |
| CSV 落盘 | `php bin/w i18n:collect` → 各模块 `i18n/{locale}.csv`；**保留** CSV 已有但本次未扫到的词条 |
| AI 翻译 | I18n 后台「AI 翻译」→ Queue → 扫描未译词 → 写入词典 → 发布语言包 |

PayPal 指南模板已按此写法组织（见 `view/templates/Frontend/guide/payment/paypal/guide.phtml`）。

### 1.2 当前痛点

1. **半英半中**：`en_US.csv` 只译了部分句子时，英文站会混显（截图即此情况）。  
2. **归属分散**：指南正文在供应商模块，外壳布局在 `Weline_Theme`，词条分属不同模块 CSV。  
3. **长文语义**：整篇指南由多句 `__()` 组成，AI 默认按「词条」翻译，缺少「支付帮助文档」领域提示。  
4. **验收缺口**：缺少「某 method_code 指南是否译全」的检查手段。

---

## 2. 设计原则

1. **源语言固定 `zh_Hans_CN`**：phtml 内 `<lang>` 以简体中文为源串。  
2. **phtml 禁止 `__()` 正文**：供应商指南/政策 phtml 只用 `<lang>`；`aria-label` 等属性用 `@lang()`。  
3. **禁止硬编码展示语种**：正文中不得写死英文/日文句子；品牌名（PayPal、Visa）除外。  
3. **内容归供应商模块**：谁提供 `PaymentCustomerGuide` + phtml，谁就维护 `i18n/*.csv`。  
4. **布局归 Theme**：`payment_guide` 布局、亚马逊样式 class 的壳层文案归 `Weline_Theme` / `Weline_Payment` 公共 partial。  
5. **复用 I18n 主干**：不新建平行翻译存储；扩展点只做「收集范围、批次范围、领域提示」。  
6. **Theme 可覆写模板**：主题覆写 `guide/payment/**` 时，新文案归主题模块或原供应商模块（见 §4.3）。

---

## 3. 三层架构

```text
┌─────────────────────────────────────────────────────────────┐
│  L1 创作层（供应商 / Payment 内置）                          │
│  **phtml + `<lang>` / `@lang()`**（官方正文载体）              │
│  weline-code 标记段落；可复用 Payment 公共 partial           │
└──────────────────────────┬──────────────────────────────────┘
                           │ 静态扫描
┌──────────────────────────▼──────────────────────────────────┐
│  L2 收集层（Weline_I18n TranslationCollector）                 │
│  i18n:collect / setup:upgrade 触发                           │
│  → 模块 i18n/zh_Hans_CN.csv（源）+ en_US.csv（译文列）        │
│  Payment 扩展：按 guide 注册表校验完整性                       │
└──────────────────────────┬──────────────────────────────────┘
                           │ 未译词入队
┌──────────────────────────▼──────────────────────────────────┐
│  L3 AI 翻译层（现有 AiTranslationService + Queue）            │
│  全量：后台开启目标语言 AI 翻译                               │
│  定向：word_filter = 某指南模块本次收集的词表                  │
│  可选：payment_guide 领域 adapter 增强 prompt                 │
└─────────────────────────────────────────────────────────────┘
```

---

## 4. 创作规范（L1）

### 4.1 目录约定（不变）

```text
{Module}/
├─ extends/module/Weline_Payment/PaymentCustomerGuide/{Name}CustomerGuide.php
└─ view/templates/Frontend/guide/payment/{method_code}/
   ├─ guide.phtml      # 客户支付指南
   └─ policy.phtml     # 支付政策
```

`method_code` 必须与 Provider `getCode()` 一致。

### 4.2 模板写法（**必须**）

所有面向客户的句子写在 phtml 中，使用 `<lang>`；HTML 属性使用 `@lang()`：

```html
<nav aria-label="@lang(支付指南导航)">
    <h1><lang>PayPal 支付指南</lang></h1>
    <p><lang>在结账页选择 PayPal，并按页面提示完成支付。</lang></p>
    <p><lang args="[(string) count($entries)]">共 %{1} 个支付供应商</lang></p>
</nav>
```

占位符使用 `%{1}` / `%{name}`，动态参数通过 `<lang args="[...]">` 传入（与框架 i18n 一致）。

**禁止**在指南/政策 phtml 中使用 `<?= __('…') ?>` 输出正文。

**段落锚点**（收集溯源 + 主题 Hook）：保留 `weline-code`：

```html
<section class="amazon-payment-guide-article__panel" weline-code="payment.guide.paypal.faq">
```

### 4.3 模块归属

| 文案位置 | 归属模块 | CSV 路径 |
| --- | --- | --- |
| `paypal/guide.phtml` 正文 | 定义该 guide 的模块（如 `Vendor_PayPal`） | `Vendor_PayPal/i18n/*.csv` |
| `Weline_Payment` 内置 fake_card / paypal | `Weline_Payment` | `Weline_Payment/i18n/*.csv` |
| `amazon-sidebar.phtml` 等公共 partial | `Weline_Payment` | 同上 |
| `payment_guide` 布局壳层 | `Weline_Theme` | `Weline_Theme/i18n/*.csv` |
| `PaymentCustomerGuide` 类中 `getTitle()` 等 | 同 guide 类所在模块 | 同上 |

Guide 类元数据已在 PHP 中用 `__()` 包裹，会随 `i18n:collect` 进入**该 PHP 文件所在模块**。

### 4.4 不推荐：PHP 内容类拼正文

仅当极长文档且需分段验收时，才考虑额外的 `PaymentGuideContent` PHP 类；**默认一律用 phtml**。即便使用内容类，`getSegments()` 返回的 `text` 仍须包 `__()`，以保证与 `i18n:collect` 行为一致。

---

## 5. 收集规范（L2）

### 5.1 日常命令

```bash
php bin/w i18n:collect Weline_Payment
php bin/w payment:guide:i18n-validate
php bin/w payment:guide:i18n-audit
php bin/w payment:guide:i18n-audit --method=paypal --locale=en_US --json
php bin/w payment:guide:i18n-translate --method=paypal --locale=en_US
php bin/w command:upgrade   # 新增 CLI 后刷新命令表
```

**强制门禁（setup:upgrade）**：`Weline_Payment::setup_upgrade_validate_payment_guide_i18n` 在语言包收集（sort 90）之前执行，要求：

1. 每个已注册供应商的 `guide.phtml` / `policy.phtml` 必须使用 `<lang>` / `@lang()`，禁止 `<?= __()` 正文；
2. 词条归属模块的 `i18n/zh_Hans_CN.csv` 须源串=译文；
3. `i18n/en_US.csv` 须存在且译文与源串不同。

其他语言可经 I18n AI 翻译补齐，但基础中英文不完整时 **setup:upgrade 将失败**。

后台 QueryProvider（`payment`）：

- `listPaymentCustomerGuides` — 列出已注册指南及 i18n 完整度
- `auditPaymentGuideI18n` — 审计缺口
- `enqueuePaymentGuideAiTranslation` — 入队 AI 翻译（`missing_only` 默认 true）

收集结果写入该模块 `i18n/zh_Hans_CN.csv`（源=译文）与 `i18n/en_US.csv`（源→英译）。

### 5.2 Payment 扩展：`PaymentGuideI18nCatalog`（建议实现）

职责：

1. 读取 `PaymentCustomerGuideRegistry` 已发布条目。  
2. 对每个 `source_module` + `guide_template` / `policy_template` 解析物理路径。  
3. 调用 `TranslationCollector::collectLazy($modulePath)` 过滤 `guide/payment/` 下文件。  
4. 输出：

```php
[
    'method_code' => 'paypal',
    'source_module' => 'Weline_Payment',
    'phrases' => ['支付前准备' => ['file' => '.../guide.phtml', 'context' => 'Template']],
    'missing_in_locale' => ['en_US' => ['某未译句']],
]
```

用于：

- 后台「支付指南翻译」页展示缺口  
- CLI：`php bin/w payment:guide:i18n-audit [--method=paypal] [--locale=en_US]`  
- AI 入队时传 `word_filter`（见 §6.2）

### 5.3 接口小扩展（建议）

在 `PaymentCustomerGuideInterface` 增加可选方法（默认由 Registry 推断）：

```php
/** 词条归属模块；默认 = 扫描到 guide 类的模块名 */
public function getI18nOwnerModule(): string;

/** 收集/AI 批次标签，默认 payment_guide.{method_code} */
public function getI18nBatchTag(): string;
```

第三方模块若 guide 类与 phtml 不在同一模块，可显式指定 `getI18nOwnerModule()`。

---

## 6. AI 翻译（L3）

### 6.1 全量路径（零代码，现已可用）

1. 供应商提交 guide phtml（全文 `__()`）。  
2. `php bin/w i18n:collect`（或 `setup:upgrade`）。  
3. I18n 后台 → AI 翻译 → 为目标语言（如 `en_US`）开启并「立即翻译」。  
4. Queue 消费后写入词典并发布；刷新前台即可。

### 6.2 定向批次（建议实现）

对单供应商或单篇指南入队，避免全站词库干扰：

```php
AiTranslationQueueService::enqueue($targetLocale, [
    'word_filter' => $catalog->listPhrases('paypal'), // 来自 PaymentGuideI18nCatalog
    'owner' => 'payment_guide:paypal',
]);
```

复用 `AiTranslationService::batchTranslateDictionary()` 已有 `word_filter` / `owner` 作用域。

### 6.3 领域 Prompt（可选，二期）

新增 AI 场景适配器（类似 `DeveloperWorkspace\DocumentTranslationAdapter`）：

- **code**：`payment_customer_guide_translation`  
- **输入**：`segments[{id,text}]`、`method_code`、`page_type`（guide|policy）、`source_locale`、`target_locale`  
- **规则**：保留 HTML/Markdown 结构；不译 PayPal、SKU、币种代码；语气=电商帮助中心说明文  

I18n 侧在 `word_filter` 批次检测到 `owner` 前缀 `payment_guide:` 时，改走该 adapter 而非通用词典翻译。

---

## 7. Theme 兼容

| 项 | 约定 |
| --- | --- |
| 布局 | `payment_guide`，不承载正文翻译 |
| 样式 | `amazon-payment-guide.css` 在 Theme，无用户可见句子 |
| 模板覆写 | 主题在 `view/templates/.../guide/payment/` 覆写 phtml 时，**新出现的 `__()` 归主题模块**；运行前需对主题模块执行 `i18n:collect` |
| Hook | `Weline_Theme::frontend::layouts::payment_guide::*` 注入的块若含文案，须 `__()` 且归属注入方模块 |

---

## 8. 供应商检查清单

- [ ] `guide.phtml` / `policy.phtml` 正文已用 `<lang>` / `@lang()`（无 `<?= __(` 正文）  
- [ ] `PaymentCustomerGuide` 元数据（title、summary）已 `__()`  
- [ ] 执行 `php bin/w i18n:collect {YourModule}`  
- [ ] `i18n/en_US.csv`（及目标语言）无空译文行  
- [ ] I18n AI 翻译已跑完或人工补全  
- [ ] 切换前台语言验收 `/guide/payment/{method_code}` 无混语  
- [ ] `weline-code` 段落完整，便于后续按段 Hook / 验收  

---

## 9. 分阶段落地

| 阶段 | 内容 | 优先级 |
| --- | --- | --- |
| **P0** | 文档 + 内置 fake_card/paypal 补全 `en_US.csv`；供应商遵守 §4 | ✅ 已完成 |
| **P1** | `PaymentGuideI18nCatalog` + `payment:guide:i18n-audit` CLI | ✅ 已完成 |
| **P2** | QueryProvider + CLI 按 `method_code` 触发 `word_filter` AI 批次 | ✅ 已完成 |
| **P3** | `PaymentGuideContentInterface` + `payment_customer_guide_translation` AI adapter | 中 |
| **P4** | `getI18nOwnerModule()` / `getI18nBatchTag()` 接口字段 | 低 |

---

## 10. 与现有扩展点的关系

```text
PaymentCustomerGuideInterface   → 注册指南路由与模板
PaymentGuideI18nCatalog         → 收集范围 + 缺口审计（新增）
TranslationCollector (I18n)     → 静态提取 __() / <lang>
AiTranslationService (I18n)     → 批量翻译 + 发布
payment_guide layout (Theme)    → 外壳，不参与正文词典
```

供应商**无需**实现自定义收集器；只需保证 phtml 可扫描，并维护本模块 `i18n/*.csv`。
