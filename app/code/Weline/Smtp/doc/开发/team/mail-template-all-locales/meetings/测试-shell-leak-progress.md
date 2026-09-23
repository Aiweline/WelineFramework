# 测试 progress — test-shell-en-gate（壳层英标门禁）

date: 2026-09-23  
seat: Team:测试:  
plan_id: test-shell-en-gate  
result: **progress（门禁已落地 · 矩阵真实红 · 禁假绿）**  
notify_pm: true  

@项目经理：本席已交付/上报壳层英标门禁与红灯证据，请检查并更新 SESSION。等 `i18n-shell-brand-hours`（及必要时后端）把 `service_hours` 等真译后，本席再独立复跑全矩阵 + persist-preview；全绿再写 `测试-shell-leak-closed.md`。

## 结论（本回合）

| 项 | 状态 |
|----|------|
| 扩展矩阵 `shell_en_leak` | **done** |
| `topics_label` locale 本地化样例 | **done**（ru=`Акции / Новинки`，禁 `Offers / New arrivals`） |
| 契约 UT | **done**（壳标单元 + topics 断言绿；抽样格 skip 因 DB） |
| 全矩阵 40×36=1440 | **跑完 · verdict=fail**（真实红） |
| ≥5×3 persist-preview（含 ru_RU welcome） | **done（15 条）** |
| 证明无 Phone:/Hours: | **部分**：ru_RU welcome 可见壳标已是 `Телефон:`/`Часы работы:`/`Адрес:`/`Нужна помощь?`；仍命中品牌 `Monday to Friday` → **不得判绿** |

## 门禁设计（防汇审假绿）

对非 `zh_*`、非 `en_*` 的可见 subject + wrapped content：

- `Phone:` / `Hours:` / `Address:`（允许空白：`Phone :`）
- `Need help?`
- `Monday to Friday` / `Monday-Friday` / `Mon-Fri` / `Mon to Fri`

用正则 + 冒号/问号形态；先 `visibleText` 去标签，避免 brand URL 路径误杀。

失败 reason：`shell_en_leak:…`；计数：`counts.shell_en_fails`。

## 矩阵计数（本机真实跑）

```text
locales=40 channels=36 cells=1440
verdict=fail
pass=108 fail=1332
en_placeholder_fails=0
shell_en_fails=1332
cjk_fails=180
uc1_fails=0
```

- `pass=108` = `zh_Hans_CN` + `en_US` + `en_GB`（跳过壳英标门）。
- **几乎全非英格**命中 `shell_en_leak:Monday to Friday`（品牌 `service_hours` 仍英文）——若无此门禁会假绿（`en_placeholder=0`）。
- `cjk=180`：`da_DK/de_DE/nb_NO/nl_NL/sv_SE`×36，命中品牌串「客服邮箱」（独立问题，非 topics 样例引入）。

证据：`app/code/Weline/Smtp/test/evidence/matrix-shell-en-gate.json`

```bash
php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php \
  --persist-preview \
  --preview-locales=de_DE,it_IT,ru_RU,pl_PL,nl_NL \
  --preview-channels=Weline_Newsletter::subscribe_gift,Weline_Newsletter::subscribe_welcome,Weline_Order::order_created \
  --json-out=app/code/Weline/Smtp/test/evidence/matrix-shell-en-gate.json
```

## ru_RU × subscribe_welcome 抽检

| 项 | 结果 |
|----|------|
| 渠道正文俄文 | ✓ |
| `topics_label` | `Акции / Новинки`（非 Offers） |
| `Phone:` / `Hours:` / `Address:` / `Need help?` | miss（壳标已俄） |
| `Monday to Friday` | **HIT**（`service_hours`）→ `shell_en_leak` |
| preview log_id | **67** |

## 预览（≥5×3，禁止单条交差）

Host：`https://p05113ef3.test.weline.com:9555`  
prefix：`jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH`

| log_id | locale | channel |
|--------|--------|---------|
| 60 | de_DE | subscribe_gift |
| 61 | it_IT | subscribe_gift |
| 62 | ru_RU | subscribe_gift |
| 63 | pl_PL | subscribe_gift |
| 64 | nl_NL | subscribe_gift |
| 65 | de_DE | subscribe_welcome |
| 66 | it_IT | subscribe_welcome |
| **67** | **ru_RU** | **subscribe_welcome** |
| 68 | pl_PL | subscribe_welcome |
| 69 | nl_NL | subscribe_welcome |
| 70 | de_DE | order_created |
| 71 | it_IT | order_created |
| 72 | ru_RU | order_created |
| 73 | pl_PL | order_created |
| 74 | nl_NL | order_created |

embed 例：[ru_RU welcome log_id=67](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=67&embed=1)

## 交付物

| 路径 | 作用 |
|------|------|
| `test/Support/MailTemplateLocaleMatrixRunner.php` | `SHELL_EN_LABEL_PATTERNS` + `findShellEnLabels` + `localizedTopicsLabel` |
| `test/Unit/MailTemplateLocaleMatrixContractTest.php` | 壳标/topics 单元断言 |
| `doc/开发/spec/mail-template-locale-matrix.md` | 第 4 条壳英标门禁 |
| `scripts/mail-template-locale-matrix.php` | 摘要输出 `shell_en=` |

## escalate / 下一步

1. **翻译席** `i18n-shell-brand-hours`：按 locale 真译 `service_hours`（及必要时 address）；消掉「客服邮箱」等 CJK 品牌漏译。
2. 后端若仍有中文→英回落路径，继续 `be-shell-prefer-seed`。
3. 上游完成后本席复跑；期望 `shell_en_fails=0` 且 `cjk_fails=0` → 写 `meetings/测试-shell-leak-closed.md`。

## 未写 closed 的原因

矩阵仍红（`Monday to Friday` / 部分 CJK）。门禁已能抓住汇审假绿场景，但按「禁假绿」不得在整信未净前 closed。
