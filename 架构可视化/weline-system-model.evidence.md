# 证据索引 v2（五通道）

生成日期: 2026-10-05 · current-state, L3 模块级 · v1→v2: 新增 event/extends/hooks 机制通道并修正边语义判定

## 通道与提取方法

| 通道 | 来源 | 规模 | 提取 |
|---|---|---|---|
| requires（排序权威） | `app/code/Weline/*/etc/module.php` | 450 可解析边 | `/tmp/weline-modules.json`（PHP include，兼容 Dropship/CjDropshipping 数字列表格式） |
| optional（suggest 对账） | 同上 | ~165 边 | 同上；消费方 `Framework/Architecture/ComposerMetadataValidator.php:79` |
| provides（DI 绑定） | 同上 + `generated/` ServiceProviderRegistry | 68 模块声明 | `ObjectManager.php:146-205,761`（"provides is authoritative"） |
| event.xml（观察者总线） | `app/code/Weline/*/etc/event.xml` | 72 模块 / 跨模块订阅 78 对 | SimpleXML 解析 `<event name="Weline_X::ev">`，owner=前缀模块 → `/tmp/event-edges.json` |
| extends（契约装配） | `app/code/Weline/*/extends/module/<Target>/` | 54 模块 / 122 处 | 目录名即目标模块 → `/tmp/extends-edges.json`；规约源 `*/extends.php`、扫描器 `Framework/Extends/ExtendsScanner.php`、校验 `CompletenessChecker.php` |
| view/hooks | `app/code/Weline/*/view/hooks/*.phtml` | 18 模块 / 43 文件 | 文件名=钩子名（`Framework/Hook/readme.txt`） |
| 在场门禁 | — | — | `Framework/Registry/Service/RegistryModulePresence.php`；消费点 EventRegistry×6、Extends/Hook/Plugin Registry |

合并产物: `/tmp/mech-classified.json`（requires+optional+provides+mech[200]+hooks）。

## 排序链证据

- `Framework/Register/Register.php:890-940`：requires ∪ register.php deps ∪ env deps → `Sort::dependenciesSort` DFS。注释确认 etc/module.php 为权威（修复 I18n 首装乱序事故）。
- optional 未进入该链（全 Framework grep 仅 Manifest/Validator 消费）。

## 关键量化结论

- 扇入 TOP: Framework 78 · Backend 63 · SystemConfig 37 · I18n 24 · Websites 22 · Theme 19 · Admin 17
- 事件 owner 热度: Framework←32 · Order←14 · Server←13 · Checkout←11 · Websites←10 · Acl/Theme←8
- 未声明机制边 58 对（A extends/订阅 B 但无 requires/optional 声明）：SiteSetupAssistant←13、Widget←11 为大户——机制允许（门禁兜底），属文档纪律问题。
- Tarjan SCC = 空 → requires 图为 DAG，零循环依赖。
- 幽灵 requires: `UrlManager→ModuleManager`（grep 全模块 0 代码引用）。

## 修正记录（v1 误判留痕）

- v1 判"平台→业务倒挂 6 条"为缺陷；v2 核实其中 `Widget→Ai`、`SessionManager→Customer`、`Widget→Extends` 是 extends/hook/provides 机制的**设计内登记**，收回缺陷判定。教训：先学机制再判定（已写入项目记忆）。

## 再生成

```bash
# 1) 刷新三个 JSON（命令正文见本报告会话记录; 或按上表"提取"列重写）
# 2) php 架构可视化/generate-module-dot-v2.php
# 3) dot -Tsvg weline-core-overview.dot -o weline-core-overview.svg
#    dot -Tsvg weline-module-dependencies-full.dot -o weline-module-dependencies-full.svg
# 4) cd 架构可视化 && php -S 127.0.0.1:8931   # http://127.0.0.1:8931/
```
