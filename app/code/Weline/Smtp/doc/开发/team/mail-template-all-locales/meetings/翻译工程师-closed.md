# 翻译工程师 closed — mail-template-all-locales

date: 2026-09-23  
seat: Team:翻译工程师:（别名 i18n）  
plan_id: i18n-translate-seeds + i18n-websites-en-leak  
result: **closed / pass**  
notify_pm: true

@项目经理：本席已交付/上报，请检查并更新 SESSION。请立刻唤醒 Team:测试: 重跑矩阵（本席已自跑全矩阵绿）。

## 结论

| 项 | 状态 |
|----|------|
| 默认站 40 语种子真译 | **done**（JSON 38 非基线语种 + zh/en 基线文件） |
| 全渠道 `view/email/**` 替换 en 占位 | **done** |
| Websites `notify_domain_*` en_US 去中文 | **done** |
| `materializeFiles` + `syncAll` | **done** |
| 模块 CSV 仅 zh+en | **N/A**（本波未改模块 CSV；未跑 Ollama） |
| 全矩阵自验 | **pass**：1440/1440，`en_placeholder_fails=0`，`cjk=0`，`uc1=0` |

## 规模

| 指标 | 值 |
|------|-----|
| 默认站启用语种 | 40 |
| `mail_template_seed_copy.json` 真译 pack | 38（除 zh_Hans_CN / en_US） |
| 邮件 slug 目录（含 notification / marketing / newsletter / domain / pixel 等） | 24 |
| 落盘 `.html` 约 | 930 |
| DB sync | `syncAll(SCOPE_GLOBAL)`；末次增量 updated=4（mailing list 返工）后与全量一致 |

### 已真译 locale 清单（38）

`ar_SA, bg_BG, bn_BD, ca_ES, cs_CZ, da_DK, de_DE, el_GR, en_GB, es_ES, es_MX, et_EE, fi_FI, fr_CA, fr_FR, ga_IE, hi_IN, hr_HR, hu_HU, id_ID, is_IS, it_IT, lt_LT, lv_LV, mt_MT, nb_NO, nl_NL, pl_PL, pt_BR, pt_PT, ro_RO, ru_RU, sk_SK, sl_SI, sv_SE, tr_TR, uk_UA, ur_PK`

基线文件：`zh_Hans_CN` + `en_US`（域名通知 en 已英文化）。

## 抽检（subscribe_gift subject + body）

| locale | subject 要点 | body h1 要点 | Welcome gift? |
|--------|--------------|--------------|---------------|
| de_DE | `· Abo-Geschenk ·` | `· Willkommensgeschenk` | 否 |
| it_IT | `· Regalo iscrizione ·` | `· Regalo di benvenuto` | 否 |
| ru_RU | `· Подарок за подписку ·` | `· Приветственный подарок` | 否 |

**ja_JP / ko_KR**：默认站 `WebsiteLanguage` **未启用**（不在 40 语内，矩阵无此二 locale）。按 PM 点名无法落库抽检；等价非中英语抽检见上表 + 全矩阵 1440 绿。

预览 log（真译后）：

- de_DE gift log_id=42  
- it_IT gift log_id=43  
- ru_RU gift log_id=44  

证据：`app/code/Weline/Smtp/test/evidence/matrix-after-i18n.json`（verdict=pass）  
命令：

```bash
php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php \
  --json-out=app/code/Weline/Smtp/test/evidence/matrix-after-i18n.json
# → verdict=pass total=1440 en_placeholder=0 cjk=0 uc1=0
```

## Websites en leak

`notify_domain_expiring|notify_domain_pool_resolve_off_local|notify_domain_transfer` 的 `en_US.html/.subject.txt` 已改为英文（Domain expiring soon / Domain pool DNS drift / Domain transfer notice）。渠道实际种子路径多为 `Websites/view/email/notification/{locale}`（亦已覆盖全语种）。

## 附带修复（否则矩阵假红）

测试矩阵在 `buildEnMarkersForChannel` 解析 `en_US` 时复用 `SmtpMailTemplate` OM 单例，覆盖了已 resolve 的目标语模板引用 → 真译仍判 `en_placeholder`。已：

1. `MailTemplateLocaleMatrixRunner`：resolve 后立即快照 subject/body  
2. `MailTemplateResolver::loadRow`：`clone` 脱离单例  

否则 480 假红会挡住测试席重跑。

## channel

请测试席按 SESSION 重跑全矩阵；期望 `en_placeholder_fails=0`。若需 materialize 再 sync，入口同后端：

```php
MailTemplateSeedCopyCatalog::materializeFiles();
ObjectManager::getInstance(MailTemplateSeeder::class)->syncAll(\Weline\SystemConfig\Model\SystemConfig::SCOPE_GLOBAL);
```
