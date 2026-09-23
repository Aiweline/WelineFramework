# channel — homepage-wave2 · WO-HP-P2-06 done

日期：2026-09-22  
工单：`WO-HP-P2-06` Newsletter 弹窗延后触发  
席位：`Team:前端:`（主责）∥ 主题开发工程师（协同）  
状态：**closed**  
`notify_pm: true`

## 改动摘要

- 默认触发改为 **`deferred` 组合延后**：停留约 **15s** OR 滚动约 **40%** OR **退出意向**；另加 **`min_open_seconds=3`** 首屏硬门槛（任意触发源 3s 内不得弹）。
- 模板 + JS 对旧配置 `trigger=delay` 且 `delay_seconds<15` **自动升级**为组合延后，禁止首访首屏立即弹。
- 关闭路径不变：关闭按钮 / 蒙层点击 / Esc；`show_once` cookie 14 天频控保留。
- 资源：`newsletter-subscribe.js?v=20260922-deferred-p206`；`resource:compile welineModules` 已跑。
- 合同：`NewsletterWidgetOwnerContractTest` OK（7 tests / 94 assertions）。
- 未碰：信任条位置、locale、迷你车、Hero CTA（其他工单）。

## 触及文件

- `app/code/Weline/Newsletter/view/statics/js/newsletter-subscribe.js`
- `app/code/Weline/Newsletter/view/templates/frontend/widgets/newsletter-popup/default.phtml`
- `app/code/Weline/Newsletter/extends/module/Weline_Widget/Weline_Newsletter/widget.php`
- `app/code/Weline/Newsletter/Test/Unit/Widget/NewsletterWidgetOwnerContractTest.php`
- `app/code/Weline/Newsletter/view/statics/frontend/weline.modules.js`
- `app/code/Weline/Frontend/view/statics/base/weline.modules.js`
- `app/code/Weline/Newsletter/etc/module.php`（`1.0.10`）
- `app/code/Weline/Newsletter/i18n/{zh_Hans_CN,en_US}.csv`
- `app/code/Weline/Newsletter/doc/开发日志.md`

## 自验（nocache + 抹自动化标志）

| 项 | 结果 |
|----|------|
| SSR 属性 | `data-trigger=deferred` / `data-delay=15000` / `data-scroll=40` / `data-min-open=3000` |
| 首访 ~2.8s | 弹窗 `display:none`、无 `is-open`、无滚动锁 |
| 滚动约 45%（过 3s 门槛后） | 弹窗打开 `is-open` |
| 点关闭 | `display:none`、锁清除 |

（Cursor ide-browser 本回合不可用；改用本机 Playwright headless + Cache-Control/nocache query + `navigator.webdriver` 抹除，证据同上。）

## related_web_urls

- [首页验收面](https://p05113ef3.test.weline.com:9555/)

## 请 PM

记账 `WO-HP-P2-06` → closed；纳入 Wave-2 测试/汇审队列。
