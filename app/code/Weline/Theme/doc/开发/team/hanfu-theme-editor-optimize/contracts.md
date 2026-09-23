# contracts — hanfu-theme-editor-optimize · P1 商城感（编辑器草稿）

冻结日期：2026-09-23  
冻结依据：`channel/hanfu-theme-editor-ops-brief.md` msg-4 escalate（电商顾问领域决策定稿）  
验收面（硬）：**仅** theme_id=3 主题编辑器首页草稿预览（禁缓存）；禁止已发布 `/`；禁止本波发布主题。

## 顾问约束（冻结）

- 品牌：长安汉服水墨；色服从 Theme `ink`；勿另造色板。
- 拍板：压矮 chrome+Hero + 信任下精选入屏（不改嵌卡除非证明更能达标）。
- 不要：拆信任条、清空水墨 Hero、发布激活、用 `/` 验收。

## UC（可执行）

| UC | 成功标准 | 证据 |
|----|----------|------|
| UC-P1-01 | scrollY=0 预览内可见 ≥4 带价商品卡 + ≥1 ATC/Buy | 编辑器 iframe 禁缓存截图/度量 |
| UC-P1-02 | 槽序 Hero→信任→精选→品类→特价；特价 top/vh ≤1.5 | 度量 deals top |
| UC-P1-03 | 精选 `columns-4` **实渲 4 列一行**；一行完整含价 | 计算列宽×4 ≤ 容器 |
| UC-P1-04 | 活跃 Hero slide **仅 1 个**实心主 CTA；次行动降为文字链 | DOM/截图 |
| UC-P2-01 | products 草稿不崩、气质一致（施工后抽检） | 编辑器 page_type=products |

## 席位边界

| 席位 | 可改 | 禁止 |
|------|------|------|
| **主题开发工程师** | `work_mode=design_theme`：`app/design/Weline/hanfu/**` 首页 layout/CSS（Hero/chrome 高度、区块间距、deals 上移相关 layout）；Theme 模块仅当共享壳必需 | 禁同 key 覆盖 theme.css/theme.js；禁发布；禁改 HotCache |
| **部件开发工程师** | Hero slider / 货架 widget 配置与模板（单主 CTA、columns、limit）；default_injections 若本主题草稿需要 | 禁代写外国模块业务；禁双路径同身份 |
| **前端** | 店面/编辑器预览相关交互 CSS/JS（真 4 列 grid 计算、卡高）；BinQuery only | 禁原生 fetch；禁改 Framework HotCache |
| **原型** | 线稿/信息架构确认；施工后写 `acceptance-prototype.md`（有否决权） | 禁写生产业务码 |
| **UI** | 视觉落地协助 + 施工后 `acceptance-ui.md`（有否决权） | 禁另造色板 |
| **翻译工程师** | Hero CTA/可见串：模块 CSV 仅 zh+en；其它 locale→词典/实体；`i18n:collect` | 禁代跑商品翻译优化 |
| **电商顾问** | 复审运营意图 | 禁写码 |
| **后端** | HF-ED-P0-01 **已 closed** | 本波不重开除非复现 |
| **数据分析** | Pixel 引导提示（非阻断） | 本波可延后 |

## deps

```
ops-brief escalate (done)
  → HF-ED-P0-01 backend closed (done)
  → 主题 ∥ 部件 ∥ 前端 （施工）
  → 翻译（若改串，与 Hero CTA 同波）
  → 原型 ∥ UI 审查过签
  → 顾问复审
  → 测试（过签后）
  → PM 汇审
```
