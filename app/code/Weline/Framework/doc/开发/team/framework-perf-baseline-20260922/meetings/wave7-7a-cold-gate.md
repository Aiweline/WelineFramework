# wave7-7a — 近处女冷验收口径 + 机制冻结（架构）

- date: 2026-09-22 ~17:11+08
- seat: Team:架构师:
- channel: `framework-unreasonable-audit.md` **msg-40**（re msg-38/39）
- claim_sla: **false** · 不写大码

## 定案

1. **pass 门** = 低 rc 近处女冷 FPC MISS + trace；高 rc 暖样本 / 公网 HIT 仅辅证。
2. **B 双态**：暖≈1% 与处女≈68% 分账并存；不得互否。
3. **LayoutSlot Policy**：首冷 miss 有 fill+袋写税；7s 禁复辟平行 static，禁相对 msg-38 再恶化 LayoutSlot。
4. **7s/7c 禁区**：假 HIT；平行 static；拆 no-store / fail-open；自 reload；伪加速关账。

## 对照锚点（msg-38）

| 项 | 近处女（rc=55） |
|----|-----------------|
| total | ~3982ms |
| LayoutSlotRenderer | **1673ms** |
| 残 B | **68.2%** |

wave7 关账对照本表，不对照 msg-31 暖窗单独 pass。

---

## 7v 复测（msg-45 reload · Theme 2.2.563 + F 2.5.155 / S 2.0.71）

- date: 2026-09-22 ~17:20+08
- 样本：pid=**63663** · rc=**54** · FPC MISS · identity · panel-trace
- request_id=`ebf0afe773f3b4a8-671266453817166`
- **total=2324.5ms** · **LayoutSlotRenderer=1167.75ms** · A=8.9% · 残 B=48.3% · 其它=42.9%
- vs msg-38：LayoutSlot **1673→1168** · total **3982→2325** · B% **68.2→48.3**
- 公网 `/` `/products` HIT
- result=**pass**（msg-40 近处女门）· claim_sla=**false** · channel **msg-46**

