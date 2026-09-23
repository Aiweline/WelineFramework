# 测试 closed — test-shell-en-gate（壳层英标门禁）

date: 2026-09-23  
seat: Team:测试:  
plan_id: test-shell-en-gate  
result: **closed / pass**  
notify_pm: true  

@项目经理：本席已交付/上报，请检查并更新 SESSION。

## 结论

| 项 | 状态 |
|----|------|
| 独立复跑全矩阵 40×36=1440 | **pass** |
| `shell_en_fails` | **0** |
| `en_placeholder_fails` | **0** |
| `cjk_fails` | **0**（此前 progress 的 180 已随翻译/壳修复消除，非误杀） |
| persist-preview ≥5×3（含 ru_RU welcome） | **15 条** |
| ru_RU welcome 无 Phone:/Hours:/Monday to Friday/Offers | **pass**（log_id=82） |

禁止假绿：本席自跑 CLI，未采信翻译自检。

## 矩阵计数（本机独立跑）

```text
matrix verdict=pass total=1440 pass=1440 fail=0
en_placeholder=0 shell_en=0 cjk=0 uc1=0
locales=40 channels=36
```

证据：`app/code/Weline/Smtp/test/evidence/matrix-shell-en-rerun.json`

```bash
php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php \
  --persist-preview \
  --preview-locales=de_DE,it_IT,ru_RU,pl_PL,nl_NL \
  --preview-channels=Weline_Newsletter::subscribe_gift,Weline_Newsletter::subscribe_welcome,Weline_Order::order_created \
  --json-out=app/code/Weline/Smtp/test/evidence/matrix-shell-en-rerun.json
```

## ru_RU × subscribe_welcome（log_id=82）

| 检查 | 结果 |
|------|------|
| `Phone:` / `Hours:` / `Address:` / `Need help?` | miss |
| `Monday to Friday` / `Offers` / `Offers / New arrivals` | miss |
| `Телефон:` / `Часы работы:` / `Нужна помощь?` / `Акции` | HIT（目标语） |
| `service_hours` | `Пн–Пт 9:00–18:00 (кроме государственных праздников)` |
| `shell_en_hits` / `cjk_hits` | `[]` |

embed：[ru_RU welcome log_id=82](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=82&embed=1)

## 预览（≥5×3，禁止单条交差）

Host：`https://p05113ef3.test.weline.com:9555`  
prefix：`jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH`

| log_id | locale | channel |
|--------|--------|---------|
| 75 | de_DE | subscribe_gift |
| 76 | it_IT | subscribe_gift |
| 77 | ru_RU | subscribe_gift |
| 78 | pl_PL | subscribe_gift |
| 79 | nl_NL | subscribe_gift |
| 80 | de_DE | subscribe_welcome |
| 81 | it_IT | subscribe_welcome |
| **82** | **ru_RU** | **subscribe_welcome** |
| 83 | pl_PL | subscribe_welcome |
| 84 | nl_NL | subscribe_welcome |
| 85 | de_DE | order_created |
| 86 | it_IT | order_created |
| 87 | ru_RU | order_created |
| 88 | pl_PL | order_created |
| 89 | nl_NL | order_created |

JSON 中全部 15 条 `shell_en_hits=[]`、`cjk_hits=[]`。

## 门禁交付回顾

| 路径 | 作用 |
|------|------|
| `test/Support/MailTemplateLocaleMatrixRunner.php` | `shell_en_leak` + locale `topics_label` |
| `test/Unit/MailTemplateLocaleMatrixContractTest.php` | 壳标/topics 单元 |
| `doc/开发/spec/mail-template-locale-matrix.md` | 第 4 条壳英标 |
| 前序 progress | `meetings/测试-shell-leak-progress.md`（真红证据保留） |

## related_web_urls

- [ru_RU welcome embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=82&embed=1)
- [发件记录 listing](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log/listing)
