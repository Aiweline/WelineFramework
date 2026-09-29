# Theme 纯 PHTML 专项运行验收

这组用例操作真实编辑器、API、数据库与产物。它需要已准备好的独立店铺渠道；不会在收集测试时创建店铺，也不会回退到默认 owner。

默认读取 `dev/tmp/theme-phtml-solidification/runtime-browser-store.json`。文件不存在或其中 Store/Channel 已清理时，本组用例显示 **skipped（未执行）**，不影响其它 E2E 收集，不能计作验收通过。通过 `PHTML_FIXTURE_PATH` 指定其它验收文件，`PHTML_EVIDENCE_DIR` 指定该轮独立证据目录。读取在 `beforeAll` 中进行；PHP 观测器使用同一文件并核对真实 Store/Channel 和 canonical scope。

## 实际环境依赖

- 使用项目正式 WLS 与 HTTPS 域名；Store URL 必须能路由到真实 `homepage/default`。不替换 Host、不关闭 H2、不拦截或伪造响应。
- fixture 由现有 Websites 模型/管理流程准备为唯一 Store 与 Channel，保留原主题继承关系及精确清理 ID。Store code 必须是 `e2e_theme_` 加该轮 `phtml_*` token，不能使用默认 Store。typed identity 与 canonical scope 必须来自 `ScopeHierarchyInterface`，不要手拼 scope。
- JSON 字段：`theme_id`、`website_id`、`store_id`、`channel_id`、`scope`、`identity`、`storefront_url`、`token`。`identity` 是完整 channel `ScopeIdentity::toArray()`。
- 图文与语言用例需要至少两个现有真实 FileAsset 图片引用。可将从正式媒体选择器/模型取得的 `file-image` 对象放入 fixture 的 `image_sources` 数组；也可复用本轮真实 workspace 或 `runtime-before-flow.json` 中已读取的引用。不能构造不存在的 asset_id。
- 主流程会仅为该隔离渠道保存 Theme 绑定、创建草稿与发布。使用正式 `tests/e2e/framework` 后台登录 helper，不需要外部 Chrome。

## 运行入口

从仓库根运行，并按当前部署填入实际 origin：

```sh
PHTML_FIXTURE_PATH=/absolute/path/fixture.json \
PHTML_EVIDENCE_DIR=/absolute/path/evidence \
PLAYWRIGHT_DISABLE_PROXY=1 \
PLAYWRIGHT_TARGET_ORIGIN=https://configured-wls-origin \
php bin/w e2e:run app/code/Weline/Theme/test/e2e/backend/theme-phtml-solidification.spec.js \
  --project=chromium --case-id=UC-PHTML-01-02 --workers=1
```

`UC-PHTML-01-02` 是连续 A/B 编辑、首草稿 Token、历史重编、卸载、语言与发布流程。其它 case 是单项续验，开始前应核对其输入：

- `UC-PHTML-EMPTY-HTTP`：当前首页有内容；真实保存全空，校验 DB/PHTML/同身份 HTTP 和公共部分。
- `UC-PHTML-SAVE-LAYOUT-COMPAT-HTTP`：真实旧分组表单保存，核对 UID/槽/顺序、false/null/空字符串与 HTTP 回读。
- `UC-PHTML-WRAPPER`、`UC-PHTML-CANVAS-LOCALE`：实际 iframe DOM、可见表单和图像加载，HTTP 结果不能替代它们。语言专项要求先正常清空再通过兼容表单创建A；读取/切换阶段直接SELECT核版本稳定，并离线解码实际WQB1请求确认无自动reconcile。
- `UC-PHTML-04-TWO-SESSIONS`：两个真实不同后台 session 通过正式bootstrapOnly登录，不打开编辑器，再并发保存；必须收到成功与冲突回执及两个实际Worker记录，连接断开不算冲突通过。两请求各带唯一原生trace ID，完整原始trace按0600保留并如实报告截断/缺失。
- `UC-PHTML-CURRENT-DRAFT`：当前 D 独立改动后再从当前创建 D，验证未发布内容继承。

`PHTML_RESUME_*` 仅用于从本轮已保存回执继续，不是新环境的准备入口。`PHTML_RESUME_EMPTY_HTTP=1` 只读核验已保存的同一 V/R，不重发全空保存。

`PHTML_RESUME_COMPAT_HTTP=1` 只读续验同一份 `runtime-save-layout-compat.json` 的精确V/R，跳过保存并保留原始证据。复用UID时，基础配置与已有en_US语言覆盖分别从实际widget-config读取，不因语言显示差异删除翻译。`PHTML_RESUME_REQUIRED_VERSION=<当前D>` 配 `PHTML_RESUME_REQUIRED_EMPTY_ONLY=1` 仅执行正常全空保存及真实iframe content0/public保留；旧卸载/历史Token步骤不会重复。

`UC-PHTML-CANVAS-NETLOG` 仅用于加载故障诊断。显式设置 `PHTML_NETLOG_PATH` 到本轮证据目录下的私有文件，并用 `PHTML_NETLOG_LABEL` 区分多次诊断；它保留浏览器默认参数，仅追加日志参数，不保存数据。原始 NetLog/HTML 按 0600 保留，只共享脱敏摘要。

验收后按记录的精确 owner、版本、绑定与 Store/Channel ID 清理，不清父 Website 或公共媒体。保留失败回执和可重跑现场，直到运行协调者给出清理窗口。
