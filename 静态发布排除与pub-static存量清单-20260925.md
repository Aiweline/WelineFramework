# 静态发布治理报告（2026-09-25）

> 起因：`pub/static` 在 Web 根之下，被铺进去的文档会被浏览器直接读取（`*.php` 更会被执行）。
> 本报告覆盖三件事：**① 补发布排除规则**、**② 修畸形路径根因**、**③ 清理存量**。

---

## 一、交付总览（9 改 + 4 新增）

### 1. 发布排除规则（`pub/static` 只许含运行时资源）

| 文件 | 改动 |
|------|------|
| `app/code/Weline/Framework/Deploy/StaticPublishExclusion.php` | **新增**。规则唯一权威：段 / 文件名 / 文档主干 / 文档扩展名 |
| `app/code/Weline/Framework/Console/Console/Deploy/Upgrade.php` | `recursiveCopy()` 改 `RecursiveCallbackFilterIterator` 剪枝（overlay + 扁平双写同时生效） |
| `app/code/Weline/Theme/Console/Theme/Upgrade.php` | `fetchThemeFiles()` 增 `isExcludedPublishPath()`（`app/design/{theme}` 搬迁） |

### 2. 畸形路径根因（发布目标与 URL 同源）

| 文件 | 改动 |
|------|------|
| `app/code/Weline/Framework/View/PublicThemeNamespace.php` | **新增**。`theme.path` → 公开主题命名空间归一化（从 `TraitTemplate` 纯提取） |
| `app/code/Weline/Framework/View/TraitTemplate.php` | `resolvePublicThemeNamespace()` 改为委托，消除重复实现 |
| `app/code/Weline/Framework/Console/Console/Deploy/Upgrade.php` | overlay / `view/theme` 目标改用归一化命名空间；不安全时跳过主题域发布并告警 |

### 3. 测试与文档

| 文件 | 改动 |
|------|------|
| `app/code/Weline/Framework/Test/Unit/Deploy/StaticPublishExclusionTest.php` | **新增**，40 用例 |
| `app/code/Weline/Framework/Test/Unit/View/PublicThemeNamespaceTest.php` | **新增**，17 用例（含恶意输入恒安全契约） |
| `app/code/Weline/Framework/Test/Unit/Deploy/ModuleFlatStaticsPublishTest.php` | 增真实落盘用例 |
| `app/code/Weline/Theme/test/Unit/Console/Theme/ThemeUpgradeCommandContractTest.php` | 增 2 用例（真实命令 + 真实 Scan） |
| `app/code/Weline/Theme/test/Unit/ThemeStaticAssetPublisherTest.php` | 增 `tearDown()` 自清理（原本把夹具主题写进真实 `pub/static`，见第四章） |
| `app/code/Weline/Framework/doc/static-resource-versioning.md` | 新增「主题命名空间归一化」「发布排除」两章 |

### 排除规则要点

- **段**（任意层级，命中即剪整棵子树）：`.git` `.github` `.gitlab` `.circleci` `.husky` `.idea` `.vscode` `node_modules` `bower_components` `nuget` `doc` `docs` `documentation`
- **段（仅用户命名空间之外）**：`test` `tests` `__tests__` `cypress` `e2e` `spec` `specs`
- **文件名**：`package.json` `package-lock.json` `yarn.lock` `pnpm-lock.yaml` `composer.json` `composer.lock` `bower.json` `Gruntfile.js` `gulpfile.js` `webpack.config.js` `rollup.config.js` `vite.config.js` `karma.conf.js` `jest.config.js` `cypress.json` `tsconfig.json`，`.eslintrc*` `.stylelintrc*` `.babelrc*` `.prettierrc*`，`.editorconfig` `.gitignore` `.npmignore` `.travis.yml` `.browserslistrc`
- **文档主干**（忽略扩展名，含无扩展名 `LICENSE`）：`readme` `changelog` `contributing` `license` `licence` `copying` `notice` `authors` `contributors` `code_of_conduct`
- **文档扩展名**：`md` `markdown` `mdown` `rst` `adoc` `asciidoc`。**刻意不含 `txt`**（`robots.txt` 是运行时资源）

**用户命名空间逃逸**：`layouts` / `partials` / `widgets` 下是自命名目录，仓库真实存在名为 `test` 的布局
（`Theme/view/theme/frontend/layouts/test/assets-test.phtml`），故 `test`/`spec` 等段在其内不套用。

---

## 二、存量清理执行记录

### 清理前后

| 指标 | 清理前 | 清理后 | 变化 |
|------|-------:|-------:|-----:|
| 文件数 | 39437 | **24748** | −14689 |
| 体量 | ≈1.75 GB | **914.4 MB** | **−≈835 MB** |
| 规则命中残留 | 3109 | **0** | 归零 |
| 顶层目录 | 9 个（含 4 个畸形/孤儿） | `Weline/` `__preview/` | — |

### 删除构成（14689 文件）

| 批次 | 内容 | 体量 | 安全依据 |
|------|------|-----:|---------|
| 1 | `pub/static/Users/**` | 291 M | 畸形绝对路径发布树，全仓 + `app/etc` + `var` + `generated` **零引用** |
| 1 | `pub/static/Codex/**` | 264 M | 孤儿主题命名空间（`theme:listing` 无此主题，env.php 无 Codex，零引用） |
| 1 | `Weline_Theme::view/` `acceptance/` `WeShop/` | ~52 K | 畸形目录名 / 残留 |
| 2 | `StaticPublishExclusion` 命中的文档/测试（81 目录 + 520 文件） | ~191 M | **规则本身即安全证明** —— 只命中非运行时资源 |
| 3 | `__preview/.../Weline/test` | 微小 | 预览上下文内的测试目录 |

### 执行过程与安全措施

1. **目录级剪枝**：按规则向上找最顶层「本身即被排除」的祖先目录，整棵删除，不留空壳。
2. **逐路径 git 核验**：606 条路径跑 `git ls-files --error-unmatch` → **被跟踪目标 0 个**
   （`pub/static` 全树仅 `.gitkeep` 被跟踪，已保留）。
3. **备份**：`rsync -aRr` → `var/backup/static-publish-cleanup-20260925_2158`（**14693 文件 / 749 MB**）。
   - ⚠️ 坑：`rsync --files-from` **不会**隐含 `-r`（即使带 `-a`），首次备份只落了 520 个文件、目录全是空的。必须显式 `-aRr`。
4. **备份校验**：14693 个待删文件**逐个**确认在备份中存在 → 缺失 0；`diff -rq` 抽样（`hanfu/doc`、`Codex`）无内容差异。
5. **走回收站**：`/usr/bin/trash`，全程未用 `rm`。共 12 批（5 顶层 + 4×目录 + 7×文件），每批后核验，**残留均为 0**。

### 保留未动

- `pub/static/.gitkeep`（唯一被跟踪文件）
- `pub/static/Weline/**` 运行时资源、`pub/static/__preview/**` 预览上下文
- `layouts/test/...` 两个真实布局目录（命名空间逃逸正确放过）
- 64 个空目录中，**60 个是清理前就存在的**（发布器会建空目录），仅 4 个由本次清空 → 为保持一致未删

---

## 三、验证证据

```
$ php vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --no-configuration <paths>
```

| 测试 | 结果 |
|------|------|
| `StaticPublishExclusionTest` | **OK（40 tests, 47 assertions）** |
| `PublicThemeNamespaceTest` | **OK（17 tests, 75 assertions）** |
| `ModuleFlatStaticsPublishTest` | **OK（5 tests）** |
| `ThemeUpgradeCommandContractTest` | **OK（9 tests, 31 assertions）** |

### 归一化重构的正确性证明（差异对比）

逐行照搬旧实现，对新实现做 27 组输入对比：

```
总输入: 27   行为差异: 4
DIFF  'Weline//hanfu'   old='Weline//hanfu'   new=默认命名空间
DIFF  'a/../b'          old='a/../b'          new=默认命名空间
DIFF  '..'              old='..'              new=默认命名空间
DIFF  '.'               old='.'               new=默认命名空间
```

**23/27 完全一致**（含 `Weline/hanfu`、`Weline_Theme::view/theme`、绝对源码路径、`app/design` 路径等全部真实形态）；
4 处差异均为**有意的加固**（`..` / `.` / 空段此前会产出可越出 `pub/static` 的路径）。当前配置 `theme.path` 归一化结果 `Weline/hanfu`，与旧实现一致。

### ⚠️ 本机环境不一致（既有，非本次引入）

```
DEV = true   PROD = true   system.deploy = 'prod'
```

`DEV` 与 `PROD` **同时为真**，而测试套件假定二者互斥（如 `TemplateTest` 走 `if (DEV)` 分支断言 dev 路径，
运行时却按 `PROD` 产出 `/static/{theme}/...`）。因此：

- `Framework/Test/Unit/View/` → 261 tests，14 failures + 3 errors
- `Theme/test/Unit/` → 1681 tests，128 failures + 77 errors

这些**均为既有环境性问题**，与本次改动无关（差异对比已证明归一化行为对真实输入不变）。
另：`Framework/Test/Unit/` 整目录直跑会因 `BinQueryGatewayAuthenticatorTest` 在 `Service/Query/` 与
`Service/Query/Query/` 下**重复声明**而 fatal，须走官方 `php bin/w phpunit:run`（本机被非 DEV 模式拦住）。

> **后续（2026-09-25 23:0x）：已定位根因并修复，见 [第六章](#六preview-清理与-devprod-矛盾修复)。**
> 结论：`DEV` 与 `PROD` 同时为真是**测试引导缺一个常量固定**导致的，已用 8 行改动消除；
> `php bin/w phpunit:run` 被拦是**另一个独立问题**（`Env::system('deploy')` 门禁），属配置决策，未擅自改。

> 复现命令：`php vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --no-configuration <dir>`
> （**必须**用 `app/bootstrap_phpunit.php`；用 `vendor/autoload.php` 会缺 `BP`/`APP_ETC_PATH` 常量而报 7 个假错）

---

## 四、清理后复现排查：污染源是**非幂等测试**，不是发布器

清理完成、复验为 `24748 文件 / 914 MB / 规则残留 0` 之后，再做收尾状态检查时发现
`pub/static` 顶层又出现 `Codex/`、`WeShop/`，文件数 24748 → 24757、体量 914 MB → 973 MB。
**已定位并修复**，结论如下。

### 1. 复现物是什么（体量极小，且无文档）

| 路径 | 大小 | 内容判定 |
|------|-----:|---------|
| `pub/static/Codex/demo-theme/Weline/Theme/view/statics/ui/weline-foundation.css` | 117 605 B | 与 `app/code/Weline/Theme/view/statics/ui/weline-foundation.css` **逐字节相同** |
| `pub/static/Codex/demo-theme/Weline/Theme/view/theme/backend/assets/css/theme.css` | 99 710 B | 与 `app/code/Weline/Theme/view/theme/backend/assets/css/theme.css` **逐字节相同** |
| `pub/static/WeShop/default/Weline/Theme/view/theme/frontend/variables/_colors.css` | 15 904 B | 与模块默认 `_colors.css` **逐字节相同** |
| `pub/static/WeShop/motor/Weline/Theme/view/theme/frontend/variables/_colors.css` | 15 904 B | 同上 |

关键点：**`Codex/` 从 5344 文件 / 264 MB 缩到 2 文件 / 216 KB，且 `find pub/static -name '*.md'` 仍为 0**。
说明 `StaticPublishExclusion` 剪枝**确实生效**（不再整棵搬运），复现物只是运行时 CSS 副本，
**无信息泄露**；但孤儿命名空间仍属目录卫生问题。

### 2. 根因：`ThemeStaticAssetPublisherTest` 把夹具写进**真实** `pub/static`

`app/code/Weline/Theme/test/Unit/ThemeStaticAssetPublisherTest.php` 调用真实发布器，
而 `ThemeResourceGateway` 的发布根**硬编码** `rtrim(BP, '/\\') . '/pub/static/'`，
用例又用 `$basePath = rtrim(BP, '\\/') . DS` 断言，于是夹具主题被写进项目真实 Web 根：

```php
$theme = $this->buildTheme(990001, 'motor',      'WeShop/motor');
$theme = $this->buildTheme(990002, 'default',    'WeShop/default');
$theme = $this->buildTheme(990006, 'demo-theme', 'Codex/demo-theme');
$theme = $this->buildTheme(990007, 'demo-theme', 'Codex/demo-theme');
```

三条独立证据交叉确认（缺一不可）：

1. **日志**：`var/log/other/theme_layout_entity.log` 在 `14:06:17Z`（= 本地 22:06）记到
   `Theme/test/Unit/LayoutEntity/RegistryRetirementHistoricalChromeTest.php:71` 与
   `Theme/test/e2e/backend/theme-version-artifact-isolation-fixture.php:1761` —— 即**当时正在跑 Theme 测试套件**。
2. **文件 mtime**：四个复现物全部 `2026-09-25 22:06`，与日志同一分钟。
3. **路径字面量**：测试源码里的 `'WeShop/motor'` / `'WeShop/default'` / `'Codex/demo-theme'` 与复现物路径**完全对应**。

**排除的假设**（都查过，均不成立）：

| 假设 | 排除依据 |
|------|---------|
| 线上 WLS 服务在持续发布 | 当时新增写入全部是 `__preview/ctx_*`（预览上下文，本就有意保留） |
| cron 任务 | `var/cron.log` 123 MB 且在实时写，但内容是 `cron:task:run` 分页进度，未触达 `Codex`/`WeShop` |
| 遗留 DB 主题表 | `weline_theme`（无前缀，8 行，含 `codex-demo-theme`/`weshop-*`）看着像元凶，但 `PgsqlTableNameStrategy::resolve()` 会补前缀 `w_`，模型实际读 `w_weline_theme`；且 `pg_stat_user_tables` 显示 `weline_theme` 计数**冻结**（`seq=27 idx=0`，75 秒零增长），仅我自己的查询碰过 → **死数据** |
| 主题记录被删 | `w_weline_theme` 只有 Default/hanfu/daocharms 三行，`is_active` 与 `env.php` 一致 |

### 3. 修复：用例自清理（外科式，只删自己发布的文件）

给 `ThemeStaticAssetPublisherTest` 增 `tearDown()`：按**精确文件清单** unlink，再逐级回收变空目录，
范围强制限制在 `pub/static` 之内（`realpath` 越界即跳过）。**不整棵删目录**，避免误伤同名命名空间下其它内容。

```php
private const FIXTURE_PUBLISHED_FILES = [
    'WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/motor.css',
    'WeShop/motor/Weline/Theme/view/theme/frontend/variables/_colors.css',
    'WeShop/default/Weline/Theme/view/theme/frontend/variables/_colors.css',
    '__preview/token_pv_preview_namespace/WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/motor.css',
    'Codex/demo-theme/Weline/Theme/view/statics/ui/weline-foundation.css',
    'Codex/demo-theme/Weline/Theme/view/theme/backend/assets/css/theme.css',
    'Weline/Backend/js/weline-api.js',
    'Weline/Backend/js/weline-api-worker.js',
];
```

### 4. 验证（真跑）

```
$ php vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --no-configuration \
    app/code/Weline/Theme/test/Unit/ThemeStaticAssetPublisherTest.php
Tests: 8, Assertions: 20, Failures: 4.     ← 4 个失败为既有（发布器返回 null），非本次引入
```

| 检查 | 结果 |
|------|------|
| 运行前 `ls pub/static/` | `Weline  __preview` |
| 运行后 `ls pub/static/` | `Weline  __preview`（**零残留**） |
| `pub/static/WeShop`、`pub/static/Codex`、`__preview/token_pv_preview_namespace` | 全部干净 |
| `pub/static/Weline/Backend/js/` | 仍保留原有 **8 个**运行时文件（未误删） |

**「先写后清」闭环证明**：单跑两个 Codex 用例（`--filter`）→ `OK (2 tests, 6 assertions)`。
运行**前** `Codex` 不存在，用例断言 `assertFileExists('pub/static/Codex/demo-theme/...')` 却**通过**
（证明运行中确实发布），运行**后** `Codex` 又不存在（证明 `tearDown` 回收）。写 → 断言 → 清，闭环成立。

### 5. 结论

- 清理成果**完整保持**：`Users/`（291 M）、`Weline_Theme::view/`、`acceptance/` 等**没有回来**，
  `Codex/` 只回来 2 个文件（对比修复前 5344 个），文档泄露为 0。
- 残留的**真实原因**是测试不幂等，而非发布规则或畸形路径根因——**根因修复仍然有效**。
- 已修掉该污染源；此后跑该用例不再向 Web 根写任何东西。

---

## 五、遗留与待决策

| 项 | 说明 | 建议 |
|----|------|------|
| **归一化语义分歧** | ~~`ThemeStaticNamespaceService` 把 `Vendor_Module::path` **展开**；`PublicThemeNamespace` **回落默认**~~ → **已合并**：`PublicThemeNamespace` 为唯一权威，语义定为**展开**，`ThemeStaticNamespaceService` 改为委托 | ✅ 已完成，见第七章 §1 |
| **相对 `app/code/...`** | ~~若 `theme.path` 存成**相对** `app/code/Weline/Theme/view/theme`，新旧实现都原样返回 → 铺到 `pub/static/app/code/...`~~ → **已归一化**为 `Weline/Theme/view/theme` | ✅ 已完成，见第七章 §1 |
| **`__preview/`** | ~~4606 文件 / 83 MB，1118 个预览上下文~~ → **已清理**：1123 → 109 上下文，83 MB → 33 MB | ✅ 已完成，见第六章 |
| **`daocharms/register.php`** | ~~模块注册文件在 Web 根~~ → **已删除**（`.php` 扩展名排除；来源是历史残留，`fetchThemeFiles` 的 `register.php` 守卫是后加的） | ✅ 已完成，见第七章 §2 |
| **`daocharms-page-boot.php`** | ~~设计目录 `frontend/includes/` 下的 `.php` 落在 Web 根，可能被模板 include，也可能被直接访问~~ → **已查用途并删除**：全仓无引用，设计主题内 `includes/` 只有这一个文件 | ✅ 已完成，见第七章 §2 |
| **`.phtml` 落 Web 根** | ~~来源待查~~ → **已查明并删除**：`theme:upgrade` 会把设计主题 `frontend/layouts|partials|widgets/**/*.phtml` **源码模板**整树搬进 `pub/static`（301 个）；运行时布局实体在 `var/runtime/theme-layout-entities/`，**无任何消费方**从 `pub/static` 读 `.phtml` | ✅ 已完成，见第七章 §2 |
| **`*.php` 落 Web 根（elFinder）** | 95 个 `.php` + 6 个 `.php-dist` 的三份副本（flat + 两份 overlay）；elFinder 实际由 `VENDOR_PATH . '/autoload.php'` 装载，`"php/connector.minimal.php"` 被框架路由**替换**，**从不执行** | ✅ 已删除，见第七章 §2 |
| **`DEV`/`PROD` 同时为真** | ~~测试套件在本机无法有效运行~~ → **已定位并修复**（`app/bootstrap_phpunit.php` 补 `PROD=false`） | ✅ 已完成，见第六章 |
| **`bin/w phpunit:run` 被拦** | 门禁读 `Env::system('deploy') !== 'dev'`（6 处硬编码，无逃生口），与 `DEV`/`PROD` 常量**无关**；本机 `system.deploy='prod'`（疑 2026-09-24 `force-prod-after-static-404-fix` 遗留） | **不建议改**：该开关会松开**支付安全锁**、改域名商 live/sandbox、改 cron/SSL/i18n，并让本地不再走生产静态链路。改用零风险替代入口，见第六章 §3 |
| **未提交** | ~~97 个 bootstrap-select 删除 + 本次 9 改 4 新增~~ → **4 个 commit 已推送**至 gitee 与 github；命名空间统一与脚本排除为**第 5 个提交** | ✅ 见第六章「提交记录」与第七章 §3 |
| **生成的 JUnit 报告被纳入版本控制** | ~~`DataTable/Test/test-results.xml` 每次跑测都被改写，却被 git 跟踪~~ → **已 `git rm --cached` + 加模块级 `.gitignore`** | ✅ 已完成，见第六章 §5 |

### 备份位置

```
var/backup/static-publish-cleanup-20260925_2158/     (14693 文件 / 749 MB)
var/backup/pubstatic-scripts-20260926_0012/          (417 文件 / 6.9 MB)   ← 第七章 §2 的脚本清理
```

按相对路径结构保存，原样还原：`rsync -aRr --files-from=<清单> <备份>/ ./`

`__preview` 孤儿备份：`var/backup/preview-orphans-20260925_2254/`（3384 文件 / 50 MB）

---

## 六、`__preview` 清理与 `DEV`/`PROD` 矛盾修复

### 1. `__preview/` 清理（1123 → 109 上下文，83 MB → 33 MB）

**先确认「有没有在用」，再定阈值。** `__preview` 当时确有活跃写入（近 1 小时 8 个新上下文，mtime 22:35），
所以**不能整目录删**。

| 事实 | 数据 |
|------|------|
| 命名两类 | `token_*` 711 个（预览令牌派生）+ `ctx_*` 412 个（会话派生） |
| 孤儿上界 | `PreviewTokenService::TOKEN_MAX_LIFETIME = 8 * 3600` → `token_*` 超 8 h **必定死亡**；`ctx_*` 绑会话（`cookie_lifetime = 604800` = 7 d） |
| 体积分布 | >8 h = 1077 目录 / 80.7 MB；>24 h = 1077 / 80.7 MB；>7 d = **1014 / 50.2 MB**；>30 d = 775 / 26.1 MB |

取 **>7 天**（即 `ctx_*` 会话上界）为阈值 → 1014 个目录 / 50.2 MB。

**删除前两道安全证明**：

1. 抽样上下文内容只有主题资源副本（`_dark.css`、`theme.css`），与源文件**逐字节相同** → 删的是副本，不是唯一副本。
2. `ThemeResourceGateway::publishForRequestPath()` 会**按需重新发布** `__preview/` 命名空间资源 → 即便误删也能自愈。

**备份与执行**：

```
rsync -aRr --files-from=/tmp/preview_orphans.txt pub/static/ var/backup/preview-orphans-20260925_2254/
→ 3384 文件 / 50 MB（与目标计数**逐一对上**，备份核验通过后才动手）
```

- 跟踪状态核对：`git ls-files pub/static` = 1（仅 `.gitkeep`）、`git ls-files pub/static/__preview` = 0 → 删除不触碰版本控制内容。
- 分 200 个一批，共 1014 个目录经 `/usr/bin/trash` 送废纸篓 → **残留 0**。

**结果与冒烟**：`__preview` 1123 → **109** 上下文、83 MB → **33 MB**；`pub/static` 收至 **21408 文件 / 922 MB**。
带浏览器 UA 冒烟：`/` **200**（1.12 s）、`/products` **200**（0.84 s）、保留的预览资源 **200**。

> **顺带的安全发现**：请求一个**已删除**的预览资源返回 **404 且不会重新发布** —— 既证明该令牌确已失效，
> 也反证这些陈旧预览资源**此前确实在 Web 根下可被公开拉取**。删除即收窄了该暴露面。

### 2. `DEV` 与 `PROD` 同时为真：根因与修复

**症状**（真实探针，非推断）：

```
[PROBE] Env::system('deploy') = 'prod'
[PROBE] DEV  = true
[PROBE] PROD = true
[PROBE] 矛盾(DEV && PROD) = YES
```

**根因**：`app/bootstrap_phpunit.php` 只固定了 `DEV`，**没固定 `PROD`**；随后 `App::init()`
（`Framework/App.php:1268-1273`）两个常量都带 `!defined()` 守卫：

```php
if (!defined('DEV'))  { define('DEV',  w_array_get($config, 'system.deploy') === 'dev');  }  // 被 bootstrap 抢先 → 保持 true
if (!defined('PROD')) { define('PROD', w_array_get($config, 'system.deploy') === 'prod'); }  // 无人固定 → 按 env.php 得 true
```

`env.php` 里 `system.deploy = 'prod'`，于是 `PROD` 被求值为 `true`，而 `DEV` 早已被固定为 `true`。

| 启动方式 | DEV | PROD | 矛盾 |
|---------|-----|------|------|
| 非测试（FPM / WLS / `bin/w`） | false | true | 否（正确） |
| 测试（`bootstrap_phpunit.php`） | **true** | **true** | **是** |

**为什么危害大**：代码里 `DEV &&` 164 处、`PROD &&` 23 处、`!DEV` 13 处。两个常量同时为真时，
**dev 分支与 prod 分支会同时激活**，而 `!DEV` 那 13 处更会「以为自己在 prod」却拿到 dev 语义。
最直接的后果：

- `TraitTemplate` 把 `view/statics`、`view/theme` 解析到**真实 `pub/static`**（测试开始写 Web 根，与本次治理目标直接冲突）
- `QueryProviderRegistry::compiledRegistryRequired()` 强制走**编译注册表**，而非 dev 的实时扫描
- `FpmRuntime::bootstrap()` 触发 `ContainerRuntime::preflight()` 生产冷启动门禁（`ContainerException` 抛出点）
- `TelemetryBroadcaster` 采样模式由 `full` 变 `sampled`

**修复**（8 行，仅测试引导，不触碰生产路径）：与既有约定对齐。`B2B`、`CustomerAsset`、`Vendor`、`Search`、
`Subscription`、`Order`、`Product` 共 **7 个**模块 `Test/Unit/bootstrap.php` **都是成对固定** `DEV=true` + `PROD=false`，
只有根测试引导漏了后者。

```php
if (!defined('DEV')) {
    define('DEV', true);
}
// 必须与 DEV 成对固定：否则 App::init() 会按 env.php 的 system.deploy 求值 PROD……
if (!defined('PROD')) {
    define('PROD', false);
}
```

**验证（改动前后对比，真实探针）**：

| 判定 | BEFORE（DEV=T, PROD=T） | AFTER（DEV=T, PROD=F） |
|------|:----------------------:|:----------------------:|
| 强制编译注册表 | **true** | false |
| 静态资源落真实 `pub/static` | **true** | **false** |
| `DEV` / `PROD` | true / true | true / **false** |

**回归核验**：`DataTable` 单测套件（83 tests / 651 assertions）改动前后**结果完全一致** —— 均为 **1 failure**，
且是**同一个既有失败**（`TaglibFrontendContractTest::testStandaloneFormLoadsOnlyItsOwnedComponentBundle`，
断言资源版本号形如 `dev_<12hex>`，实际为 `1.1.4-20260925-cta-amz`，与常量无关）。**本次修复零回归。**
另：该套件在原版/修复版两种模式下对 `pub/static` 的写入增量均为 **0**（此套件本身不污染）。

### 3. 未处理（属配置决策，需人确认）

`php bin/w phpunit:run` 仍被拦：

```
非开发环境禁止运行！如你确认是dev环境，请运行php bin/w deploy:model:set dev 转换环境后运行！
当前部署模式：prod
```

该门禁读的是 `Env::system('deploy') !== 'dev'`（见 `Test/Console/PhpUnit/Run.php:136`、
`Test/Service/TestRunService.php:275` 等 **6 处，均为硬编码、无逃生口**），
**与 `DEV`/`PROD` 常量无关**，所以上面的修复**不会**解开它。

**⚠️ 但结论是：不建议为了跑测试去改它。** 核实后发现这个开关远不止"测试便利"，
它会改本地站点的**真实运行时行为**：

| `system.deploy` 的运行时读者 | `prod` 时 | 改成 `dev` 后 |
|---|---|---|
| `Payment/Service/DevRelayGateService::isAllowed()` | dev 支付中继要过 `allow_on_production` **生产安全锁** | **安全锁消失**，dev relay 无条件放行 |
| `Payment/Controller/Frontend/Checkout.php:165` | `isProductionLive()` → 空支付返回页 `redirect('/')` | 改成渲染空状态页 |
| `Websites/Adapter/{GoDaddy,Gname,Cloudflare}Registrar` | 域名商 API 的 live/sandbox 选择 | 切换 live/sandbox |
| `Cron/Schedule/{Schtasks,Schedule,Linux/Crontab}` | 本地是否安装 cron 任务 | 变化 |
| `Server/Service/{LocalDomainPolicy,SslCertificateService}` | 本地域名/证书策略 | 变化 |
| `I18n/Service/DictionaryModuleCatalogService` | 禁止写回模块 CSV | 允许写回 |
| `TraitTemplate`（经 `DEV`/`PROD` 常量） | `view/statics`、`view/theme` → `pub/static/{theme}/…` | dev 路径 → **本地不再走生产静态发布链路** |

三条不动的理由：

1. **真正危害已消除，且没碰环境**——常量矛盾由 `fcc9081f9`（8 行测试引导改动）修掉；
   剩下的只是"两个 CLI 入口被拦"的**便利性**问题，不是正确性问题。
2. **`prod` 是有意留的**——`var/deploy/current.json` 的 note 是 `force-prod-after-static-404-fix`；
   而本轮加固/验证的正是**生产静态发布链路**，切 dev 后本地就不再走这条链路了。
3. **配置块自身矛盾**：`system.env = 'local'` 而 `system.deploy = 'prod'` →
   `isLocalEnvironment()` 与 `isProductionLive()` **同时为真**。`deploy=prod` 确是异类，
   但它压着**支付安全锁**，属**产品决策**，不该由清理任务顺手改。

**零风险替代（仓库已有先例）**：

- 单测：`vendor/bin/phpunit --no-configuration --bootstrap app/bootstrap_phpunit.php <file>`
- e2e：`cd tests/e2e && ./node_modules/.bin/playwright test --config=playwright.config.js <spec>`
  （需 `PLAYWRIGHT_TARGET_ORIGIN=https://p05113ef3.test.weline.com:9555`；裸 `127.0.0.1:9555` 被 WLS RST）
- 先例：`Theme/doc/开发/spec/layout-entity-per-version-isolation.md:347` 就是这么绕开的。

**若确要 `bin/w phpunit:run`**：临时切，别常驻 —— `php bin/w deploy:mode:set dev` → 跑 →
`php bin/w deploy:mode:set prod`。`Mode\Set::deploy()` 离开 prod 会先提示确认，
且 `dev` 分支**很轻**（只 `cache:clear` + `cleanTplComDir()`），不像 `prod` 分支要重编译静态、
清 `pub`、跑 `deploy_upgrade`、失效 FPC。⚠️ 期间本地资产 URL 会变、**支付安全锁会松**，
**别在验证支付时切**。

### 4. 提交记录（已推送至 `origin/dev` 与 `github/dev`）

| Commit | 内容 | 规模 |
|--------|------|------|
| `4414222e2` | `chore(eav): 删除未被引用的 bootstrap-select 1.14.0-beta3 资源副本` | 97 文件，22118 行删除 |
| `fb980ae44` | `fix(deploy): 静态发布排除规则与主题命名空间归一化，并让发布测试自清理` | 11 文件，+778 / −43 |
| `fcc9081f9` | `fix(test): 固定 PROD 常量，消除测试环境下 DEV 与 PROD 同时为真` | 1 文件，+8 |
| `8d3dd1f6d` | `chore(test): 将 phpunit 生成物移出版本控制并预置忽略规则` | 3 文件，+16 / −201 |

**推送结果**：`7defab83a..8d3dd1f6d`，gitee 与 github 均**干净快进**（推送前 dry-run 确认）。
推送后 `local dev` = `origin/dev` = `github/dev` = `8d3dd1f6d`，**0 领先 / 0 落后**。

提交前已核对索引，未夹带其它会话/他人未发布的工作（工作树另有 141 个他人改动文件，**未触碰**；
根 `.gitignore` 本身含他人 6 行未提交改动，故本次**绕开它**，改用模块级 `.gitignore`）。

### 5. 生成物纳入版本控制的治理（第 4 个提交）

`app/code/Weline/DataTable/Test/test-results.xml` 由 `phpunit.xml` 的
`<logging><junit outputFile="test-results.xml"/>` 每次跑测重写，却**被 git 跟踪** —— 表现为
"跑一次单测就产生一次无意义 diff"（本次就被改脏、又用 `git show HEAD:<path>` 还原过）。

**修"类"而非修"例"**：先全仓枚举同类生成物，确认只有这 1 个被跟踪，再修产生它的配置面：

```
git ls-files | grep -E '(test-results\.xml|\.phpunit\.result\.cache|coverage\.(xml|txt)|/coverage-html/)'
→ 仅 app/code/Weline/DataTable/Test/test-results.xml
```

| 动作 | 说明 |
|------|------|
| `git rm --cached` | 从索引移除，**保留磁盘文件**，运行期照常生成 |
| 新增 `DataTable/Test/.gitignore` | 覆盖该目录 `phpunit.xml` 声明的全部产物（junit / coverage / `cacheResultFile`） |
| 新增 `Order/Test/.gitignore` | `Order/Test/phpunit.xml` 同样声明了 junit 输出，**预置**规则避免重蹈覆辙 |

核验：4 条规则 `git check-ignore` 全部 `YES`，且 `test-results.xml` 磁盘文件仍在（49 KB）。


---

## 七、命名空间归一化统一 与 Web 根脚本清理

### 1. 归一化语义分歧：合并为单一权威

**问题**：两份实现对同一输入语义不同 —— `Theme\Service\ThemeStaticNamespaceService::normalizePublicThemePath()`
把 `Vendor_Module::path` **展开**为 `Vendor/Module/path`，而 `Framework\View\PublicThemeNamespace` **回落默认命名空间**。
`theme:upgrade` 走前者、`deploy:upgrade` 走后者 → 同一主题可能被发布到两个不同目录，或两个主题互相覆盖。

**先定语义（依据真实数据，而非推断）**。查 `w_weline_theme` 只有 3 行、3 种路径形态：

| id | name | module_name | path | is_active |
|----|------|-------------|------|-----------|
| 1 | Default 默认主题 | `Weline_Theme` | `/Users/…/app/code/Weline/Theme/view/theme`（绝对） | 0 |
| 3 | hanfu | `Weline_Hanfu` | `Weline/hanfu` | 1 |
| 4 | daocharms | `Weline_Daocharms` | `Weline/daocharms` | 0 |

**两份实现对这 3 个真实值结论完全一致** → 分歧是**纯潜在**的，合并对真实数据零行为变化。
语义取**展开**：`Vendor_Module::path` 命名的是**某个具体主题**，若一律回落默认，两个主题会写进
同一个 `pub/static/{默认}/…` 而互相覆盖。对内置默认主题（`Weline_Theme::view/theme`）展开结果
恰好等于默认命名空间，故该场景行为不变。

**顺带修掉一个安全漏洞**：旧 `normalizePublicThemePath()` 会把 `..` / `.` / `a/../b` / `a//b` / `a::b`
**原样返回** —— 正是本次清理掉的「畸形目录树」那一类。委托后不可能再发生。

| 动作 | 文件 |
|------|------|
| 新增 `tryResolve(): ?string`；`resolve()` 改为委托 `tryResolve() ?? defaultNamespace()` | `Framework/View/PublicThemeNamespace.php` |
| 删除自持实现（含失效的 `isPathUnderRoot()` / `isAbsolutePath()`），改为 3 行委托 | `Theme/Service/ThemeStaticNamespaceService.php` |
| 补 `testTryResolveDistinguishesUnresolvableFromDefault` 等 4 个用例，`hostilePathProvider` 扩到 13 项 | `Framework/Test/Unit/View/PublicThemeNamespaceTest.php` |
| 新增安全契约用例（13 项畸形输入）+ 逐输入等价用例 | `Theme/test/Unit/ThemeStaticNamespaceServiceTest.php` |

**证据**：探针对比 27 组输入 → 「有效命名空间不一致数 = 0」「返回非空且不安全的输入数 = 0」，
且额外修好 `app/code/Weline/Theme/view/theme` → `Weline/Theme/view/theme`、`app/design/Weline/hanfu` → `Weline/hanfu`。

### 2. Web 根下的可执行 / 模板源码：规则补齐 + 存量清理

**先证用途，再动手**（这是本次能安全清理的关键）：

| 目标 | 证据 |
|------|------|
| elFinder 的 `view/statics/php/**`（93 个 `.php`/`.php-dist` 三份副本） | `ElFinderFileManager/Controller/{Backend,Frontend}/Connector.php` 与 `Block/ElFinder.php` 均 `require VENDOR_PATH . '/autoload.php'`，并把 `"php/connector.minimal.php" => "$urlPath"` **替换**为框架路由 → 发布副本**从不执行** |
| 301 个 `.phtml` | 运行时布局实体在 `var/runtime/theme-layout-entities/`（`ThemeLayoutEntityPaths::root()`）；主题编辑器经 `ThemePathResolver` / `findFileInThemeHierarchy` 从**模块/主题源目录**解析模板；全仓 JS/HTML 无任何 `.phtml` 的 HTTP 取用 |
| 服务器如何回吐 | `Framework\Router\Core::StaticFile()` 用 `file_get_contents()` 原样返回 —— 即**源码泄露**；且 `*.php` 会被 Web 服务器交给 PHP 执行，`.phtml` 在 Apache/LiteSpeed 常见 `AddHandler` 下同理 |

**规则补齐**（`StaticPublishExclusion`，唯一权威）：

- 新增 `EXCLUDED_SCRIPT_EXTENSIONS`：`php` `phtml` `pht` `phps` `phar` `php3`–`php8`，加 `php` 前缀变体规则覆盖 `.php-dist`；另有 `cgi` `fcgi` `pl` `py` `rb` `sh` `bash` `zsh`、`asp` `aspx` `jsp` `jspx` `shtml`。**只按文件判定，不剪同名目录**。
- `EXCLUDED_FILE_NAMES` 补 Web 服务器 / PHP 运行期配置：`.htaccess` `.htpasswd` `.user.ini` `php.ini` `web.config`（落在 Web 根里能改变「谁能访问、什么会被执行」）。
- **第三处过滤点**：`ThemeResourceGateway::publishForRequestPath()` 也接上规则 —— 否则一次针对 `/static/Weline/ElFinderFileManager/php/connector.minimal.php` 的请求就会把整个 `view/statics/php/**` **重新铺回** Web 根，把清理结果一键还原。

**存量清理（按规则盘点，不另写判定）**：

| 指标 | 值 |
|------|-----|
| 盘点方式 | 直接 `require` 规则类遍历 `pub/static`（21 429 文件） |
| 命中 | **417 文件 / 6.3 MB**：`.phtml` 301、`.php` 95、`.rb` 12、`.php-dist` 6、`.htaccess` 3 |
| 安全核验 | 逐路径 `git ls-files --error-unmatch` → **被跟踪 0**；`git check-ignore` → **417/417 已忽略**（`pub/.gitignore:2:*`） |
| 备份 | `var/backup/pubstatic-scripts-20260926_0012/`（`rsync -aRr`，417 文件 / 6.9 MB）；**文件数 417 = 417**，逐字节 `cmp` **417/417 相同、0 缺失** |
| 删除 | `/usr/bin/trash` 分 **11 批**（≤60/批），每批残留核验 **0** |
| 清理后 | 规则盘点命中 **0**；`find pub/static -name '*.php' -o -name '*.phtml' -o -name '*.rb' -o -name '*.htaccess'` = **0** |

**残留（非可执行，未删）**：elFinder 三份副本各留 5 个文件 —— `MySQLStorage.sql`、`mime.types`、
`plugins/Watermark/logo.png`、`resources/image.png`、`resources/video.png`（共 15 个）。
均非可执行/模板，规则不覆盖；如需一并清掉 `view/statics/php/**` 整棵死目录，属另一项决定。

**空目录**：清理产生了一批变空目录（如 `Weline/Theme/view/theme/frontend/layouts/*`）。该树**本来就有**
大量空目录（`Weline/AppStore`、`Weline/Cron`、`Weline/Database` 等从未含脚本），
故**未做额外的空目录清理**，保持与既有形态一致。

**回归**（改动前后逐套件对比，失败数**不增**）：

| 套件 | 改动前 | 改动后 |
|------|--------|--------|
| `Theme/test/Unit`（1711 tests） | 77 err / **129 fail** | 77 err / **128 fail**（少 1） |
| `Framework/Test/Unit/View`（266 tests） | 3 err / 11 fail | 3 err / 11 fail |
| `Framework/Test/Unit/Deploy` | 69 tests / 169 assert OK | **70 tests / 174 assert OK** |
| `Framework/Test/Unit/Deploy/StaticPublishExclusionTest` | — | **55 tests / 86 assert OK** |
| `Theme/test/Unit/…/ThemeUpgradeCommandContractTest` | — | **9 tests / 36 assert OK** |

> `Theme/test/Unit` 的 77 err / 128 fail 为**既有**：用 `git show HEAD:<file>` 换回旧网关实现做 A/B，
> 结果是 129 fail → 本次改动使其**减少 1**、未新增。`ThemeStaticNamespaceServiceTest` 的 3 个
> `__preview` 前缀失败同样是既有（与 HEAD 逐字节同结果）。

### 3. 提交记录（第 5 个提交）

| Commit | 内容 |
|--------|------|
| （本次） | `fix(deploy): 统一主题命名空间归一化并排除 Web 根下的可执行/模板源码` —— `PublicThemeNamespace` 唯一权威 + `ThemeStaticNamespaceService` 委托 + `StaticPublishExclusion` 补脚本/配置扩展名 + `ThemeResourceGateway` 接规则 + 文档与测试 |

**未夹带**：工作树另有**他人**未提交改动（含 3 个他人删除的 Theme 文件 —— `MigrateBindings.php`、
`LayoutBindingMigrationCommandTest.php`、`fixtures/layout-binding-migration.php`，与未跟踪的
`ConvertVersionArtifacts.php` 配套，属其重构中），**未触碰**。
