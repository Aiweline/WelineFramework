# WO-BUYER-SHOW-04 · 买家秀 P1 种子完成（内容/Review 种子席）

日期：2026-09-23  
席位：Team:内容/Review种子  
对照：`pm-buyer-show-p1.md` · WO-BUYER-SHOW-04

## 目标

默认站热销汉服 Top SKU：**≥3 个 PDP** 各具备 **≥1 条已通过（approved）、含图** 的商品评论，评论区非长期空态。

## 合规写入路径（最短）

1. `ReviewMediaService::stage` — 从既有 `catalog/hanfu/…` 读字节，写入 `pub/media/review/…` 并生成 upload token（非伪造媒体 URL）
2. `ReviewService::create` — 经 `ProductReviewTypeProvider` 字段校验写入（默认 `pending`）
3. **服务层直接** `ProductReview.status = approved`（种子专用）

未走 pending→AI Cron→人工：因 `ReviewAdminService::moderate` **禁止**对 `pending` 人工抢审；AI 预审可能未启用。WO 明确允许「服务层直接 approved 种子」。

禁止项遵守：无假星展示层、无未审核公开（前台 `list` 仅 `approved`）。

## 脚本

- 路径：`app/code/Weline/Review/scripts/seed-hanfu-buyer-show-p1-approved.php`
- 命令：`php app/code/Weline/Review/scripts/seed-hanfu-buyer-show-p1-approved.php`
- 幂等：目标实体已有「approved + 含图」则跳过新建

## 种子结果

| product_id | 名称 | review_id | status | 评论图 URL | 源图（catalog） |
|---|---|---|---|---|---|
| **543** | 长乐公主 | **70** | approved | `/media/review/product/2026/09/59453a30218ed0b3cf822f0db2ec51a14e6799d4.jpg` | `/media/catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-03-c2e91b039ebb.jpg` |
| **542** | 成人汉服重工刺绣大袖衫齐 | **71** | approved | `/media/review/product/2026/09/ce22095ddc50ea9832e137d3b987ee900e7dc0b1.jpg` | `/media/catalog/hanfu/1688/factory-huazhaoji-cx/824496857539/detail-08-fc1264c1e346.jpg` |
| **245** | 初冬明制立领弓袋袖短袄马面裙汉服 | **72** | approved | `/media/review/product/2026/09/7dc086b78c7849d2b10a6d0cbb659d5db737ac62.jpg` | `/media/catalog/hanfu/1688/factory-huazhaoji-cx/997742393161/detail-08-1bf03eab4ec2.jpg` |

### 实体键

| product_id | offer_uuid | entity_uuid（写入） | entity_id |
|---|---|---|---|
| 543 | `7910baad-3cf4-5fe5-aa1e-824a143c345b` | `8eddcce3-ad79-5bd7-b0ea-1452f811cd1e` | 10022 |
| 542 | `64c6da07-760d-5e98-8091-48dc4e7c2775` | `fa5ab0a4-030f-5cf2-9bf4-69ff2eabbcd0` | 10018 |
| 245 | `b46b6aaf-7f96-56e6-9671-7b7775e126d8` | `5206e529-130b-5b0f-8c17-d4068873a325` | 5474 |

### 评论文案摘要

| review_id | 标题 | 评分 | 评论者 |
|---|---|---|---|
| 70 | 长乐公主试穿很惊艳 | 5 | 长安买家小满 |
| 71 | 刺绣大袖衫质感在线 | 5 | 汉服爱好者阿溪 |
| 72 | 明制短袄马面很合身 | 5 | 云裳买家清禾 |

## 验收抽检（本机服务层）

| 检查 | 结果 |
|---|---|
| `ReviewService::list` ×3 PDP | 各 `total=1`，非 empty；均含 `kind=image` |
| 媒体文件落盘 | 三文件均存在（约 143KB / 200KB / 219KB） |
| `ReviewAdminService::listing(status=approved)` | review_id 70/71/72 均可见，status=approved，media_count=1 |

### PDP 探活路径（店面）

- `/product/543`（长乐公主）
- `/product/542`
- `/product/245`

交付 Host 参考：`http://p05113ef3.test.weline.com:9555/product/{id}#product-reviews`

后台：商品与目录 → 评论列表 → 筛选「已通过」。

## 交接

- WO-BUYER-SHOW-03（首页 looks 聚合）可消费上述 `approved` + media。
- 本席不改主题/部件；不启 Ollama；未做破坏性 git。
