# 模块前端改造 · 本机冒烟与截图配方（WLS）

> 用途：合同验收要求的「桌面/移动 × 亮/暗 × 预览/正式」smoke 在本机怎么跑。
> 适用：本机 WLS 实例（`php bin/w server:status` 查看公网边缘地址）。

## 1. 访问地址

```bash
php bin/w server:status
# Pure WLS public edge：https://p05113ef3.test.weline.com:9555
```

域名已在 `/etc/hosts` 指向 `127.0.0.1`；本机当前站点为 `Weline/hanfu` 设计主题下的「长安汉服」。

## 2. ⚠️ curl 会被 WLS attack guard 拦（重要）

直接 `curl` 访问会得到：

```
HTTP/2 403
x-wls-deny-reason: server.request.attack_guard
body: Forbidden
```

**原因**：WLS `server.request.attack_guard` 会拦默认 `curl/...` UA。**加浏览器 UA 与 Accept 头即可正常 200**：

```bash
H=p05113ef3.test.weline.com
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"
curl -sk --resolve "$H:9555:127.0.0.1" \
  -H "User-Agent: $UA" -H "Accept: text/html" -H "Accept-Language: zh-CN,zh;q=0.9" \
  "https://$H:9555/compare"
```

`/_wls/health` 不需要 UA（返回 `OK`）。代码生效方式按 WLS 重载（`php bin/w server:reload`）。

## 3. 截图（4 断点 × 亮暗）

```bash
node app/code/Weline/Theme/bin/capture-module-screenshots.mjs \
  --url "https://p05113ef3.test.weline.com:9555/compare" \
  --out-dir tmp/theme-standard-audit/shots/compare \
  --widths 390,768,1024,1440 --themes light,dark
```

脚本特性：
- 自带浏览器 UA（绕 attack guard）；
- CDP `Page.captureScreenshot`（Chrome `--screenshot` 在本站会挂）；
- 每个主题下会注入 `<html data-theme>`，并回读 `--color-bg-canvas` / `--color-text` / body 计算值作为**亮暗生效证据**；
- 同时收集 `console` error、未捕获异常、失败请求（`console_errors` / `page_errors` / `failed_requests`）。

## 4. 当前暗色缺口（Theme 层，非模块债）

实测（`/compare`，1440）：

```json
{"theme":"light","bg":"#f7f4ef","text":"#1c1c1c","bodyBg":"rgb(247, 244, 239)"}
{"theme":"dark","bg":"#f7f4ef","text":"#1c1c1c","bodyBg":"rgb(247, 244, 239)"}
```

即 `data-theme="dark"` 已设置成功，但令牌未变：本机 `hanfu` 设计主题**没有把暗色盘接入当前组合**（Theme `colors/_dark.css` 里有 `[data-theme="dark"]` 覆盖，但未在本站点生效）。

按合同：**缺 Theme 能力 → 停手提 Theme 任务，不在模块里硬造**。
→ 已登记 Theme 任务：`hanfu` 的 `data-theme="dark"` 组合/加载缺口；模块侧亮暗验收待该缺口修复后补跑。

## 5. 浏览器冒烟（当前结果，2026-10-21）

命令见 §3；每页 390/1440 两档，light。

| 页面 | 截图 | console error | 未捕获异常 | 失败请求 |
|---|---:|---:|---:|---|
| `/` | 2 | 0 | 0 | 仅 GA beacon（ERR_ABORTED，第三方） |
| `/compare` | 2 | 0 | 0 | 仅 GA beacon |
| `/products` | 2 | 0 | 0 | GA beacon |
| `/contact` | 2 | 0 | 0 | GA beacon |
| `/blog` | 2 | 0 | 0 | GA beacon |
| `/faq` | 2 | 0 | 0 | GA beacon |

- 全页截图：`tmp/theme-standard-audit/smoke/final-home/{390,1440}-light.png`（390 全页 4200px 高）
- 亮暗令牌回读（light）：`--color-bg-canvas=#f7f4ef`、`--color-text=#1c1c1c`，与 body 计算值一致
- **注意**：截图脚本内**不要**加 `Network.setExtraHTTPHeaders` / 仅覆盖 UA——会让静态资源请求失败（页面裸 HTML）。
  WLS 风控只认「浏览器 UA」，Chrome 启动参数 `--user-agent` 就够。

### 5.1 待跟进观察（非本轮改动引入，需功能确认）

1. `/compare` 一直是「加载对比商品…」且页面残留「请稍等片刻…」toast：
   来源 `Weline_Frontend::js/weline-api.js` 的 scope wait tip（有 API 请求未 settle）。
   需确认 compare 页取数接口是否失败/挂起。
2. 390 宽下推荐商品卡右侧价格文本贴边截断（`$…` 显示不全），疑似卡片内宽度/字体刻度问题，需按页核对。

## 6. HTTP 冒烟（当前结果）

| 路由 | 状态 | 字节 | PHP Fatal/Parse | `<lang>` 残留 | `@lang(` 残留 |
|---|---|---:|---:|---:|---:|
| `/` | 200 | 590,327 | 0 | 0 | 0 |
| `/compare` | 200 | 402,063 | 0 | 0 | 0 |
| `/cart` | 200 | 482,384 | 0 | 0 | 0 |
| `/checkout` | 200 | 604,788 | 0 | 0 | 0 |
| `/products` | 200 | 1,057,085 | 0 | 0 | 0 |
| `/about` | 200 | 413,834 | 0 | 0 | 0 |
| `/contact` | 200 | 398,765 | 0 | 0 | 0 |
| `/faq` | 200 | 420,700 | 0 | 0 | 0 |
| `/blog` | 200 | 616,886 | 0 | 0 | 0 |
| `/customer/account` | 302 | — | 0 | 0 | 0 |
