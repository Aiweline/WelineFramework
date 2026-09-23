# QA-09 回归热修 · 结账脚本外泄

日期：2026-09-23  
触发：[汇审复验](58ab6824-5a58-4939-9e8b-9eb3a240522c) fail

## 根因

`checkout/index.phtml` 内联 `<script>` 注释中写了 `<w:form>`。Taglib 把注释里的标签当真标签编译，注入 FormRuntime 的 `</script>`，提前关闭主脚本 → JS 源码渲成正文 + `SyntaxError`，壳卡在「正在加载结账信息...」。

## 修复

- 去掉脚本/PHP 注释中的尖括号标签名（`w:form` / `script` / `span` 均不得以 `<...>` 出现在 script 注释内）
- 文件：`app/code/Weline/Checkout/view/frontend/checkout/index.phtml`

## 验证

- 抽出含 `showCheckoutShell` 的主内联脚本：`node --check` **OK**
- 正文不再出现 `setFormVisible` / `Always drive` 作为 HTML 文本泄漏

待汇审席对 QA-09 单点复测。
