# Weline_StoreMusic（进店音乐）

## 模块定位

店面**进店音乐**：按站店渠配置进店 BGM 与歌单；页面 load 后再延迟加载/尝试播放；左下角浮层提供选曲、播控与全页波形开关。

## 知识维护约定

- 长期事实写入本模块 `doc/`。
- 不在本文复制全局规则或客户端规则。
- 无法由当前证据确认的行为必须标记待确认。

## 解耦边界

- 配置契约：SystemConfig Extends（`store_music/*`）；读取仅 `ConfigReader`。
- 音频选择：本模块后台页 `WelineMedia`（`mp3/wav/ogg/m4a/aac/flac/opus/wma` 等，约 20MB；不改 SystemConfig 核心模板）。
- 店面挂载：实现 `Weline_Theme::frontend::layouts::base::body-end`。
- Consent：仅 `Weline.Api.resource('consent')` 软探测；无 API 不拦截。
- **禁止**依赖或修改 `Weline_CustomerService`。

## 自动播放与延迟

- 首屏不创建 Audio、不请求曲目。
- `window` load 完成 → 等待 `delay_seconds` →（Consent marketing 允许时）再创建 Audio。
- `try_autoplay` 失败（如 `NotAllowedError`）→ 浮层脉冲提示点击播放。
- 波形依赖 `crossOrigin=anonymous`；CORS 失败则仅播放并禁用波形开关。

## 入口

- 后台：`weline_storemusic/backend/config`（需登录；菜单「进店音乐」）。
- 前台：启用且曲目非空时，Theme `body-end` 输出左下角进店音乐浮层。

## 本地 e2e

```bash
PLAYWRIGHT_TARGET_ORIGIN='https://p05113ef3.test.weline.com:9555' \
PLAYWRIGHT_DISABLE_PROXY=1 \
php bin/w e2e:run --module=Weline_StoreMusic --workers=1
```

新增 Controller 后若后台 404：`php bin/w setup:upgrade --route -m Weline_StoreMusic`。
