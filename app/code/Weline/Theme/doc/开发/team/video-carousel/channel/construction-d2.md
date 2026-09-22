# channel: construction-d2

## msg-1 | 2026-09-21T22:45:00+08:00 | from:后端·Theme嵌入 | to:* | thread:construction-d2 | kind:result
agent_id: theme-embed-d2
body:
result=closed。D2 已交付：`VideoEmbedResolver` + `ThemeVideoEmbedCsp` + `video-player` sanitize 三处同步扩 bilibili。

### 交付摘要
- `resolveBilibiliId`：BV 页/播放器 `bvid=` / iframe src；`av` 路径与 `aid=` → 归一 `BVxxx` 或 `av{n}`
- `bilibiliEmbedUrl`：`player.bilibili.com/player.html?bvid=` / `aid=`
- `trustedEmbedHosts()` 累加 `player.bilibili.com`（iframe 受信；www.bilibili.com 仅作页面解析源，不进 iframe 白名单）
- CSP：`frame-src`/`connect-src` → `https://player.bilibili.com`；`img-src` → `i0/i1/i2.hdslb.com`
- `video-player` sanitize 改复用 `VideoEmbedResolver::trustedEmbedHosts()`（未新建 video-carousel 模板）

### 改动文件
- `app/code/Weline/Theme/Helper/VideoEmbedResolver.php`
- `app/code/Weline/Theme/extends/module/Weline_Framework/Security/Csp/ThemeVideoEmbedCsp.php`
- `app/code/Weline/Theme/view/theme/frontend/widgets/video/video-player/default.phtml`
- `app/code/Weline/Theme/test/Unit/Helper/VideoEmbedResolverTest.php`
- `app/code/Weline/Theme/test/Unit/Security/ThemeVideoEmbedCspContractTest.php`

### 测试
```text
php vendor/bin/phpunit --no-configuration --bootstrap app/bootstrap.php \
  app/code/Weline/Theme/test/Unit/Helper/VideoEmbedResolverTest.php \
  app/code/Weline/Theme/test/Unit/Security/ThemeVideoEmbedCspContractTest.php
→ OK (13 tests, 33 assertions)
```

下游 D4（部件模板）可消费同一 Resolver/CSP；本席未新建 `video-carousel` 模板。
---
