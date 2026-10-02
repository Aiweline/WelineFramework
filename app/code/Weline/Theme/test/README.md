# Weline Theme 模块测试说明

> 本文件原内容严重过期（仅描述 3 个早期测试，且引用了已删除的 `Observer/LayoutAssetsExtractor`）。
> 2026-10-02 重写：改为描述**运行方式与目录结构**，不再逐个罗列测试文件（那类清单必然过期）。
> 追溯记录：`dev/audit/theme-legacy-audit-20261002.md`。

## 1. 如何运行

### PHP（PHPUnit 10.5）

仓库唯一的 PHPUnit 配置是 `tests/phpunit/config.xml`，其 `<testsuite>` **只覆盖 `Weline_Framework`**；本模块测试**不在任何默认套件内**（长期无运行器执行）。因此必须显式指定路径：

```bash
# 单个测试
php vendor/bin/phpunit -c tests/phpunit/config.xml --no-coverage app/code/Weline/Theme/test/Unit/你的测试.php

# 整个模块（推荐作为回归基线）
php vendor/bin/phpunit -c tests/phpunit/config.xml --no-coverage app/code/Weline/Theme/test
```

bootstrap 由配置指定为 `app/bootstrap_phpunit.php`（相对 `tests/phpunit/`）。

### JS（Vitest）

模块内 `.test.js` / `.test.cjs` 同样**不在**默认 vitest 配置（`tests/unit/vitest.config.js` 的 root 固定在 `tests/unit/`，include 不覆盖 `app/code/**`）。已提供专用配置：

```bash
cd tests/unit
./node_modules/.bin/vitest run \
  --config /绝对路径/框架/tests/unit/theme-module.vitest.config.js \
  --root /绝对路径/框架
```

### e2e（Playwright）

`test/e2e/**/*.spec.js` 由仓库级收集器处理（`tests/e2e/collect-tests.js`，只认 `test/e2e/` 下的 `*.spec.js`）：

```bash
php bin/w e2e:run
```

⚠️ 注意：`test/Browser/theme-editor-preview.spec.js` 不在 `test/e2e/` 下，**不被收集**。

## 2. 当前基线（2026-10-02 实测）

| 侧 | 命令 | 结果 |
|---|---|---|
| PHPUnit（全模块） | 见 §1 | `Tests: 1764, Assertions: 11008, Errors: 87, Failures: 162` |
| Vitest（模块 JS） | 见 §1 | **当前不可用**：3 个 `.cjs` 报 `ECONNREFUSED`（需运行中的 WLS/HTTP 服务）；e2e spec 报 `Cannot find module '@playwright/test'` |

**两点必须知道**：

1. **套件非确定性**：连续三次全量运行 `Errors` 恒为 87，`Failures` 在 162 / 170 / 178 间波动（单跑个别用例则通过）→ 存在**测试间污染**。当前只能把"量级未突增"当回归信号，不能当精确门禁。
2. **多数失败源于缺测试数据库**：典型错误 `PDOException: no such table: w_weline_theme`。相当一部分测试需要真实库/夹具，尚未解决。

## 3. 目录结构

| 目录 | PHP | JS/CJS | 内容 |
|---|---:|---:|---|
| `Unit/` | 459 | 11 | 单元与契约测试主体（含 `Unit/Service/`、`Unit/Controller/`、`Unit/View/`、`Unit/Minify/`、`Unit/LayoutEntity/` 等子域） |
| `Contract/` | 3 | 0 | 跨层契约（模板/预览身份等） |
| `Integration/` | 3 | 0 | 集成测试（部分依赖真实库） |
| `Runtime/` | 2 | 0 | 运行期探针脚本 |
| `e2e/` | 6 | 38 | Playwright 端到端（`backend/`、`frontend/`） |
| `Browser/` | 0 | 1 | 游离 spec（**不被任何收集器覆盖**，待迁移或删除） |
| 顶层 | — | — | `README.md`（本文件）、`测试结果报告.md`（**一次性报告，内容已过期**，见 `dev/audit` 报告） |

> 注：`Unit/Service/Service/**` 存在 24 个与上级同名的异常嵌套文件，抽样比对互不相同，**不可盲删**，待逐对合并。

## 4. 编写约定

- **不要只做源码字符串断言**。本模块存在大量 `assertStringContainsString(读取服务源码)` 形式的"契约测试"（如 `SlotRendererSharedChromeFooterContractTest`、`WidgetRenderErrorWordWrapContractTest`）。这类测试在删码时会**假绿**（同名字符串残留在注释/i18n 里）或**假红**，请优先断言行为。
- **测试替身同步父类签名**。2026-10-02 曾因 6 处替身 `load()` 停留在 2 参签名（父类已加 `bool $forceReload = false`）导致 PHP fatal 中断整轮。覆写 `AbstractModel` 方法时须与 `app/code/Weline/Framework/Database/AbstractModel.php` 保持一致。
- **e2e 命名**：只有 `test/e2e/**/*.spec.js` 会被 `php bin/w e2e:run` 收集。
