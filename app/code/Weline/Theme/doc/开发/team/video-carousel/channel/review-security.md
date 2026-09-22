# channel: review-security

## msg-1 | 2026-09-21T22:35:00+08:00 | from:安全 | to:* | thread:review-security | kind:stance
agent_id: security-video-carousel-review
body:
**stance=同意。** result=closed。

只读复审 video-carousel：`default.phtml` sanitize（禁 script/on*/srcdoc；iframe 套 `trustedEmbedHosts` + sandbox）、`VideoEmbedResolver`（拒 javascript/data/vbscript；YT/Vimeo/Bili 信任主机）、`ThemeVideoEmbedCsp`（frame-src 含 bilibili）、ArrayType `product_picker`（委托 ProductPicker；降级框 htmlspecialchars；店面 product_ids 强制 int）、轮播 JS 无原生 fetch/eval、关联商品 SSR + `w-dialog`。

未发现致命 XSS / 任意脚本注入；**未改生产码**。

残余（不挡 closed）：embed 的 src 未套 trusted hosts（SEC-VC-R1，低）；CSP 与裸 youtube.com 略不等（SEC-VC-R2）。

纪要：`meetings/复审-安全.md`

result=closed
---
