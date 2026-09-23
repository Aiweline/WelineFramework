# 翻译工程师 closed — i18n-shell-brand-hours（壳漏译 reopen）

date: 2026-09-23  
seat: Team:翻译工程师:（别名 i18n）  
plan_id: i18n-shell-brand-hours  
result: **closed / pass**  
notify_pm: true

@项目经理：本席已交付/上报，请检查并更新 SESSION。后端 be-shell-prefer-seed 已过 DoD；本席补齐品牌 `service_hours` + topics 样例真译，请唤醒测试席跑 `test-shell-en-gate`。

## 结论

| 项 | 状态 |
|----|------|
| `website-brand-local-copy.v1.php` 全启用语种 `service_hours` 真译 | **done**（39 非中文 + zh 源串由 getter 返回） |
| `topics_label` 预览样例本地化（禁 Offers / New arrivals） | **done**（同包 + MailBrandContext 直读） |
| seed shell pack 校对（phone/hours/address 无英回落；Support: 德/北欧等改为目标语） | **done** |
| LocaleDictionary 纠正「客服电话：」等 ru/it 误英 Phone:/Hours: | **done**（273 writes + publishLocale） |
| MailBrandContext 读到本地 hours（非 Monday to Friday） | **done**（pack 优先 + skipLocalize） |
| Ollama | **未使用** |

## 根因（本席范围）

1. `SiteContactInfo` 空配置回落 `__('周一至周五…')`，CLI/邮件 locale 未绑定时先变成 Theme 英文；`localizeBrandStrings` 再译英文串 → 词典无键 → 整信仍 `Monday to Friday…`。
2. LocalDescription 仅有 name/description，**无** service_hours 列；须在品牌本地包落真译并由运行时直读。
3. 系统词典曾把「客服电话：」误写成 `Phone:`（ru/it）；壳标签现已由后端 seed 优先，本席仍纠词典防回落。

## 落盘

| 路径 | 说明 |
|------|------|
| `Websites/Service/data/website-brand-local-copy.v1.php` | 增 `service_hours` + `topics_label` |
| `Websites/Service/WebsiteBrandIdentitySeedService` | `serviceHoursForLocale` / `topicsLabelForLocale` / `loadLocalBrandCopyPack` |
| `Smtp/Service/MailBrandContextService` | resolve 优先 pack hours；`buildPreviewSamples` 优先 pack topics |
| `Smtp/Service/data/mail_template_seed_copy.json` | da/de/nb/nl/sv `support` 去纯英 Support: |
| `I18n/scripts/data/dict-fill-mail-shell-brand-hours.v1.php` + remediate | 壳标 + 营业时间中/英源 + topics 词典 upsert |

ensure：`ensureDefaultWebsiteLocalBrandCopy` → upserted=0 skipped=40（Local 行已齐；hours/topics 运行时读包）。

## 抽检（MailBrandContext resolve + preview samples）

| locale | service_hours | topics_label | Monday… / Offers… |
|--------|---------------|--------------|-------------------|
| ru_RU | Пн–Пт 9:00–18:00 (кроме государственных праздников) | Акции / Новинки | 无 |
| de_DE | Montag bis Freitag 9:00–18:00 Uhr (außer an gesetzlichen Feiertagen) | Angebote / Neuheiten | 无 |
| it_IT | Lunedì–venerdì 9:00–18:00 (esclusi i giorni festivi) | Offerte / Novità | 无 |
| zh_Hans_CN | 周一至周五 9:00 - 18:00（法定节假日除外） | 优惠 / 新品 | N/A |
| en_US | Monday to Friday…（允许） | Offers / New arrivals（允许） | N/A |

词典抽检：`ru_RU`/`it_IT` 「客服电话：」→ Телефон: / Telefono:；「服务时间：」→ Часы работы: / Orari:。

## 命令

```bash
php -d memory_limit=512M app/code/Weline/I18n/scripts/remediate-dict-fill-mail-shell-brand-hours.php --apply
# → mode=apply words=7 writes=273 missing=0 locales=39
```
