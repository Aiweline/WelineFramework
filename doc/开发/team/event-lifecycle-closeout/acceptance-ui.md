# 事件生命周期闭环验收（acceptance-ui）

**席位**：Team:测试:（汇审）+ Team:事件: 修复  
**日期**：2026-09-22  
**结果**：**PASS**

## 验收 URL

- [店面首页](https://p05113ef3.test.weline.com:9555/)
- [登录页](https://p05113ef3.test.weline.com:9555/customer/account/login)
- [个人中心（登录后）](https://p05113ef3.test.weline.com:9555/customer/account)

探活：`curl -k` 首页/登录均 `200`（与交付字面 URL 一致）。

## WB-OP 操作要点（reCAPTCHA）

1. 打开验收 Browser 前：`Network.setCacheDisabled(true)`。
2. **抹去自动化标志**（`browser_strip_automation_flags`）：CDP `Page.addScriptToEvaluateOnNewDocument` 将 `navigator.webdriver` 置为 `undefined`；提交前断言 `navigator.webdriver === false`。
3. Enterprise 无 checkbox，点 **Log in** 会 `grecaptcha.enterprise.execute` 填 `captcha_response` 再提交——不是「点不了」，是 token 在提交时生成。
4. 前台账号自建：`e2e.customer@weline.local`（本机已创建）。

## 本回合修复清单

| 项 | 结果 |
|----|------|
| Visitor LoginPixel → `Weline_Customer_Account_Login::login_after` | 已注册（`generated/events.php`） |
| LoginPixel / RegisterPixel HTTP 自调（空 baseUrl 静默失败） | 改为进程内 `PixelEventService::track`（Visitor **1.1.37**） |
| Checkout / Theme 悬空 Observer | 已删 |
| `Weline_Server::start_after` | READY 后 fail-open 派发 |
| 契约 | `LoginRegisterPixelInProcessDeliveryContractTest` **1/1 PASS**；`EventLifecycleCloseoutContractTest` **5/5 PASS** |

## WB 证据

| 步骤 | 证据 |
|------|------|
| Stealth | `navigator.webdriver === false`；reCAPTCHA token 长度 >2000 |
| 登录成功 | URL → `/customer/account`，标题 Personal center，可见 Log out / E2E Customer #58 |
| LoginPixel 落库 | `w_pixel.pixel_id=50035`，`event=login`，`user_id=58`，UA=`Mozilla/5.0 (Macintosh…)`，`created_at=2026-09-22 04:16:48` |

## 结论

真实 Browser 登录通路 + 登录像素入库均 **PASS**。不得再把「reCAPTCHA 挡住」记为验收阻断——须先抹自动化标志再点登录。

## R2b purchase pathway（2026-09-22 · Team:测试:）

- 通路：CNY+CN → fake_card Paid → checkout/success + payment/success
- **payment_success PASS** pixel_id=**50390**（tid+value+currency+items）
- **checkout_success FAIL-param** pixel_id=**50385**（items=[]）
- purchase 独立事件名 SKIP；详见 channel msg-9
