# 结账页 textContent null 报错

## 问题
`/checkout` 粉红提示条显示：`Cannot set properties of null (setting 'textContent')`。

## 根因
1. 页面 JS 调用 `Weline.Api.resource('checkout').getData()`，但 `dev` 分支缺少 `CheckoutQueryProvider`。
2. API 错误展示链路可能把 Toast/DOM 空节点异常冒泡成页面提示。
3. 结账页 JS 对 `data-subtotal` 等节点缺少 null 防护。

## 修复
1. 新增 `CheckoutQueryProvider`（`getData` / `placeOrder` / `createOrder`）
2. 加固 `index.phtml` DOM 写入
3. `weline-api.js` 解析 `Weline.Theme.Notice`，`showDefaultError` try/catch
4. Theme 暴露 `Weline.Toast` 别名，Toast DOM 写入加防护

## 验证
- `php bin/w framework:compile` → checkout provider 可见
- `server:reload` 后登录访问 `/checkout`，提示应为业务文案（空购物车/加载成功），不再是 textContent 异常
