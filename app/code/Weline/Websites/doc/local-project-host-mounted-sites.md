# 本机项目 Host 的商城挂载目录

`DetectWebsite::expandSitesWithDomains()` 发布网站和有效 WebsiteDomain 的入口，`Url::parser()` 使用最长 base URL 匹配先剥挂载路径，再识别 API 区域。标准本机项目裸 Host 归默认网站。

**合成挂载（推荐编辑/截图入口）**：每个 URL-safe 的 `Website.code` 自动发布虚拟入口 `https://{projectHost}/~site/{code}`（`ProjectHostSiteMount`），**不**写入 `WebsiteDomain`。探测顺序：`/~site/{code}` → 非空 `WebsiteDomain.sub_path` → 裸 Host 默认站。`/~site` 为保留段，禁止作为域名 `sub_path`。

同 Host 下遗留显式非空路径（如 `/daocharms`）仍可进入候选表。`addExpandedSiteUrls()` 只排除标准项目 Host 的空路径或 `/`，保留带路径入口。此前 helper 无条件排除此 Host，虽调用者允许非空 `sub_path`，入口仍被丢弃。冷进程注册表只含裸 Host 时，挂载 API 会误绑默认站；虚拟 `/~site/{code}` 与非空 `sub_path` 都必须进目录。

最小开发验证：同一 helper 对裸 Host 产生 0 行，对 `/daocharms` 保留原 Host 和既有 www 别名；原生 parser 对 mounted query-bin 得 `rest_frontend`、正确 Website ID 与 `/framework/query-bin`。`DetectWebsite` 的目录数组归 `global/websites-registry` namespace；版本 bump 必须同时失效这个 namespace，原默认 pool 的 clear 不保证包含此显式 owner namespace。`Url::bumpWebsiteParserSitesVersion()` 先调用原生 namespace facade 的 clear，再保持原版本键和进程版本发布。修改目录生成逻辑后，调用原生 `Url::bumpWebsiteParserSitesVersion()` 更新注册表版本和 owner 缓存，并刷新 WLS。不要放宽 Scope guard，也不要增加前端伪造 Scope 或 REST fallback。

运行验收需要独立冷页面执行真实 Worker bootstrap、搜索、商品购买面板和账户接口；HTTP 缺 body 的 400 只属于开发证据。
