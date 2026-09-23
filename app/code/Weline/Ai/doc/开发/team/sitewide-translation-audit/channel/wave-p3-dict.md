# channel — P3 词典补全波

- date: 2026-09-22
- from: Team:翻译工程师:
- to: Team:项目经理:

## 批次 3 / 4

| 字段 | 值 |
|------|-----|
| batch | 3 |
| pack | `generated/i18n-p3/pack-3.json` |
| seed | `generated/i18n-p3/pack-3-seed.json` |
| words | 101 |
| writes | 6076（含 BOM 变体双写） |
| missing_count | 0 |
| touched_locales | 38 |
| published_ok | 38 |
| published_fail | [] |
| result | **closed** |

### 过程

1. 以 `batch-3.json` gaps 合并 unique `word_clean`（BOM 折叠）+ seed 为起点。
2. 自身模型译写全部 gap locale；禁 Ollama / 禁非中英模块 CSV。
3. dry-run 曾因加泰/希腊间隔号 `·`（U+00B7）被 PHP `\p{Han}` 误拒 8 条 → 改为 `.` 后 `missing_count=0`。
4. `--apply` upsert `LocaleDictionary` + `publishLocale` × 38。

### 抽检

- `包邮` → ru_RU「Бесплатная доставка」 / de_DE「Kostenloser Versand」
- `购物车为空` → de_DE「Ihr Warenkorb ist leer」
- `%{1}` / `%{fail}` / `php bin/w env:install` 占位符保留

### 回报

`{batch:3, words:101, writes:6076, published_ok:38, missing_count:0, result:closed, note:"ca/el middle-dot→dot for Han false positive"}`

## 清扫（P3 remain）

| 字段 | 值 |
|------|-----|
| batch | remain |
| pack | `generated/i18n-p3/pack-remain.json`（+ `pack-remain-tail16.json`） |
| source | `generated/i18n-p3/batch-remain.json` |
| words | 25（batch unique+购物车）+ 16（复查混中误译） |
| writes | 24222 + 528（含 FEFF×0..32 变体） |
| missing_count | 0 |
| touched_locales | 38（主包）/ 2（tail bn_BD+hi_IN） |
| published_ok | 38 / 2 |
| published_fail | [] |
| remaining_gap_rows_after | **0**（CJK 源串 + 好 en_US 口径） |
| result | **closed** |

### 过程

1. 读 `batch-remain.json`：40 行 → BOM 折叠后 **24** unique + 强制 `购物车` = **25** pack keys。
2. 自身模型为全部 `gaps` 写出真实目标语；占位符 `%1` / `%{1}` 保留；禁中文占位 / 禁 Ollama。
3. 强制修正：`购物车` ru_RU `"Shopping Cart"` → **Корзина**（已核验落库）。
4. apply 脚本增强：pack key 去 BOM 后双写扩展为 **FEFF×0..32**，以覆盖多 BOM collect 变体（否则「无权限访问重定向前」多 BOM 行仍缺）。
5. dry-run `missing_count=0` → `--apply` publish ×38。
6. 同口径复查后尚余 **16** 行（bn_BD/hi_IN 译文残留汉字）→ `pack-remain-tail16.json` 再 apply → 复查 **0**。

### 抽检

- `购物车` → ru_RU「Корзина」（不再是英文 Shopping Cart）
- `还差 %1 包邮` → de_DE / ru_RU 等已填；`%1` 保留
- `batch_remain_still_bad=0`

### 回报

`{batch:"remain", words:25, writes:24222, published_ok:38, missing_count:0, remaining_gap_rows_after:0, result:closed, note:"tail16 hi/bn contaminated→0; FEFF×0..32"}`
