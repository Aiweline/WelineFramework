---
status: implemented
work_kind: bugfix
feature_slug: setup-upgrade-theme-hang
module: Weline_Framework
updated: 2026-09-20
---

# setup:upgrade Theme 卡死双轨修复

## 澄清结论

| 点 | 决定 |
|----|------|
| 根因 A | CLI `__()` → Phrase 全量加载 `w_i18n_locale_dictionary`；`shouldSkipHeavy` 仅 WLS |
| 根因 B | Theme `Setup/Upgrade` 无 from-gate，小 bump 重跑 cutover/bake |
| Phrase | 仅 `setup:upgrade` 入口 `Parser::setSetupUpgradeLightDictionary` |
| Context | 注入 `from_setup_version` / `getFromSetupVersion()` |
| Theme | `from >= VERSION(2.2.480)` 跳过历史重迁移 |
| 非目标 | 不改 I18n 表；不永久关闭 CLI 词典；不重写迁移语义 |

## EARS

1. WHEN 执行 `setup:upgrade`，系统 SHALL 在进程入口启用轻词典 flag，并在 finally 清除。
2. WHEN 轻词典 flag 为真，Phrase SHALL 跳过全局 DB 词典整表 hydrate。
3. WHEN Handle 构造 Setup Context，系统 SHALL 注入升级前 `setup_version` 为 from。
4. WHEN Theme `from_setup >= Upgrade::VERSION`，系统 SHALL 跳过本脚本内全部历史重迁移。

## 验收

- UT：`ParserSetupUpgradeLightDictionaryTest`、`SetupContextFromSetupVersionTest`、`ThemeUpgradeVersionGateContractTest` PASS（15 tests）
- 手工：`php bin/w s:up -m Weline_Framework` — ModuleSetup 段秒级 `Updated!`；无 `zend_array_dup` 打满；`setup_version`→`2.5.110`
- Theme：`2.2.486→2.2.487` 已秒级 Updated（from-gate 跳过历史重迁移）
