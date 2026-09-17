# 模块翻译 CSV 规范

> **硬规则**：任何模块交付用户可见文案时，必须维护齐全的基础中英文 CSV，并在每次改动后执行 `i18n:collect`。只改 CSV 不 collect，运行时**不会生效**。

## 适用范围

- 所有 `app/code/{Vendor}/{Module}/` 业务模块
- 前台 `.phtml`（`<lang>` / `@lang()`）、后台 PHP（`__()`）、JS（`__()`）、菜单/事件描述等一切可翻译源串

## 必备文件

每个模块至少维护：

```
app/code/Weline/YourModule/i18n/
├── zh_Hans_CN.csv   # 简体中文（source 通常为中文）
└── en_US.csv        # 英文（source 列与 zh 对齐，translate 列为英文译文）
```

| 文件 | 第一列（source / word） | 第二列（translate） |
|------|-------------------------|---------------------|
| `zh_Hans_CN.csv` | 源串（中文） | 简体中文展示（通常与 source 相同） |
| `en_US.csv` | **与 zh 文件相同的 source** | **英文译文**（不得留空、不得把中文 source 原样当作英文） |

## 前后台对齐

- **前台**新增 `<lang>某文案</lang>` 或 `@lang(某文案)` → 该 source 必须出现在**归属模块**的两份 CSV 中。
- **后台**新增 `__('某文案')` → 同上；禁止只补前台 locale 而漏后台词条。
- 跨模块覆写模板时，新出现的源串归属**实际声明该 `.phtml` 的模块**；对**该模块**执行 collect，而不是只改调用方模块。

## 标准流程（固定）

```text
1. 代码中加源串（<lang> / __() / @lang）
2. 运行收集（扫描代码并合并 CSV，同时刷新运行时词典）
      php bin/w i18n:collect Weline_YourModule
3. 打开 i18n/zh_Hans_CN.csv 与 i18n/en_US.csv：
   - 确认新词条两行都在
   - en_US 第二列填好英文（collect 不会自动翻译）
4. 若手改 CSV，必须再次 collect（否则 WLS/后台仍读旧缓存）
      php bin/w i18n:collect Weline_YourModule
5. 验收：切换 en_US locale 页面/后台，确认不再显示中文 source
```

全仓库批量：

```bash
php bin/w i18n:collect
```

## 为何必须 collect

- WLS 运行时按**模块 CSV 快照 + 词典缓存**解析翻译；`i18n:collect` 会：
  1. 静态扫描 `__()` / 模板源串并合并进 `i18n/*.csv`（保留 CSV 已有但本次未扫到的词条）
  2. 清理当前进程的 `i18n` / `phrase` 翻译状态
  3. 通过已配置的 `RuntimeControlBroadcasterInterface` 通知当前项目的运行实例清理缓存，最多等待 12 秒；仅 `success=true` 且 `completed=true` 才报告翻译缓存清理成功。没有运行控制 provider 时，保留原本地清理行为。
- **只编辑 CSV 文件而不跑 collect** → 文件在磁盘上已更新，但进程仍用旧词典 → 页面上仍显示未翻译 source。
- **前台 QueryBin / `Weline.Api`**：WLS 不整包加载 `generated/language/*.php`，`__()` 按请求关联模块读取 `app/code/.../i18n/{locale}.csv`。因此改完英文 CSV 后必须 `php bin/w i18n:collect Weline_YourModule`，否则英文站接口提示仍可能是中文 source。

### 收集完成与运行刷新是两项结果

- CSV 收集成功后，若运行控制连接失败、Worker 拒绝清理或在等待期内仍未完成，命令沿用现有 warning 输出具体原因，并保留已经完成的翻译收集；不能仅凭退出码 0 判断运行实例已经更新。
- 当前项目运行实例从其 `var/server/instances/` 注册信息解析，沿用正式运行控制服务；不新增重启逻辑，不操作其他项目实例。
- 遇到重载排队等情况，缓存操作可能在命令等待结束后才完成。验收须核对该次操作 ID 的终态与 Worker 回执，并回读普通中英文页面；不要把另一轮操作或一次成功视为全部刷新问题已解决。

## CSV 格式与编码（硬规则）

- **编码**：UTF-8。文件开头**最多允许 1 个** UTF-8 BOM（`EF BB BF`，便于 Excel 识别）；读入时仅跳过这 1 个文件头 BOM。
- **禁止**：把 BOM 写进词条键或译文；禁止在中间行再写 BOM。
- **乱码行**：键或译文仍含 BOM / U+FEFF / U+FFFD 的行**整行丢弃**，不写回 CSV；缺词留给下次翻译。禁止 strip 挽救后再写入。
- **写回**：AI/词典写回不得 `append` 污染键；应 `read → 丢弃乱码行 → dedupe → write`（见 `I18nCsvCodec`）。

```csv
保存成功,保存成功
"带逗号或引号的文案","带逗号或引号的文案"
```

`en_US.csv` 对应：

```csv
保存成功,"Saved successfully"
"带逗号或引号的文案","Text with comma or quotes"
```

规则：

- 第一列必须是**稳定 source**（与代码里 `<lang>` / `__()` 的字符串完全一致）
- 两 locale 文件的 source 列**键集合应对齐**（允许 en 暂缺时 collect 后补，但交付前必须补齐）
- 插值占位符用 `%{1}`、`%{name}`，两 locale 保持一致
- 编辑器若显示 ``（U+FFFD）堆在中文键前：按乱码行丢弃即可，缺词下次再译；不要把污染键 strip 后写回

## 默认网站全语种（硬，用户提到「翻译」）

MCP 规则 id：`user_mentions_translation_all_default_website_locales`。

当用户把 **翻译 / translate / translation** 当作**工作请求**（含店面漏译截图、搜索框仍中文等），而不是仅在讨论 i18n 子系统时：

1. **在本机**解析默认网站已选语言（`runtime_status_query_local_first`；禁止为此去生产）：
   - `WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT)`（`website_id = 0`）
   - 始终保留基线 `zh_Hans_CN` + `en_US`
2. 对**每一个**已选 locale 写入归属模块 `i18n/{locale}.csv` 的真实目标语译文（第二列不得把中文 source 原样留下）。
3. **禁止**只译 `en_US` 就宣称完成。用户可在本回合显式收窄语种。
4. 默认由 Agent **直接写 CSV**；**未要求时禁止启动 Ollama**。
5. 写完后必须 `php bin/w i18n:collect {Module}`，再抽检目标 locale 店面。

解析示例（本机）：

```bash
# 仅当任务需要确认默认站语种时查本机库；勿 SSH 生产
# website_id=0 → language_code 列表即本回合默认翻译范围
```

## 禁止

- 交付模块只有 `zh_Hans_CN.csv` 没有 `en_US.csv`（或 en 列大量仍为中文）
- 用户说「翻译」却只补 `en_US`、不覆盖默认网站已选语言
- 前台加了词、后台 CSV 不补
- 改完 CSV 宣称「已翻译」但未执行 `i18n:collect`
- 用 `cache:clear` 代替 `i18n:collect`（collect 内含扫描 + 缓存失效，二者不等价）

## 验收清单

- [ ] `i18n/zh_Hans_CN.csv` 与 `i18n/en_US.csv` 存在
- [ ] 本次新增/修改的 source 在两文件中均有行
- [ ] `en_US.csv` 译文为英文（非空、非中文占位）
- [ ] 用户提到「翻译」时：默认网站已选语言的 `i18n/{locale}.csv` 均已写真实译文（抽检非中文 locale 第二列 ≠ 中文 source）
- [ ] 已执行 `php bin/w i18n:collect Weline_YourModule` 且无报错
- [ ] 开发日志记录 collect 命令与 locale 抽检结果

## 相关文档

- [01-翻译函数使用指南](../Framework/doc/3-开发/01-翻译函数使用指南.md)
- [01-lang标签使用指南](../Framework/doc/4-内置标签/01-lang标签使用指南.md)
- [Theme 开发总指南 §i18n](../Theme/doc/开发/Theme开发总指南.md)
- [AI 硬规则索引](../Ai/doc/AI硬规则索引.md)
