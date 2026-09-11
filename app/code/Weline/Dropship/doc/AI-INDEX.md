# AI-INDEX · Weline_Dropship

- 壳模块；勿与 Affiliate 混用（菜单「货源代发」，配置文案写「货源」不写「分销」）。
- Provider 路径：`extends/module/Weline_Dropship/DropshipProvider/*Provider.php`
- 权威：`doc/provider-development.md`、`doc/功能现状.md`
- **SystemConfig embed（硬）**：业务页 `<w:config:embed fields/field>` 必须与 `extends/module/Weline_SystemConfig/Config/{area}/*.phtml` 里 `<w:config:field key>` **字面量完全一致**。对照 Affiliate/B2B Config 页（`w-stack` + `<w:scope>` + embed + 「统一配置中心」）。自造短名（如 `platforms_enabled`）→ 红标「没有这个字段」。指南：`SystemConfig/doc/config-embed标签使用指南.md`。
- **货源平台启用**：`dropship/platforms/enabled` 须 `type="multiselect"` + `options`（标签多选，禁止逗号文本框作主交互）；新增 Provider 时同步补 `options` 项。
