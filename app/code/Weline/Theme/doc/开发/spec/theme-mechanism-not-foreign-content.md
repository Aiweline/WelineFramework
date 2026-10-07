# Theme 只负责机制 · 禁止代管外模块功能布局/模板

> 冻结：2026-10-07（抽象升级：由认证页纠偏推广为模块边界通则）  
> 权威交叉：[Theme开发总指南.md](../Theme开发总指南.md) §6.0；[theme-inheritance-and-file-conventions.md](../../theme-inheritance-and-file-conventions.md)；[布局固化与默认注入.md](../../布局固化与默认注入.md)；MCP `theme_mechanism_not_foreign_content` / `theme_layout_widget_owner` / `theme_design_must_inherit_not_mutate_source_widgets`

## 一句话（抽象通则）

**各自功能由各自模块自己管理布局与模板；`Weline_Theme` 只提供主题机制（含让 `app/design` 主题继承/重写的能力），禁止 Theme 模块去兜底、代持、空挂其它业务模块的功能布局与主内容模板。**

登录归 Customer、商品归 Product、结账归 Checkout——这是**举例**，不是规则本身。规则是：**谁拥有业务，谁拥有该业务的布局/模板；Theme 不越权。**

## Theme 负责（机制）

| 机制 | 说明 |
|------|------|
| 主题继承与覆盖 | `app/design` 同 key 覆盖 layout/partial/widget；Token / colors / variables / 独立品牌 CSS |
| 布局 / partial / slot 宿主 | 暴露空槽、协议、`accept`；不代替业务模块填主内容 |
| 固化与注入运行时 | `generated/theme-layout-entities/`、required 注入读写、XOR |
| 全局 chrome | header/footer 等 Theme **自有**部件与皮肤 Token |
| Theme 自有可复用部件 | 仅挂 **Theme 拥有的布局/槽** 或编辑器可选拖拽 |
| 扩展槽给外国模块 | 槽可由 Theme 提供，**注入声明在拥有模块** |

## Theme 不负责（外模块功能）

| 禁止 | 正确 |
|------|------|
| Theme 用 required `default_injections` / 自有布局 **兜底** 外模块 path 页主内容 | **拥有模块**自管布局 + 本模块 content / `fetch` |
| Theme 把外模块表单/主流程模板挪进 Theme 树「代管」 | 模板落在拥有模块；design 主题需要外观时走 **继承覆盖**，不改归属 |
| 以固化 / `theme:upgrade` 掩盖错误归属 | 固化不能把错误归属变正确 |

历史反例（仅作说明，非规则边界）：曾用 Theme `form/account-login` 必装注入 Customer 登录 → Theme 未固化则登录空白。正确归 **Customer**。同类问题适用于任意业务模块，不限于登录。

## 与 design 继承的关系

- **业务归属**不变：功能布局/模板仍在拥有模块（或该模块已声明的贡献层）。  
- **外观定制**：有 design 主题时，在 `app/design/{Vendor}/{theme}/` **继承重写**（硬规则 `theme_design_must_inherit_not_mutate_source_widgets`）；禁止为某一皮肤去改共享源头，也禁止把业务塞进 Theme「方便兜底」。

## 判据（Agent / 席位）

动手前自问：

1. 这块 HTML **缺了谁页面就废**？→ 那个模块拥有，不是 Theme。  
2. Theme 是否在 **替外模块交付主内容 / 兜底布局**？是 → 停，改回拥有模块。  
3. Theme 是否只提供 **机制 + chrome + 主题重写能力**？否 → 越界。

## 相关

- MCP：`theme_mechanism_not_foreign_content`  
- 会话纠偏纪要可落 `dev/session/`（gitignored）；结论写入本规格与开发日志。
