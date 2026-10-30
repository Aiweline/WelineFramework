# 商品 AI 优化（设计归档）

后台「商品 AI 优化中心」的产品设计、可点击原型与 S1 实施计划留档。**当前状态（2026-10-30）：`Model/Ai`（AiJob/AiDraft 两张持久化表）与 `Service/Ai`（生成/审核/应用 7 个服务）已落盘并通过 `php -l` 与纯逻辑冒烟；`Queue/`、`Controller/Backend/`、`view/`、`extends/`、`etc/`、`Setup/` 仍未获授权，故建任务入口按 fail-closed 返回 `product_ai_queue_unavailable`，两张表也需授权 `Setup/Upgrade.php` 或模块版本号后才能由 `setup:upgrade` 建表。** 授权边界见 `S1-实施计划.md` §0。

## 文件

| 文件 | 内容 |
|---|---|
| [PRD.html](./PRD.html) | 单文件 PRD（策略、现状诊断、功能清单 F01–F13、技术方案、数据埋点、验收、风险、排期） |
| [S1-实施计划.md](./S1-实施计划.md) | 文件级实施计划：20 条已核对代码事实、2 张新表 DDL、14 新增 + 7 修改文件与插入点、5 条关键流程、错误码、模型标签接线、验收命令、风险、17 人天排期 |
| [prototype/ai-review-center.html](./prototype/ai-review-center.html) | 可点击原型：队列 + 审核双栏、字段级 diff、候选图选择、版本冲突、批量建任务 |
| [prototype/verify.mjs](./prototype/verify.mjs) | 原型交互回归（点击/采纳/应用/冲突/弹窗/筛选 + 控制台错误 + 横向溢出） |
| [prototype/verify-responsive.mjs](./prototype/verify-responsive.mjs) | 三档视口回归：1280×900、1024×900、1440×1200 |

## 打开与复验

```bash
# 打开原型：直接浏览器打开，无需服务
open app/code/Weline/Product/doc/product-ai-optimization/prototype/ai-review-center.html

# 交互回归（需本机 Chrome；可用 CHROME_PATH 覆盖路径）
node app/code/Weline/Product/doc/product-ai-optimization/prototype/verify.mjs
node app/code/Weline/Product/doc/product-ai-optimization/prototype/verify-responsive.mjs
```

两个脚本把截图写到仓库根 `.screenshots/`（`.gitignore:339` 已忽略，不入库），退出码非 0 即失败。

## 设计要点（需要评审的两处）

1. **应用语义**：确认应用 = 一次 `ProductAdminCommand::ACTION_SAVE` 直写线上，不做草稿层；字段级 `before_hash` 比对拦并发覆盖，冲突字段单独重生成。见 `S1-实施计划.md` §5。
2. **模型选择**：不新增「模型标签表」，标签即任务类型 → Ai 场景适配器绑定；单次运行可覆盖，未绑定在创建任务时就失败，不落队列。见 §7。
