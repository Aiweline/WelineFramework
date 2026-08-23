# 如何自定义 Weline Taglib 标签

权威开发与目录维护流程由本模块文档和 MCP 动态检索共同提供：

1. **场景映射（先查再用）**：`app/code/Weline/Taglib/doc/README.md`
2. **全量标签目录**：`app/code/Weline/Taglib/doc/README.md`
3. **开发与维护门禁**：`app/code/Weline/Taglib/doc/README.md`

## 摘要

1. 在**拥有该领域数据的模块**下创建 `Taglib/YourTag.php`
2. 实现 `Weline\Framework\Taglib\TaglibInterface`
3. `name()` 使用稳定名（如 `websites:website:select`）
4. 运行 `php bin/w taglib:collect`（或含收集的 `setup:upgrade`）
5. 模板使用 `<w:your-tag .../>`
6. **同一任务内**更新本 README 的场景映射及相关专题文档，MCP 会在下一次受保护调用前自动重索引

## 不要做

- 不要用手写 `<select>` + 跨模块 Model 替代已有官方选择器标签
- 不要改 `generated/` 或收集产物
- 不要在 `<w:*>` 属性里写 `<?= ?>`
- 不要把所有 UI 都做成 Taglib（优先 layout / partial / component / widget）

## 深入阅读

- `app/code/Weline/Framework/doc/2-快速开始/06-自定义标签.md`
- `app/code/Weline/Framework/View/doc/Taglib/使用指南.md`
- `app/code/Weline/Taglib/doc/README.md`
- 范例：`Weline\Websites\Taglib\WebsiteSelect`、`Weline\I18n\Taglib\LanguageSelect`
