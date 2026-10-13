# sitemap:refresh（单站）

```bash
php bin/w sitemap:refresh --website=CODE_OR_ID
# 可选：--module=Vendor_Module
```

调用 `SitemapBakeSourceInterface::refreshForBake`：Provider sync → 尝试物化 XML。  
dev/test `hard_gate` 时可能不写可索引文件，但仍同步表；命令警告并打印 `active_locs` 数量。

站解析仅经 `SeoWebsiteDirectory`（`w_query('websites')`），不依赖 Websites Model。  
全站测试仍用 `sitemap:test`。与 `website:fpc:bake` 交叉见 Websites `website-fpc-bake.md`。
