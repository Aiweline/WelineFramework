# 汇审 — mail-template-all-locales（含壳漏译 reopen）

date: 2026-09-23  
chair: Team:项目经理:  
result: **pass / 关闭**

## 范围

默认站全启用语种 × 全邮件渠道；整信（渠道正文 + 壳 UI + 品牌字段 + 预览样例）无目标语外英文漏译。

## 两波结论

| 波次 | 内容 | 结果 |
|------|------|------|
| 初版 | Resolver + 40 语种子真译 + 矩阵 | 曾汇审 pass，用户驳回壳/字段英漏 |
| reopen | 壳 seed 优先 + service_hours/topics 本地化 + shell_en 门禁 | **pass** |

## 席位 DoD

| 席位 | plan | PM DoD |
|------|------|--------|
| 后端 | be-shell-prefer-seed | pass |
| 翻译工程师 | i18n-shell-brand-hours | pass |
| 测试 | test-shell-en-gate 独立复跑 | pass（1440/`shell_en=0`） |

## 硬证据

- `matrix-shell-en-rerun.json`：1440 pass，shell_en=0，en_placeholder=0，cjk=0
- ru_RU welcome **log_id=82**：`Телефон:` / `Пн–Пт…` / `Акции`；无 Phone:/Hours:/Monday to Friday/Offers

## SESSION

全部 plan closed；本文件为终态汇审。
