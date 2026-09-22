# components.md · newsletter-subscribe（UI 复用初稿）

> 原型 / 前端 / 主题 / UI 席输入。本波仅清单，不改生产模板。

## 1. 新建（Newsletter 拥有）

| 组件 | code | 复用来源 | 说明 |
|------|------|----------|------|
| 页脚订阅条 | `footer-newsletter` | 迁自 Theme 壳 | 横向/纵向 layout；信笺气质由 hanfu token；表单字段：email + 主题 checkbox |
| 订阅弹窗 | `newsletter-popup` | 迁自 Theme 壳 | 全站；延迟/滚动触发；cookie 14 天；透明 PNG `image` 参数保留 |

## 2. 框架 / 他模块复用（不重做）

| 组件 | 模块 | 用途 |
|------|------|------|
| `footer-container` | Theme | 宿主槽；仅增 `footer-newsletter` |
| `checkout-coupon` | Marketing | 结账展示/手改券；自动用券走 Session |
| `w:form` / 框架校验样式 | Framework / Theme | 表单壳 |
| Smtp 模板预览后台 | Smtp | 渠道绑定 UI，不造平行后台 |
| DataTable | DataTable | 后台订阅名单 |
| RequiredDefaultInjection 管线 | Theme | 空槽注入；对照 Customer footer link |

## 3. 视觉参考

| 资产 | 路径 / 说明 |
|------|-------------|
| 汉服线稿 | `app/design/Weline/hanfu/doc/spec/pages/newsletter.md` |
| Theme 旧壳（迁移源） | `Weline_Theme::theme/frontend/widgets/newsletter/*` |
| Customer injection 范例 | `Weline_Customer/.../widget.php` footer-*-link |

## 4. 交互约定（跨境常规）

- 成功：页内 toast / 弹窗内成功态；有券时展示券码（可复制）。
- 重复订阅：成功态文案「偏好已更新」；若已领券则提示券仍有效（不展示新码）。
- 校验失败：字段级错误，不关弹窗。
- 关闭弹窗：写 cookie 14 天；页脚仍可见。
- 默认勾选：优惠 + 上新。

## 5. 禁止

- 结账页再造一套「订阅券输入」替代 `checkout-coupon`。
- 紫白/通用 AI 模板风覆盖汉服 token。
- 卡片堆砌弹窗（保持单板信笺）。
