# wave8-8a — 近处女 pass 门 + 固定模板策略 + deferred 真暖（架构）

- date: 2026-09-22 ~17:36+08
- seat: Team:架构师:
- channel: `framework-unreasonable-audit.md` **msg-49**（re msg-46/48/48b）
- claim_sla: **false** · 不写大码 · 禁自 reload / 禁自排施工

## 定案

1. **pass 门** = 低 rc 近处女冷 FPC MISS + trace；对照 msg-46：LayoutSlot≤1168 且求改善；残 B%≤48.3 且求降；total 求降。禁暖 HIT/高 rc 关账。
2. **用户固定模板口径（同意）**：LayoutSlot 主责 = 生成固定 PHP 模板（内测/发布 bake）；店面已发布态不应每请求重做补槽；仅可视化编辑改动才重生。
3. **现状判定**：**半冲突 → 产品意图实质冲突**。hard-cut 已 include bake，但壳仍带 marker → 每请求 `SlotFiller::fill`（chrome+include+splice+required）→ LayoutSlot~1s+。
4. **优先方向改**：从「再缓存 LayoutSlot」→「已发布态尽量零运行时补槽」（壳含 bake / 无 marker 早退 / 重路径仅 editor·preview·发布 bake）。
5. **残 B**：Theme 管 Policy/peek/remember/PostResponse；Runtime 管 deferred，公网首击前真种 header/builder Process/HotCache（不仅 FPC HTML）。禁假 HIT；禁平行 static；禁拆 no-store/fail-open。

## 对照锚点（msg-46）

| 项 | 近处女（rc≈54） |
|----|-----------------|
| total | ~2325ms |
| LayoutSlotRenderer | **1168ms** |
| 残 B | **48.3%**（builder 616 + header 406 + …） |

## 8s / 8c

| 席 | 任务 | 禁区摘要 |
|----|------|----------|
| **8s** | 零运行时补槽 | 假 HIT；平行 static；删 Slot 语义；再缓存 fill 当主解 |
| **8c** | deferred 真种 header/builder 袋 | 假 HIT；拆 no-store/fail-open；造处女；暖 HIT 关冷账 |

## escalate

@项目经理：8a stance_frozen → 可唤醒 8s/8c。
