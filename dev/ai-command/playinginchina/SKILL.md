---
name: playinginchina-destination-classify
description: >-
  PlayingInChina（海外玩中国）站点专属：按主题旅行 / 地域 / 旗舰线路包给景点打分类与标签。
  Triggers: 景点分类 / 线路主题分类 / 主题旅行分类 / classify destinations /
  playinginchina classify / 丝绸之路分类 / 整理未分类景点 /
  浪漫之旅 / 自由之旅 / 异星 / 古镇 / 古城 / 古文化 / 民风 / 雪山 / 民族 / 热带 / 草原.
  Git-tracked authority (design theme doc/ is gitignored). SKIP Product 分类修复.
---

# PlayingInChina · 景点分类与线路主题（权威）

**站点专属技能。** 只服务 `app/design/Weline/playinginchina/`（PlayingInChina / overseas→China 信息套餐站）。  
**不要**套用 `产品分类修复` / 汉服类目树。

配套指令（触发词入口）：`dev/ai-command/playinginchina/景点分类.md`  
主题本地镜像（gitignore）：`app/design/Weline/playinginchina/doc/ai/skills/playinginchina-destination-classify/SKILL.md`

---

## 目标

把 `data/destinations.json` 里的景点归入可售卖的 **信息套餐货架**：

| 轴 | kind | 例子 |
|----|------|------|
| 风格 / 主题旅行 | `style` | 经典首访、丝路、浪漫、自由、异星、古文化/古镇/古城、民风、民族、雪山、热带、草原… |
| 地域 | `region` | 华北 / 华东江南 / 西南 / 华南 / 西北丝路 / 中原 / 东北（**无 cap，全量覆盖**） |
| 旗舰套餐 | `flagship` | 黄金三角、四川自然、张家界·漓江、江南水乡、云南高原、丝路与西藏 |

权威产出（可入库）：

- `app/design/Weline/playinginchina/data/route-themes.json`
- `…/data/index-by-theme.json`
- `…/data/index-destination-themes.json`

构建脚本：`app/design/Weline/playinginchina/scripts/build-route-themes.php`

---

## 触发后必做（HARD）

用户说「景点分类」等触发词时，**立即执行**，不要只口头解释：

1. **读本 SKILL + 指令** `景点分类.md`。
2. **Dirty-load** 当前 `destinations.json` / `route-themes.json` / `index-destination-themes.json`。
3. **扫描未分类**：

```bash
php app/design/Weline/playinginchina/scripts/build-route-themes.php --report-untagged
```

4. **整理未分类景点**：改 `$themes` 的 `seed_dests`/`match`，或补景点 `types`/`scenery`/`region`；保证每点 ≥1 region + ≥1 style；旗舰包保持精选。
5. **重建**：

```bash
php app/design/Weline/playinginchina/scripts/build-route-themes.php
```

期望 `untagged=0`（例外须列出）。
6. 抽检 ≥3 点 + 丝路锚点。
7. 主题本地 `doc/开发日志.md` 记数字（该路径可能 gitignore，仍写）。
8. 汇报数字与样例；未改 UI 勿宣称店面已展示。

---

## 主题清单（v3，可扩不可乱删 id）

### style
`classic-first-china` · `history-heritage` · `nature-landscape` · `photography` · `family` · `hiking-adventure` · `ancient-jiangnan` · `silk-road` · `beach-island` · `food-culinary` · `luxury` · `winter` · `religion-pilgrimage` · `wildlife` · `cycling-self-drive` · `romantic` · `free-independent` · `otherworldly` · `ancient-culture` · `ancient-town` · `ancient-city` · `night-city` · `ethnic-culture`（民族） · `folkways`（民风） · `snow-mountain`（雪山） · `tropical`（热带） · `grassland`（草原） · `karst-river`（喀斯特与江河）

区分：`ethnic-culture`=民族聚落；`folkways`=市井民俗；`tropical` 偏湿热/海南；`snow-mountain`≠`winter`。

### region
`region-north` · `region-east-jiangnan` · `region-southwest` · `region-south` · `region-northwest` · `region-central` · `region-northeast`

### flagship
`pack-golden-triangle` · `pack-sichuan-nature` · `pack-avatar-liriver` · `pack-jiangnan-water` · `pack-yunnan-highlands` · `pack-silk-tibet`

新增主题：先改 `build-route-themes.php` 的 `$themes`，rebuild，并更新本清单。

---

## 禁止

- 改 `app/code/**` 做本站分类
- 汉服/商品类目脚本
- 长尾塞满旗舰包
- 跳过脚本手改巨型 index
