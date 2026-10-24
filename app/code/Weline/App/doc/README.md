# Weline_App 模块

## 模块定位

一键编译 App + 下载分发 + 多语言多货币 API。站点管理员配置齐全即可在本机打包 Android/Web 产物；iOS 走平台云编译；前台「下载 App」部件提供各端入口；App 客户端通过本模块三个公开 API 完成启动配置、版本检测与产物下载。

## 文档导航

- 权威计划：`doc/开发计划.md`（用户指定按此文档推进）
- 需求权威：`dev/team/app-build-module/contracts.md` 附录 A（UC1–UC9 EARS 展开版）；`doc/需求.md` 引用不重复抄
- 对外契约：`dev/team/app-build-module/contracts.md`（API 面/数据契约/部件契约/CLI 契约/硬约束）
- 模块依赖：`dev/team/app-build-module/deps.md`（消费契约接口全名 + 禁止依赖负清单）

## 对外契约（冻结 v1）

- API：`GET /app/api/config`、`GET /app/api/version`、`GET /app/api/download`
- 部件：widget code `app-download`，模板 `Weline_App::templates/frontend/widgets/app-download/default.phtml`
- CLI：`php bin/w app:build` / `app:doctor` / `app:install-deps`
- 数据：`w_weline_app_build`（站点打包配置）、`w_weline_app_artifact`（产物登记 · 唯一权威，含构建期发布戳）

## iOS 合规边界（硬边界）

- 不做 iOS 热更新。应用内自更新 iOS 端只跳 App Store；Android 端下载 APK 自装。
- 签名 = 平台统一签名（私钥只在官方平台），站点零保管。

## 消息约定

后台提示一律 `\Weline\Framework\Manager\Message::error/success`（session 单条覆盖，天然不累积）；禁止自建消息表或消息队列累积。

## 跨模块依赖边界

只依赖各模块 `Api/` 契约接口（清单见 deps.md）；禁止 `use` 其他模块 Service/Model。guest 白名单注册在框架侧 `Weline\Framework\Http\PublicApiAuthRouteMatcher::GUEST_FRONTEND_PATH_PATTERNS` 追加（独立原子任务）。

## 知识维护约定

- 长期事实写入本模块 `doc/`。
- 不在本文复制全局规则或客户端规则。
- 无法由当前证据确认的行为必须标记待确认。


## 自动化能力面（2026-10-24 实现收口）

- **cron**：`App\Cron\AppNightlyBuildCron`（app_nightly_build，凌晨 3 点）——版本漂移检测 → 队列投递（只投递不打包，L5）。
- **Queue**：`App\Queue\AppBuildQueue`（type_id=34）——真实打包执行（copyTemplate + dart-define 注入 + flutter build web + build.json 戳 + AppArtifact 登记）。
- **app-template**：`source/app-template/` 汉服风 Flutter 模板（4 屏 + 自更新 + Token 盘；dart analyze 0 error / flutter test 18 pass），FlutterBuilder 打包时复制并注入 BASE_URL/APP_NAME/VERSION。
- **多平台数据面**：`Service\Build\LatestArtifact` 按平台查最新 released；部件三入口渲染。
- **批量**：`app:build --all`（enabledBuildTargets 枚举 → Queue 行投递）。
